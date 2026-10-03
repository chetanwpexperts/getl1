<?php

namespace Tests\Feature;

use App\Mail\CounterOfferMail;
use App\Mail\CounterOfferResponseMail;
use App\Models\AuditLog;
use App\Models\Award;
use App\Models\BuyerSupplier;
use App\Models\CounterOffer;
use App\Models\Organization;
use App\Models\Rfq;
use App\Models\RfqInvite;
use App\Models\User;
use App\Services\RfqService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

/** Counter-offers before award: offer, accept/decline/expire/withdraw, and the effect on award. */
class CounterOfferTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    private Organization $buyer;
    private User $buyerUser;
    /** @var array<string, array{0: Organization, 1: User}> */
    private array $s = [];
    private Rfq $rfq;
    private string $diskRoot;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->diskRoot = sys_get_temp_dir().'/getl1-offer-'.bin2hex(random_bytes(6));
        Storage::set('local', Storage::createLocalDriver(['root' => $this->diskRoot, 'throw' => true]));
        $this->travelTo(Carbon::parse('2026-10-05 04:30:00', 'UTC'));

        [$this->buyer, $this->buyerUser] = $this->buyer('Acme Buyers');
        foreach (['A' => 'Alpha Packaging', 'B' => 'Beta Corrugators'] as $k => $name) {
            [$org, $user] = $this->supplier($name);
            BuyerSupplier::create(['buyer_org_id' => $this->buyer->id, 'company_name' => $name, 'status' => 'active',
                'contact_email' => $user->email, 'supplier_org_id' => $org->id]);
            $this->s[$k] = [$org, $user];
        }
        $svc = app(RfqService::class);
        $rfq = $svc->saveDraft($this->buyer, $this->buyerUser, [
            'title' => 'Corrugated boxes',
            'quote_deadline' => now()->addHours(3)->setTimezone('Asia/Kolkata')->format('Y-m-d\TH:i'),
            'items' => [['name' => 'Box 5-ply', 'qty' => 1000, 'unit' => 'pcs']],
        ]);
        $svc->invite($rfq, $this->buyerUser, BuyerSupplier::where('buyer_org_id', $this->buyer->id)->pluck('id')->all());
        $svc->publish($rfq->fresh(), $this->buyerUser);
        app(CurrentOrganization::class)->set(null);
        $this->rfq = $rfq->fresh();

        // A quotes 90 (L1, 90,000), B quotes 92 (92,000).
        foreach (['A' => 90, 'B' => 92] as $k => $price) {
            $invite = $this->invite($k);
            $this->actingAs($this->s[$k][1])->post(route('supplier.rfqs.accept', $invite->id), ['agree' => 1]);
            $items = [$this->rfq->items()->first()->id => ['unit_price' => $price, 'gst_rate' => '18', 'freight' => 0]];
            $this->actingAs($this->s[$k][1])->post(route('supplier.rfqs.quote', $invite->id), ['items' => $items, 'valid_till' => now()->addDays(10)->toDateString()])->assertSessionHasNoErrors();
            app(CurrentOrganization::class)->set(null);
        }
        $this->travel(4)->hours();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->diskRoot);
        parent::tearDown();
    }

    private function invite(string $k): RfqInvite
    {
        return RfqInvite::where('rfq_id', $this->rfq->id)->where('supplier_org_id', $this->s[$k][0]->id)->firstOrFail();
    }

    private function offer(string $k, string $amount, int $hours = 24): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->buyerUser)->post(route('buyer.rfqs.offers.store', $this->rfq->id), [
            'supplier_org_id' => $this->s[$k][0]->id, 'offered_amount' => $amount, 'hours' => $hours, 'message' => 'We can confirm today.',
        ]);
    }

    private function respond(string $k, CounterOffer $o, string $decision): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->s[$k][1])->post(route('supplier.rfqs.offers.respond', [$this->invite($k)->id, $o->id]), ['decision' => $decision, 'note' => 'OK for us']);
    }

    public function test_accepted_offer_becomes_the_award_price(): void
    {
        $this->actingAs($this->buyerUser)->get(route('buyer.rfqs.show', $this->rfq->id))->assertOk()->assertSee('Negotiate before awarding');

        $this->offer('A', '88,000')->assertRedirect()->assertSessionHasNoErrors();
        $o = CounterOffer::firstOrFail();
        $this->assertEquals(90000, (float) $o->current_amount);
        $this->assertEquals(88000, (float) $o->offered_amount);
        Mail::assertQueued(CounterOfferMail::class, fn ($m) => $m->hasTo($this->s['A'][1]->email));
        $this->assertStringContainsString('88,000.00', (new CounterOfferMail($o, $this->invite('A')))->render());

        // Only one open offer at a time.
        $this->offer('B', '89000')->assertSessionHasErrors('offered_amount');

        // The supplier sees it and accepts.
        $this->actingAs($this->s['A'][1])->get(route('supplier.rfqs.show', $this->invite('A')->id))->assertOk()
            ->assertSee('Counter-offer from Acme Buyers')->assertSee('88,000.00');
        $this->actingAs($this->s['B'][1])->get(route('supplier.rfqs.show', $this->invite('B')->id))->assertOk()->assertDontSee('88,000.00');
        $this->respond('B', $o, 'accept')->assertNotFound(); // not theirs
        $this->respond('A', $o, 'accept')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(CounterOffer::ACCEPTED, $o->fresh()->status);
        Mail::assertQueued(CounterOfferResponseMail::class, fn ($m) => $m->hasTo($this->buyerUser->email));
        $this->assertTrue(AuditLog::where('action', 'counter_offer_accepted')->exists());

        // Award list uses the new price, and so does the PO.
        $this->actingAs($this->buyerUser)->get(route('buyer.rfqs.show', $this->rfq->id))->assertSee('Negotiated (was');
        $this->actingAs($this->buyerUser)->post(route('buyer.awards.store', $this->rfq->id), ['supplier_org_id' => $this->s['A'][0]->id])->assertSessionHasNoErrors();
        $award = Award::withoutGlobalScopes()->where('rfq_id', $this->rfq->id)->firstOrFail();
        $this->assertEquals(88000, (float) $award->total);
        $this->assertEquals(88, $award->lines['items'][0]['unit_price']);
        $this->assertEquals(88000 * 1.18, (float) $award->grand_total);
    }

    public function test_negotiation_can_change_who_is_l1(): void
    {
        $this->offer('B', '89500');
        $this->respond('B', CounterOffer::firstOrFail(), 'accept');
        $c = app(\App\Services\AwardService::class)->candidates($this->rfq->fresh());
        $this->assertSame($this->s['B'][0]->id, $c->first()['supplier']->id, 'B at 89,500 now beats A at 90,000');
        $this->assertSame(1, $c->first()['rank']);
        $this->assertSame(2, $c->firstWhere('supplier.id', $this->s['A'][0]->id)['rank']);
    }

    public function test_declined_or_expired_offers_change_nothing(): void
    {
        $this->offer('A', '88000');
        $this->respond('A', CounterOffer::firstOrFail(), 'decline');
        $this->assertSame(CounterOffer::DECLINED, CounterOffer::first()->status);
        $this->assertEquals(90000, app(\App\Services\AwardService::class)->candidates($this->rfq->fresh())->first()['basic']);

        // Expiry is by the clock.
        $this->offer('A', '87000', 2);
        $o = CounterOffer::latest('id')->first();
        $this->travel(3)->hours();
        $this->assertSame('expired', $o->fresh()->displayStatus());
        $this->respond('A', $o, 'accept')->assertSessionHasErrors('offer');
        // A new offer is allowed after expiry.
        $this->offer('A', '87500')->assertSessionHasNoErrors();
    }

    public function test_rules(): void
    {
        $this->offer('A', '90000')->assertSessionHasErrors('offered_amount');     // not lower
        $this->offer('A', '40000')->assertSessionHasErrors('offered_amount');     // typo guard
        $this->offer('A', '88000', 5)->assertSessionHasErrors('hours');
        [, $other] = $this->buyer('Other Buyer');
        $this->actingAs($other)->post(route('buyer.rfqs.offers.store', $this->rfq->id), ['supplier_org_id' => $this->s['A'][0]->id, 'offered_amount' => '88000', 'hours' => 24])->assertNotFound();

        // Withdraw, and awarding withdraws any open offer.
        $this->offer('A', '88000');
        $o = CounterOffer::firstOrFail();
        $this->actingAs($this->buyerUser)->post(route('buyer.rfqs.offers.withdraw', [$this->rfq->id, $o->id]))->assertRedirect();
        $this->assertSame(CounterOffer::WITHDRAWN, $o->fresh()->status);

        $this->offer('A', '89000');
        $open = CounterOffer::latest('id')->first();
        $this->actingAs($this->buyerUser)->post(route('buyer.awards.store', $this->rfq->id), ['supplier_org_id' => $this->s['A'][0]->id])->assertSessionHasNoErrors();
        $this->assertSame(CounterOffer::WITHDRAWN, $open->fresh()->status);
        $this->respond('A', $open, 'accept')->assertSessionHasErrors('offer');
    }

    public function test_offer_on_sealed_prices_never_overrides_a_lower_auction_price(): void
    {
        // B accepts 88,000 on the sealed prices…
        $this->offer('B', '88000');
        $this->respond('B', CounterOffer::firstOrFail(), 'accept');
        // …then the buyer still runs an auction, and an open offer to A is withdrawn by it.
        $this->offer('A', '89000');
        $openA = CounterOffer::latest('id')->first();
        $auction = app(\App\Services\Auction\AuctionService::class)->schedule($this->rfq->fresh(), $this->buyerUser, [
            'starts_at' => now()->addMinutes(10)->setTimezone('Asia/Kolkata')->format('Y-m-d\TH:i'), 'duration_min' => 30,
            'min_decrement_type' => 'percent', 'min_decrement_value' => '0.5', 'max_decrement_pct' => '20',
            'extend_window_sec' => 0, 'extend_by_sec' => 60, 'max_extensions' => 0, 'visibility' => 'rank_only',
        ]);
        app(CurrentOrganization::class)->set(null);
        $this->assertSame(CounterOffer::WITHDRAWN, $openA->fresh()->status);

        // In the auction B goes down to 80,000.
        $this->travelTo($auction->starts_at->copy()->addSecond());
        $this->actingAs($this->s['B'][1])->postJson(route('supplier.auctions.bid', $auction->id), ['amount' => '80000', 'idempotency_key' => (string) \Illuminate\Support\Str::uuid()])->assertOk();
        $this->travelTo($auction->starts_at->copy()->addMinutes(31));
        app(\App\Services\Auction\AuctionService::class)->tick();

        $c = app(\App\Services\AwardService::class)->candidates($this->rfq->fresh());
        $b = $c->firstWhere('supplier.id', $this->s['B'][0]->id);
        $this->assertEquals(80000, $b['basic'], 'The auction price stands; the older 88,000 offer is ignored');
        $this->assertNull($b['negotiated']);
        $this->assertSame(1, $b['rank']);
    }
}
