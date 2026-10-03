<?php

namespace Tests\Feature;

use App\Enums\AwardStatus;
use App\Enums\OrgRole;
use App\Mail\AwardApprovalRequestMail;
use App\Mail\NotSelectedMail;
use App\Mail\PurchaseOrderMail;
use App\Models\Auction;
use App\Models\Award;
use App\Models\Bid;
use App\Models\BuyerSupplier;
use App\Models\Organization;
use App\Models\Rfq;
use App\Models\RfqInvite;
use App\Models\User;
use App\Services\Auction\AuctionService;
use App\Services\RfqService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

/**
 * Item-by-item RFQs: each line has its own L1, the auction ranks every line separately, and one
 * award decision can send a PO to each supplier chosen (approved or rejected together).
 */
class ItemWiseTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    private Organization $buyer;
    private User $buyerUser;
    /** @var array<string, array{0: Organization, 1: User}> */
    private array $s = [];
    private Rfq $rfq;
    private Carbon $t0;
    private string $diskRoot;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->diskRoot = sys_get_temp_dir().'/getl1-itemwise-'.bin2hex(random_bytes(6));
        Storage::set('local', Storage::createLocalDriver(['root' => $this->diskRoot, 'throw' => true]));

        $this->t0 = Carbon::parse('2026-10-05 04:30:00', 'UTC');
        $this->travelTo($this->t0);

        [$this->buyer, $this->buyerUser] = $this->buyer('Acme Buyers');
        $this->buyer->update(['state' => 'Haryana', 'address' => 'Sector 8', 'city' => 'Panchkula']);
        foreach (['A' => 'Alpha Packaging', 'B' => 'Beta Corrugators', 'C' => 'Gamma Tapes'] as $k => $name) {
            [$org, $user] = $this->supplier($name);
            BuyerSupplier::create(['buyer_org_id' => $this->buyer->id, 'company_name' => $name, 'status' => 'active',
                'contact_email' => $user->email, 'supplier_org_id' => $org->id]);
            $this->s[$k] = [$org, $user];
        }

        // Created through the real form, choosing "Item by item".
        $this->actingAs($this->buyerUser)->post(route('buyer.rfqs.store'), [
            'title' => 'Packing material',
            'bid_basis' => 'per_item',
            'quote_deadline' => now()->addHours(3)->setTimezone('Asia/Kolkata')->format('Y-m-d\TH:i'),
            'items' => [
                ['name' => 'Box 5-ply', 'qty' => 1000, 'unit' => 'pcs'],
                ['name' => 'BOPP tape', 'qty' => 200, 'unit' => 'roll'],
            ],
        ])->assertSessionHasNoErrors();
        app(CurrentOrganization::class)->set(null);
        $rfq = Rfq::withoutGlobalScopes()->latest('id')->firstOrFail();
        $svc = app(RfqService::class);
        $svc->invite($rfq, $this->buyerUser, BuyerSupplier::where('buyer_org_id', $this->buyer->id)->pluck('id')->all());
        $svc->publish($rfq->fresh(), $this->buyerUser);
        app(CurrentOrganization::class)->set(null);
        $this->rfq = $rfq->fresh();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->diskRoot);
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    private function quote(string $k, array $prices): void
    {
        $invite = RfqInvite::where('rfq_id', $this->rfq->id)->where('supplier_org_id', $this->s[$k][0]->id)->firstOrFail();
        $this->actingAs($this->s[$k][1])->post(route('supplier.rfqs.accept', $invite->id), ['agree' => 1]);
        $items = [];
        foreach ($this->rfq->items()->orderBy('line_no')->get() as $i => $item) {
            $items[$item->id] = ['unit_price' => $prices[$i], 'gst_rate' => '18', 'freight' => 0];
        }
        $this->actingAs($this->s[$k][1])->post(route('supplier.rfqs.quote', $invite->id), [
            'items' => $items, 'valid_till' => now()->addDays(15)->toDateString(),
        ])->assertSessionHasNoErrors();
        app(CurrentOrganization::class)->set(null);
        $this->travel(1)->minutes();
    }

    /**
     * Box ×1000: A 90, B 85 (L1), C 95. Tape ×200: A 20, B 30, C 10 (L1).
     * Lot totals: A 94,000 · B 91,000 · C 97,000. Best per item: 85,000 + 2,000 = 87,000.
     */
    private function quotesAndDeadline(): void
    {
        $this->quote('A', [90, 20]);
        $this->quote('B', [85, 30]);
        $this->quote('C', [95, 10]);
        $this->travelTo($this->t0->copy()->addHours(4));
    }

    private function items(): array
    {
        $items = $this->rfq->items()->orderBy('line_no')->get();

        return ['box' => $items[0], 'tape' => $items[1]];
    }

    private function choose(array $map, array $extra = [], ?User $as = null): \Illuminate\Testing\TestResponse
    {
        $items = $this->items();
        $choice = [];
        foreach ($map as $item => $k) {
            $choice[$items[$item]->id] = $this->s[$k][0]->id;
        }

        return $this->actingAs($as ?? $this->buyerUser)->post(route('buyer.awards.store', $this->rfq->id), ['items' => $choice] + $extra);
    }

    private function awards()
    {
        return Award::withoutGlobalScopes()->where('rfq_id', $this->rfq->id)->orderBy('id')->get();
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
            'max_extensions' => 2,
            'visibility' => 'rank_only',
        ], $overrides);
    }

    private function liveAuction(array $overrides = []): Auction
    {
        $this->quotesAndDeadline();
        $a = app(AuctionService::class)->schedule($this->rfq->fresh(), $this->buyerUser, $this->rules($overrides));
        app(CurrentOrganization::class)->set(null);
        $this->travelTo($a->starts_at->copy()->addSecond());

        return $a;
    }

    private function bid(string $k, Auction $a, ?int $item, string $amount): \Illuminate\Testing\TestResponse
    {
        $this->travel(2)->seconds();

        return $this->actingAs($this->s[$k][1])->postJson(route('supplier.auctions.bid', $a->id), array_filter([
            'amount' => $amount, 'idempotency_key' => (string) Str::uuid(), 'item' => $item,
        ]));
    }

    // ---------------------------------------------------------------- RFQ and quotes

    public function test_basis_is_saved_and_explained_to_everyone(): void
    {
        $this->assertTrue($this->rfq->isPerItem());
        $this->actingAs($this->buyerUser)->get(route('buyer.rfqs.show', $this->rfq->id))->assertOk()->assertSee('Item-by-item bidding');

        $invite = RfqInvite::where('rfq_id', $this->rfq->id)->where('supplier_org_id', $this->s['A'][0]->id)->firstOrFail();
        $this->actingAs($this->s['A'][1])->post(route('supplier.rfqs.accept', $invite->id), ['agree' => 1]);
        $this->actingAs($this->s['A'][1])->get(route('supplier.rfqs.show', $invite->id))->assertOk()->assertSee('Item-by-item RFQ');

        // Default stays "one total" for RFQs saved without a choice.
        $rfq = app(RfqService::class)->saveDraft($this->buyer, $this->buyerUser, ['title' => 'Lot', 'items' => [['name' => 'X', 'qty' => 1, 'unit' => 'pcs']]]);
        $this->assertFalse($rfq->isPerItem());
    }

    public function test_comparison_shows_best_rate_on_every_item(): void
    {
        $this->quotesAndDeadline();
        $this->actingAs($this->buyerUser)->get(route('buyer.rfqs.show', $this->rfq->id))->assertOk()
            ->assertSee('Best rate on every item combined')
            ->assertSee('87,000.00')       // 85 × 1000 + 10 × 200
            ->assertSee('4,000.00 less')   // vs 91,000 for everything from the lot L1
            ->assertSee('Award item by item')
            ->assertSee('All to Beta Corrugators');
    }

    // ---------------------------------------------------------------- award without auction

    public function test_split_award_sends_one_po_per_supplier(): void
    {
        $this->quotesAndDeadline();

        // Every item needs a supplier; the lot form isn't accepted.
        $this->choose(['box' => 'B'])->assertSessionHasErrors('award');
        $this->actingAs($this->buyerUser)->post(route('buyer.awards.store', $this->rfq->id), ['supplier_org_id' => $this->s['B'][0]->id])
            ->assertSessionHasErrors('items');
        $this->assertCount(0, $this->awards());

        $this->choose(['box' => 'B', 'tape' => 'C'])->assertSessionHasNoErrors()->assertSessionHas('status');
        $awards = $this->awards();
        $this->assertCount(2, $awards);
        $this->assertNotNull($awards[0]->group_key);
        $this->assertSame($awards[0]->group_key, $awards[1]->group_key);

        $b = $awards->firstWhere('supplier_org_id', $this->s['B'][0]->id);
        $c = $awards->firstWhere('supplier_org_id', $this->s['C'][0]->id);
        $this->assertEquals(85000, (float) $b->total);
        $this->assertEquals(2000, (float) $c->total);
        $this->assertEquals(85000 * 1.18, (float) $b->grand_total);
        $this->assertCount(1, $b->lines['items']);
        $this->assertSame('Box 5-ply', $b->lines['items'][0]['name']);
        $this->assertEquals(85, $b->lines['items'][0]['unit_price']);
        $this->assertSame(1, $b->rank);

        // Both POs issued with their own numbers; suppliers get only their own.
        foreach ([$b, $c] as $aw) {
            $aw->refresh();
            $this->assertSame(AwardStatus::PoSent, $aw->status);
        }
        $this->assertNotSame($b->po_number, $c->po_number);
        Mail::assertQueued(PurchaseOrderMail::class, 2);
        // "Not selected" goes once, only to the supplier that won nothing.
        Mail::assertQueued(NotSelectedMail::class, 1);
        Mail::assertQueued(NotSelectedMail::class, fn ($m) => $m->hasTo($this->s['A'][1]->email));

        $this->actingAs($this->buyerUser)->get(route('buyer.rfqs.show', $this->rfq->id))->assertOk()
            ->assertSee('Awarded item by item to 2 suppliers')->assertSee($b->po_number)->assertSee($c->po_number);

        // A second decision is refused.
        $this->choose(['box' => 'A', 'tape' => 'A'])->assertSessionHasErrors('award');
    }

    public function test_not_l1_on_any_item_needs_a_reason(): void
    {
        $this->quotesAndDeadline();
        $this->choose(['box' => 'B', 'tape' => 'B'])->assertSessionHasErrors('reason');
        $this->choose(['box' => 'B', 'tape' => 'B'], ['reason' => 'One delivery for both items'])->assertSessionHasNoErrors();

        $awards = $this->awards();
        $this->assertCount(1, $awards, 'Same supplier for every item: one PO');
        $this->assertEquals(91000, (float) $awards[0]->total);
        $this->assertSame(3, $awards[0]->rank, 'Worst rank among its items (B is L3 on tape)');
        $this->assertSame('One delivery for both items', $awards[0]->reason);
    }

    public function test_split_is_approved_and_rejected_as_a_whole(): void
    {
        $approver = $this->memberOf($this->buyer, OrgRole::Approver);
        $this->quotesAndDeadline();

        $this->choose(['box' => 'B', 'tape' => 'C']);
        $awards = $this->awards();
        $this->assertTrue($awards->every(fn ($a) => $a->status === AwardStatus::PendingApproval));
        Mail::assertQueued(AwardApprovalRequestMail::class);
        Mail::assertNotQueued(PurchaseOrderMail::class);

        // Reject one: the whole decision is rejected, and the buyer can decide again.
        $this->actingAs($approver)->post(route('buyer.awards.reject', $awards[1]->id), ['decision_note' => 'Use one supplier'])->assertRedirect();
        $this->assertTrue($this->awards()->every(fn ($a) => $a->status === AwardStatus::Rejected));

        $this->choose(['box' => 'B', 'tape' => 'C']);
        $second = $this->awards()->where('status', AwardStatus::PendingApproval)->values();
        $this->assertCount(2, $second);

        $this->actingAs($approver)->get(route('buyer.rfqs.show', $this->rfq->id))->assertOk()->assertSee('Approve and send 2 POs');
        $this->actingAs($approver)->post(route('buyer.awards.approve', $second[0]->id), ['decision_note' => 'OK'])->assertRedirect();
        foreach ($second as $aw) {
            $this->assertSame(AwardStatus::PoSent, $aw->fresh()->status);
            $this->assertSame($approver->id, $aw->fresh()->approved_by);
        }
        Mail::assertQueued(PurchaseOrderMail::class, 2);
    }

    // ---------------------------------------------------------------- item-wise auction

    public function test_auction_opens_with_sealed_rates_per_item(): void
    {
        $this->quotesAndDeadline();
        try {
            app(AuctionService::class)->schedule($this->rfq->fresh(), $this->buyerUser, $this->rules(['min_decrement_type' => 'amount', 'min_decrement_value' => '1']));
            $this->fail('Amount decrement accepted for an item-wise auction');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('min_decrement_value', $e->errors());
        }

        $this->actingAs($this->buyerUser)->get(route('buyer.auctions.create', $this->rfq->id))->assertOk()->assertSee('each item is ranked on its own')->assertDontSee('Japanese auction');
        $a = app(AuctionService::class)->schedule($this->rfq->fresh(), $this->buyerUser, $this->rules());
        $this->assertTrue($a->isPerItem());
        $this->assertEquals(87000, (float) $a->start_price);
        $this->assertEquals(87000, (float) $a->current_l1);
        $this->assertNull($a->current_l1_supplier_org_id);
        $this->assertSame(6, Bid::where('auction_id', $a->id)->where('kind', Bid::KIND_SEALED)->whereNotNull('rfq_item_id')->count());
    }

    public function test_bidding_item_by_item(): void
    {
        $a = $this->liveAuction();
        ['box' => $box, 'tape' => $tape] = $this->items();

        // An item is required, and must belong to this auction.
        $this->bid('A', $a, null, '89')->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->bid('A', $a, 999999, '89')->assertStatus(422);

        // A beats B on the box: L1 on the box, combined L1 drops to 84,000 + 2,000.
        $this->bid('A', $a, $box->id, '84')->assertOk()->assertJsonPath('state.leading', 1);
        $fresh = Auction::withoutGlobalScopes()->find($a->id);
        $this->assertEquals(86000, (float) $fresh->current_l1);
        $this->assertSame(1, $fresh->bid_count);
        // B held the box until now: one alert naming the item.
        $this->assertContains('You are no longer L1 on '.$box->name, $this->s['B'][1]->fresh()->notifications()->get()->pluck('data.title')->all());
        $this->assertSame(0, $this->s['A'][1]->fresh()->notifications()->where('data', 'like', '%no longer L1%')->count());

        // Rules apply per item: B's own box rate is 85, so it must go to at most 84.57.
        $this->bid('B', $a, $box->id, '84.80')->assertStatus(422);
        // Typo guard against the item's L1 (84): 10% below is 75.60.
        $this->bid('C', $a, $box->id, '50')->assertStatus(422);
        // Bidding on one item never touches another.
        $this->bid('A', $a, $tape->id, '9.50')->assertOk();
        $this->assertSame(2, Bid::where('auction_id', $a->id)->where('kind', Bid::KIND_LIVE)->count());

        // Supplier sees own rank/rate per item, never others' names or rates.
        $res = $this->actingAs($this->s['B'][1])->getJson(route('supplier.auctions.state', $a->id))->assertOk();
        $res->assertJsonPath('basis', 'per_item')->assertJsonPath('leading', 0)
            ->assertJsonPath('items.0.my_rank', 2)->assertJsonPath('items.0.my_rate', 85)->assertJsonPath('items.0.l1_rate', null);
        $this->assertStringNotContainsString('Alpha', $res->getContent());
        $this->assertStringNotContainsString('Gamma', $res->getContent());

        // Buyer sees each item's L1 and who holds it.
        $this->actingAs($this->buyerUser)->getJson(route('buyer.auctions.state', $a->id))->assertOk()
            ->assertJsonPath('items.0.l1_supplier', 'Alpha Packaging')->assertJsonPath('items.0.l1_rate', 84)
            ->assertJsonPath('items.1.l1_supplier', 'Alpha Packaging')->assertJsonPath('items.1.l1_rate', 9.5);

        // Pages render.
        $this->actingAs($this->buyerUser)->get(route('buyer.auctions.show', $a->id))->assertOk()->assertSee('Item by item')->assertSee('If one supplier took every item');
        $this->actingAs($this->s['A'][1])->get(route('supplier.auctions.show', $a->id))->assertOk()->assertSee('Items where you')->assertSee('New rate per unit');
    }

    public function test_rank_and_l1_shows_each_items_l1_rate(): void
    {
        $a = $this->liveAuction(['visibility' => 'rank_and_l1']);
        $this->actingAs($this->s['A'][1])->getJson(route('supplier.auctions.state', $a->id))->assertOk()
            ->assertJsonPath('items.0.l1_rate', 85)->assertJsonPath('items.1.l1_rate', 10);
    }

    public function test_award_after_auction_uses_final_rates(): void
    {
        $a = $this->liveAuction();
        ['box' => $box] = $this->items();
        $this->bid('A', $a, $box->id, '84')->assertOk();
        $this->travelTo(Auction::withoutGlobalScopes()->find($a->id)->ends_at->copy()->addSecond());
        app(AuctionService::class)->tick();

        $this->actingAs($this->buyerUser)->get(route('buyer.rfqs.show', $this->rfq->id))->assertOk()->assertSee('Ranked by final auction rate');
        $this->choose(['box' => 'A', 'tape' => 'C'])->assertSessionHasNoErrors();
        $awards = $this->awards();
        $this->assertCount(2, $awards);
        $alpha = $awards->firstWhere('supplier_org_id', $this->s['A'][0]->id);
        $this->assertEquals(84000, (float) $alpha->total);
        $this->assertSame('auction', $alpha->source);
        $this->assertSame($a->id, $alpha->auction_id);
    }

    public function test_rank_only_never_reveals_the_l1_rate_through_the_typo_floor(): void
    {
        $a = $this->liveAuction();
        ['box' => $box] = $this->items();
        $res = $this->actingAs($this->s['A'][1])->getJson(route('supplier.auctions.state', $a->id))->assertOk();
        $res->assertJsonPath('items.0.floor', null)->assertJsonPath('items.0.l1_rate', null);
        // The server still refuses typos, without printing a price derived from L1.
        $this->bid('C', $a, $box->id, '50')->assertStatus(422)
            ->assertJsonPath('errors.amount.0', fn ($m) => ! str_contains($m, '₹'));
    }

    public function test_savings_report_compares_each_split_award_with_its_own_lines(): void
    {
        $this->rfq->items()->update(['last_purchase_price' => null]);
        $box = $this->items()['box'];
        $box->update(['last_purchase_price' => 100]);
        $this->items()['tape']->update(['last_purchase_price' => 15]);
        $this->quotesAndDeadline();
        $this->choose(['box' => 'A', 'tape' => 'C'], ['reason' => 'Alpha delivers boxes faster'])->assertSessionHasNoErrors();

        $report = app(\App\Services\Reports\SavingsReport::class)->build($this->buyer->id, 'all');
        $alpha = $report['rows']->first(fn ($r) => $r['supplier'] === 'Alpha Packaging');
        $gamma = $report['rows']->first(fn ($r) => $r['supplier'] === 'Gamma Tapes');
        // Box: best sealed 85 × 1000 = 85,000 vs paid 90,000; tape: best sealed 10 × 200 = 2,000 vs paid 2,000.
        $this->assertEquals(85000, $alpha['best_sealed']);
        $this->assertEquals(-5000, $alpha['vs_sealed']);
        $this->assertEquals(2000, $gamma['best_sealed']);
        $this->assertEquals(0, $gamma['vs_sealed']);
        $this->assertEquals(100000 - 90000, $alpha['vs_last']);
        $this->assertEquals(3000 - 2000, $gamma['vs_last']);
        $this->assertEquals(-5000, $report['totals']['vs_sealed']);
    }
}
