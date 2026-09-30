<?php

namespace Tests\Feature;

use App\Enums\AuctionStatus;
use App\Enums\RfqStatus;
use App\Mail\AuctionScheduledMail;
use App\Models\Auction;
use App\Models\AuditLog;
use App\Models\Bid;
use App\Models\BuyerSupplier;
use App\Models\Organization;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\User;
use App\Services\Auction\AuctionService;
use App\Services\Auction\BidService;
use App\Services\RfqService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class AuctionTest extends TestCase
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
        $this->t0 = Carbon::parse('2026-10-05 04:30:00', 'UTC'); // 10:00 IST
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
            'items' => [['name' => 'Box 5-ply', 'qty' => 10000, 'unit' => 'pcs']],
        ]);
        $svc->invite($rfq, $this->buyerUser, BuyerSupplier::where('buyer_org_id', $this->buyer->id)->pluck('id')->all());
        $svc->publish($rfq->fresh(), $this->buyerUser);
        $this->rfq = $rfq->fresh();
    }

    // ---------------------------------------------------------------- helpers

    private function unscope(): void
    {
        app(CurrentOrganization::class)->set(null);
    }

    /** Sealed quotes: A 1,00,000 (first), B 1,05,000, C 1,00,000 (later than A). */
    private function quotes(array $which = ['A' => 100000, 'B' => 105000, 'C' => 100000]): void
    {
        $i = 0;
        foreach ($which as $k => $total) {
            Quote::create([
                'rfq_id' => $this->rfq->id, 'supplier_org_id' => $this->s[$k][0]->id, 'submitted_by' => $this->s[$k][1]->id,
                'total' => $total, 'valid_till' => $this->t0->copy()->addDays(30)->toDateString(),
                'submitted_at' => $this->t0->copy()->addMinutes(10 * ++$i),
            ]);
        }
    }

    private function pastDeadline(): void
    {
        $this->travelTo($this->t0->copy()->addHours(4));
    }

    private function rules(array $overrides = []): array
    {
        return array_merge([
            'starts_at' => now()->addMinutes(10)->setTimezone('Asia/Kolkata')->format('Y-m-d\TH:i'),
            'duration_min' => 30,
            'min_decrement_type' => 'percent',
            'min_decrement_value' => '0.5',
            'max_decrement_pct' => '10',
            'extend_window_sec' => 120,
            'extend_by_sec' => 120,
            'max_extensions' => 1,
            'visibility' => 'rank_only',
        ], $overrides);
    }

    private function scheduled(array $overrides = []): Auction
    {
        $this->quotes();
        $this->pastDeadline();

        return app(AuctionService::class)->schedule($this->rfq->fresh(), $this->buyerUser, $this->rules($overrides));
    }

    private function live(array $overrides = []): Auction
    {
        $a = $this->scheduled($overrides);
        $this->travelTo($a->starts_at->copy()->addSecond());

        return $a;
    }

    private function bid(string $k, Auction $a, string $amount, ?string $key = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->s[$k][1])->postJson(route('supplier.auctions.bid', $a->id), [
            'amount' => $amount, 'idempotency_key' => $key ?? (string) Str::uuid(),
        ]);
    }

    private function fresh(Auction $a): Auction
    {
        return Auction::withoutGlobalScopes()->findOrFail($a->id);
    }

    // ---------------------------------------------------------------- scheduling

    public function test_buyer_schedules_after_deadline_and_sealed_quotes_become_opening_bids(): void
    {
        $this->quotes();

        // Before the deadline: quotes are sealed, no auction.
        $this->actingAs($this->buyerUser)->post(route('buyer.auctions.store', $this->rfq->id), $this->rules())
            ->assertSessionHasErrors('starts_at');

        $this->pastDeadline();
        $this->actingAs($this->buyerUser)->get(route('buyer.rfqs.show', $this->rfq->id))->assertOk()->assertSee('Schedule live auction');
        $this->actingAs($this->buyerUser)->get(route('buyer.auctions.create', $this->rfq->id))->assertOk()->assertSee('Alpha Packaging');

        $res = $this->actingAs($this->buyerUser)->post(route('buyer.auctions.store', $this->rfq->id), $this->rules());
        $this->unscope();
        $a = Auction::withoutGlobalScopes()->firstOrFail();
        $res->assertRedirect(route('buyer.auctions.show', $a->id));

        $this->assertSame(RfqStatus::Auction, $this->rfq->fresh()->status);
        $this->assertSame(AuctionStatus::Scheduled, $a->status);
        $this->assertEquals(100000, (float) $a->start_price);
        $this->assertSame($this->s['A'][0]->id, $a->current_l1_supplier_org_id, 'Tie on sealed price: the earlier quote leads');
        $this->assertSame(3, Bid::where('auction_id', $a->id)->where('kind', Bid::KIND_SEALED)->count());
        $this->assertTrue(AuditLog::where('action', 'auction_scheduled')->exists());
        Mail::assertQueued(AuctionScheduledMail::class, 3);

        // Second schedule for the same RFQ is refused.
        $this->actingAs($this->buyerUser)->post(route('buyer.auctions.store', $this->rfq->id), $this->rules())
            ->assertSessionHasErrors('starts_at');
        $this->unscope();
        $this->assertSame(1, Auction::withoutGlobalScopes()->count());

        // Pages link to the console.
        $this->actingAs($this->buyerUser)->get(route('buyer.rfqs.show', $this->rfq->id))->assertSee('Open auction console');
        $this->actingAs($this->buyerUser)->get(route('buyer.auctions.show', $a->id))->assertOk()->assertSee('Standings');
        $this->actingAs($this->s['A'][1])->get(route('supplier.rfqs.index'))->assertOk()->assertSee(route('supplier.auctions.show', $a->id));
        $this->actingAs($this->s['A'][1])->get(route('supplier.auctions.show', $a->id))->assertOk()->assertSee('Place a bid');
    }

    public function test_schedule_needs_two_quotes_and_a_sensible_start(): void
    {
        $this->quotes(['A' => 100000]);
        $this->pastDeadline();
        $svc = app(AuctionService::class);

        try {
            $svc->schedule($this->rfq->fresh(), $this->buyerUser, $this->rules());
            $this->fail('Scheduled with one participant');
        } catch (ValidationException) {
        }

        $this->quotes(['B' => 101000]);
        foreach ([now()->addMinutes(2), now()->addDays(15)] as $bad) {
            try {
                $svc->schedule($this->rfq->fresh(), $this->buyerUser, $this->rules(['starts_at' => $bad->setTimezone('Asia/Kolkata')->format('Y-m-d\TH:i')]));
                $this->fail('Accepted start '.$bad);
            } catch (ValidationException) {
            }
        }
        try {
            $svc->schedule($this->rfq->fresh(), $this->buyerUser, $this->rules(['min_decrement_value' => '15']));
            $this->fail('Accepted 15% decrement');
        } catch (ValidationException) {
        }

        $this->assertSame(0, Auction::withoutGlobalScopes()->count());
    }

    public function test_approver_cannot_schedule(): void
    {
        $approver = $this->memberOf($this->buyer, \App\Enums\OrgRole::Approver);
        $this->quotes();
        $this->pastDeadline();
        $this->actingAs($approver)->post(route('buyer.auctions.store', $this->rfq->id), $this->rules())->assertForbidden();
    }

    public function test_cancel_only_before_start_and_rfq_returns_to_published(): void
    {
        $a = $this->scheduled();
        $this->actingAs($this->buyerUser)->post(route('buyer.auctions.cancel', $a->id), ['reason' => 'Specs changed'])
            ->assertRedirect(route('buyer.rfqs.show', $this->rfq->id));
        $this->assertSame(AuctionStatus::Cancelled, $this->fresh($a)->status);
        $this->assertSame(RfqStatus::Published, $this->rfq->fresh()->status);

        // Can schedule again; the new one can't be cancelled once live.
        $b = app(AuctionService::class)->schedule($this->rfq->fresh(), $this->buyerUser, $this->rules());
        $this->travelTo($b->starts_at->copy()->addSecond());
        $this->actingAs($this->buyerUser)->post(route('buyer.auctions.cancel', $b->id), ['reason' => 'Too late now'])
            ->assertSessionHasErrors('reason');
        // Refused and rolled back: not cancelled, and live by the server clock.
        $this->assertNotSame(AuctionStatus::Cancelled, $this->fresh($b)->status);
        $this->assertSame(AuctionStatus::Live, \App\Services\Auction\Standings::effectiveStatus($this->fresh($b)));
        $this->assertSame(RfqStatus::Auction, $this->rfq->fresh()->status);
    }

    // ---------------------------------------------------------------- bidding rules

    public function test_bids_only_while_live(): void
    {
        $a = $this->scheduled();
        $this->bid('B', $a, '104000')->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->travelTo($a->ends_at->copy()->addSecond());
        $this->bid('B', $a, '104000')->assertUnprocessable();
        $this->assertSame(0, Bid::where('auction_id', $a->id)->where('kind', Bid::KIND_LIVE)->count());
    }

    public function test_minimum_decrement_floor_and_gap(): void
    {
        $a = $this->live();

        // B's own price is 1,05,000; 0.5% = 525, so at most 1,04,475.
        $this->bid('B', $a, '104500')->assertUnprocessable();
        // Floor: 10% below current L1 (1,00,000) = 90,000.
        $this->bid('B', $a, '89999')->assertUnprocessable();
        $this->bid('B', $a, 'abc')->assertUnprocessable();
        $this->bid('B', $a, '104475')->assertOk()->assertJsonPath('ok', true);

        // Too fast after own previous bid.
        $this->bid('B', $a, '103000')->assertUnprocessable();
        $this->travel(2)->seconds();
        $this->bid('B', $a, '1,03,000.50')->assertOk()->assertJsonPath('state.my_amount', 103000.5);

        $this->assertSame(2, $this->fresh($a)->bid_count);
    }

    public function test_retry_with_same_key_is_recorded_once(): void
    {
        $a = $this->live();
        $key = (string) Str::uuid();

        $this->bid('B', $a, '99000', $key)->assertOk()->assertJsonPath('duplicate', false);
        $this->bid('B', $a, '99000', $key)->assertOk()->assertJsonPath('duplicate', true);
        $this->bid('B', $a, '99000', 'short')->assertUnprocessable();

        $this->assertSame(1, Bid::where('auction_id', $a->id)->where('kind', Bid::KIND_LIVE)->count());
        $this->assertSame(1, $this->fresh($a)->bid_count);
    }

    public function test_equal_price_goes_to_the_earlier_bid(): void
    {
        $a = $this->live();

        $this->bid('B', $a, '99000')->assertOk()->assertJsonPath('state.my_rank', 1);
        $this->travel(1)->seconds();
        $this->bid('C', $a, '99000')->assertOk()->assertJsonPath('state.my_rank', 2);

        $a = $this->fresh($a);
        $this->assertSame($this->s['B'][0]->id, $a->current_l1_supplier_org_id);
        $this->assertEquals(99000, (float) $a->current_l1);
        $this->assertSame(2, Bid::where('auction_id', $a->id)->where('supplier_org_id', $this->s['C'][0]->id)->where('kind', Bid::KIND_LIVE)->value('rank_at_submit'));

        $this->actingAs($this->s['A'][1])->getJson(route('supplier.auctions.state', $a->id))->assertJsonPath('my_rank', 3);
    }

    public function test_late_bid_extends_the_auction_up_to_the_limit(): void
    {
        $a = $this->live();
        $originalEnd = $a->ends_at->copy();

        $this->travelTo($originalEnd->copy()->subSeconds(60));
        $this->bid('A', $a, '99000')->assertOk()->assertJsonPath('extended', true);
        $a = $this->fresh($a);
        $this->assertTrue($a->ends_at->eq($originalEnd->copy()->addSeconds(120)));
        $this->assertSame(1, $a->extensions_used);

        // Limit (1) reached: no further extension.
        $this->travelTo($a->ends_at->copy()->subSeconds(30));
        $this->bid('A', $a, '98000')->assertOk()->assertJsonPath('extended', false);
        $this->assertTrue($this->fresh($a)->ends_at->eq($originalEnd->copy()->addSeconds(120)));

        // An early bid never extends.
        $this->assertSame(1, $this->fresh($a)->extensions_used);
    }

    public function test_tick_opens_and_closes_and_rfq_moves_to_evaluation(): void
    {
        $a = $this->scheduled();
        $svc = app(AuctionService::class);

        $this->assertSame(['opened' => 0, 'closed' => 0], $svc->tick());
        $this->travelTo($a->starts_at->copy()->addSecond());
        $this->assertSame(['opened' => 1, 'closed' => 0], $svc->tick());
        $this->assertSame(AuctionStatus::Live, $this->fresh($a)->status);

        $this->bid('B', $a, '98000')->assertOk();

        $this->travelTo($this->fresh($a)->ends_at->copy()->addSecond());
        $this->assertSame(['opened' => 0, 'closed' => 1], $svc->tick());
        $this->assertSame(AuctionStatus::Closed, $this->fresh($a)->status);
        $this->assertSame(RfqStatus::Evaluating, $this->rfq->fresh()->status);
        $log = AuditLog::where('action', 'auction_closed')->firstOrFail();
        $this->assertEquals(98000, $log->after['final_l1']);

        $this->artisan('auctions:tick')->assertSuccessful();
    }

    // ---------------------------------------------------------------- privacy and access

    public function test_supplier_sees_only_own_position(): void
    {
        $a = $this->live();
        $this->bid('B', $a, '99000')->assertOk();

        $json = $this->actingAs($this->s['A'][1])->getJson(route('supplier.auctions.state', $a->id))->assertOk();
        $body = $json->getContent();
        $this->assertStringNotContainsString('Beta', $body);
        $this->assertStringNotContainsString('Gamma', $body);
        $json->assertJsonPath('l1_amount', null)->assertJsonPath('my_rank', 2)->assertJsonMissingPath('standings');

        $page = $this->actingAs($this->s['A'][1])->get(route('supplier.auctions.show', $a->id))->assertOk();
        $this->assertStringNotContainsString('Beta Corrugators', $page->getContent());

        // Buyer sees the full board.
        $this->actingAs($this->buyerUser)->getJson(route('buyer.auctions.state', $a->id))->assertOk()
            ->assertJsonPath('standings.0.supplier', 'Beta Corrugators')
            ->assertJsonPath('current_l1', 99000);
    }

    public function test_rank_and_l1_mode_shows_the_lowest_price_without_names(): void
    {
        $a = $this->live(['visibility' => 'rank_and_l1']);
        $this->bid('B', $a, '99000')->assertOk();

        $json = $this->actingAs($this->s['A'][1])->getJson(route('supplier.auctions.state', $a->id))->assertOk()
            ->assertJsonPath('l1_amount', 99000);
        $this->assertStringNotContainsString('Beta', $json->getContent());
    }

    public function test_outsiders_get_404(): void
    {
        $a = $this->live();

        // A supplier who never quoted.
        [, $outsider] = $this->supplier('Outsider Traders');
        $this->actingAs($outsider)->get(route('supplier.auctions.show', $a->id))->assertNotFound();
        $this->actingAs($outsider)->getJson(route('supplier.auctions.state', $a->id))->assertNotFound();
        $this->actingAs($outsider)->postJson(route('supplier.auctions.bid', $a->id), ['amount' => '90000', 'idempotency_key' => (string) Str::uuid()])
            ->assertNotFound();

        // Another buyer company.
        [, $otherBuyer] = $this->buyer('Rival Industries');
        $this->actingAs($otherBuyer)->get(route('buyer.auctions.show', $a->id))->assertNotFound();
        $this->actingAs($otherBuyer)->getJson(route('buyer.auctions.state', $a->id))->assertNotFound();

        // Supplier can't use buyer routes.
        $this->actingAs($this->s['A'][1])->get(route('buyer.auctions.show', $a->id))->assertForbidden();

        $this->assertSame(0, Bid::where('auction_id', $a->id)->where('kind', Bid::KIND_LIVE)->count());
    }

    public function test_bid_service_refuses_non_participants_directly(): void
    {
        $a = $this->live();
        [$org, $user] = $this->supplier('Outsider Traders');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        app(BidService::class)->place($a, $org, $user, '95000', (string) Str::uuid(), null, null);
    }

    public function test_websocket_channels_are_private_to_each_side(): void
    {
        $a = $this->scheduled();

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1',
        ]);
        app(BroadcastManager::class)->forgetDrivers();
        require base_path('routes/channels.php');

        $auth = fn (User $u, string $channel) => $this->actingAs($u)
            ->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-'.$channel]);

        $a1 = $this->s['A'];
        $auth($this->buyerUser, "auction.{$a->id}.buyer")->assertOk()->assertJsonStructure(['auth']);
        $auth($a1[1], "auction.{$a->id}.buyer")->assertForbidden();
        $auth($a1[1], "auction.{$a->id}.supplier.{$a1[0]->id}")->assertOk();
        $auth($a1[1], "auction.{$a->id}.supplier.{$this->s['B'][0]->id}")->assertForbidden();
        $auth($this->buyerUser, "auction.{$a->id}.supplier.{$a1[0]->id}")->assertForbidden();

        [, $otherBuyer] = $this->buyer('Rival Industries');
        $auth($otherBuyer, "auction.{$a->id}.buyer")->assertForbidden();
    }

    public function test_oversized_socket_payload_becomes_a_refresh_signal(): void
    {
        $small = new \App\Events\AuctionStateChanged('auction.1.buyer', ['id' => 1, 'status' => 'live']);
        $this->assertSame(['id' => 1, 'status' => 'live'], $small->broadcastWith());

        $big = new \App\Events\AuctionStateChanged('auction.1.buyer', ['id' => 1, 'recent' => array_fill(0, 500, ['supplier' => str_repeat('x', 40)])]);
        $this->assertSame(['id' => 1, 'refresh' => true], $big->broadcastWith());
    }
}
