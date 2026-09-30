<?php

namespace Tests\Feature;

use App\Mail\AuctionReminderMail;
use App\Mail\AuctionResultBuyerMail;
use App\Mail\AuctionResultSupplierMail;
use App\Mail\QuotesOpenedMail;
use App\Mail\RfqReminderMail;
use App\Models\BuyerSupplier;
use App\Models\Organization;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqInvite;
use App\Models\User;
use App\Services\Auction\AuctionService;
use App\Services\Automations;
use App\Services\RfqService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class AutomationsTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    private Organization $buyer;
    private User $buyerUser;
    /** @var array<string, array{0: Organization, 1: User}> */
    private array $s = [];
    private Rfq $rfq;
    private Carbon $t0;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->t0 = Carbon::parse('2026-10-05 04:30:00', 'UTC');
        $this->travelTo($this->t0);

        [$this->buyer, $this->buyerUser] = $this->buyer('Acme Buyers');
        foreach (['A' => 'Alpha Packaging', 'B' => 'Beta Corrugators', 'C' => 'Gamma Boxes'] as $k => $name) {
            [$org, $user] = $this->supplier($name);
            BuyerSupplier::create(['buyer_org_id' => $this->buyer->id, 'company_name' => $name, 'status' => 'active',
                'contact_email' => $user->email, 'supplier_org_id' => $org->id]);
            $this->s[$k] = [$org, $user];
        }

        $svc = app(RfqService::class);
        $rfq = $svc->saveDraft($this->buyer, $this->buyerUser, [
            'title' => 'Corrugated boxes',
            'quote_deadline' => $this->t0->copy()->addHours(3)->setTimezone('Asia/Kolkata')->format('Y-m-d\TH:i'),
            'items' => [['name' => 'Box 5-ply', 'qty' => 1000, 'unit' => 'pcs', 'last_purchase_price' => 100]],
        ]);
        $svc->invite($rfq, $this->buyerUser, BuyerSupplier::where('buyer_org_id', $this->buyer->id)->pluck('id')->all());
        $svc->publish($rfq->fresh(), $this->buyerUser);
        $this->rfq = $rfq->fresh();
        app(CurrentOrganization::class)->set(null);
    }

    private function quote(string $k, float $unitPrice): void
    {
        $item = $this->rfq->items()->first();
        $q = Quote::create([
            'rfq_id' => $this->rfq->id, 'supplier_org_id' => $this->s[$k][0]->id, 'submitted_by' => $this->s[$k][1]->id,
            'total' => $unitPrice * (float) $item->qty, 'valid_till' => $this->t0->copy()->addDays(30)->toDateString(), 'submitted_at' => now(),
        ]);
        QuoteItem::create(['quote_id' => $q->id, 'rfq_item_id' => $item->id, 'unit_price' => $unitPrice, 'gst_rate' => 18, 'freight' => 0]);
    }

    private function automate(): array
    {
        return app(Automations::class)->run();
    }

    public function test_one_reminder_to_suppliers_who_have_not_quoted(): void
    {
        $this->quote('A', 90);

        // 3-hour window → reminder when 45 minutes are left.
        $this->travelTo($this->rfq->quote_deadline->copy()->subMinutes(60));
        $this->automate();
        Mail::assertNotQueued(RfqReminderMail::class);

        $this->travelTo($this->rfq->quote_deadline->copy()->subMinutes(44));
        $this->assertSame(2, $this->automate()['reminders']);
        Mail::assertQueued(RfqReminderMail::class, fn ($m) => $m->hasTo($this->s['B'][1]->email));
        Mail::assertQueued(RfqReminderMail::class, fn ($m) => $m->hasTo($this->s['C'][1]->email));
        Mail::assertNotQueued(RfqReminderMail::class, fn ($m) => $m->hasTo($this->s['A'][1]->email));

        // Never twice.
        $this->travel(5)->minutes();
        $this->assertSame(0, $this->automate()['reminders']);
        Mail::assertQueued(RfqReminderMail::class, 2);
    }

    public function test_declined_suppliers_are_not_reminded(): void
    {
        RfqInvite::where('supplier_org_id', $this->s['B'][0]->id)->update(['status' => 'declined']);
        $this->travelTo($this->rfq->quote_deadline->copy()->subMinutes(20));
        $this->automate();
        Mail::assertNotQueued(RfqReminderMail::class, fn ($m) => $m->hasTo($this->s['B'][1]->email));
    }

    public function test_buyer_hears_when_quotes_open_exactly_once(): void
    {
        $this->quote('A', 90);
        $this->quote('B', 85);

        $this->automate();
        Mail::assertNotQueued(QuotesOpenedMail::class);

        $this->travelTo($this->rfq->quote_deadline->copy()->addSeconds(30));
        $this->assertSame(1, $this->automate()['quotes_opened']);
        Mail::assertQueued(QuotesOpenedMail::class, function (QuotesOpenedMail $m) {
            return $m->hasTo($this->buyerUser->email)
                && $m->summary['quoted'] === 2
                && $m->summary['l1']['supplier'] === 'Beta Corrugators'
                && $m->summary['can_auction'] === true;
        });

        $this->assertSame(0, $this->automate()['quotes_opened']);

        // The email renders (Markdown-escaped names, links).
        $html = Mail::queued(QuotesOpenedMail::class)->first()->render();
        $this->assertStringContainsString('2 of 3 suppliers quoted', $html);
        $this->assertStringContainsString(route('buyer.rfqs.show', $this->rfq->id), $html);
    }

    public function test_auction_reminder_and_results(): void
    {
        $this->quote('A', 90);
        $this->quote('B', 85);
        $this->travelTo($this->rfq->quote_deadline->copy()->addMinute());
        $this->automate(); // quotes-opened mail

        $auction = app(AuctionService::class)->schedule($this->rfq->fresh(), $this->buyerUser, [
            'starts_at' => now()->addMinutes(40)->setTimezone('Asia/Kolkata')->format('Y-m-d\TH:i'),
            'duration_min' => 10, 'min_decrement_type' => 'percent', 'min_decrement_value' => '0.5',
            'max_decrement_pct' => '10', 'extend_window_sec' => 0, 'extend_by_sec' => 60, 'max_extensions' => 0,
            'visibility' => 'rank_only',
        ]);

        $this->travelTo($auction->starts_at->copy()->subMinutes(10));
        $this->assertSame(2, $this->automate()['auction_reminders']);
        Mail::assertQueued(AuctionReminderMail::class, 2);
        $this->assertSame(0, $this->automate()['auction_reminders']);

        // Live: A beats B.
        $this->travelTo($auction->starts_at->copy()->addMinute());
        $this->actingAs($this->s['A'][1])->postJson(route('supplier.auctions.bid', $auction->id), ['amount' => '80000', 'idempotency_key' => (string) Str::uuid()])->assertOk();

        $this->travelTo($auction->ends_at->copy()->addSecond());
        app(AuctionService::class)->tick();
        app(CurrentOrganization::class)->set(null);

        $r = $this->automate();
        $this->assertSame(3, $r['auction_results']); // buyer + 2 suppliers
        Mail::assertQueued(AuctionResultBuyerMail::class, fn ($m) => $m->hasTo($this->buyerUser->email));
        Mail::assertQueued(AuctionResultSupplierMail::class, fn ($m) => $m->hasTo($this->s['A'][1]->email) && $m->rank === 1);
        Mail::assertQueued(AuctionResultSupplierMail::class, fn ($m) => $m->hasTo($this->s['B'][1]->email) && $m->rank === 2);

        // Supplier result never names the winner.
        $html = Mail::queued(AuctionResultSupplierMail::class, fn ($m) => $m->rank === 2)->first()->render();
        $this->assertStringNotContainsString('Alpha', $html);

        $this->assertSame(0, $this->automate()['auction_results']);
    }

    public function test_old_rfqs_are_not_mailed_after_an_upgrade(): void
    {
        $this->travelTo($this->rfq->quote_deadline->copy()->addDays(3));
        $this->assertSame(0, $this->automate()['quotes_opened']);
    }

    public function test_automations_command_runs(): void
    {
        $this->artisan('getl1:automations')->assertSuccessful();
    }

    // ---------------------------------------------------------------- self-updating pages

    public function test_buyer_page_fingerprint_changes_when_a_quote_arrives_and_at_the_deadline(): void
    {
        $url = route('buyer.rfqs.show.live', $this->rfq->id);
        $v1 = $this->actingAs($this->buyerUser)->getJson($url)->assertOk()
            ->assertJsonPath('refresh_at', $this->rfq->quote_deadline->getTimestampMs())
            ->json('v');

        $this->quote('A', 90);
        $v2 = $this->actingAs($this->buyerUser)->getJson($url)->json('v');
        $this->assertNotSame($v1, $v2);

        $this->travelTo($this->rfq->quote_deadline->copy()->addSecond());
        $res = $this->actingAs($this->buyerUser)->getJson($url);
        $this->assertNotSame($v2, $res->json('v'));
        $this->assertSame(['v', 'refresh_at', 'server_time'], array_keys($res->json()), 'Only a fingerprint, never data');

        $this->actingAs($this->buyerUser)->getJson(route('buyer.rfqs.live'))->assertOk()->assertJsonStructure(['v', 'refresh_at', 'server_time']);
        $this->actingAs($this->buyerUser)->get(route('buyer.rfqs.show', $this->rfq->id))->assertOk()->assertSee('data-live-page', false);
    }

    public function test_live_endpoints_respect_access(): void
    {
        [, $otherBuyer] = $this->buyer('Rival Industries');
        $this->actingAs($otherBuyer)->getJson(route('buyer.rfqs.show.live', $this->rfq->id))->assertNotFound();

        $inviteA = RfqInvite::where('supplier_org_id', $this->s['A'][0]->id)->firstOrFail();
        $this->actingAs($this->s['A'][1])->getJson(route('supplier.rfqs.show.live', $inviteA->id))->assertOk();
        $this->actingAs($this->s['B'][1])->getJson(route('supplier.rfqs.show.live', $inviteA->id))->assertNotFound();
        $this->actingAs($this->s['A'][1])->getJson(route('supplier.rfqs.live'))->assertOk();
        $this->actingAs($this->s['A'][1])->get(route('supplier.rfqs.show', $inviteA->id))->assertOk()->assertSee('data-countdown-to', false);
    }
}
