<?php

namespace Tests\Feature;

use App\Enums\OrgRole;
use App\Models\Award;
use App\Models\BuyerSupplier;
use App\Models\Organization;
use App\Models\PricePoint;
use App\Models\RateContract;
use App\Models\Rfq;
use App\Models\RfqInvite;
use App\Models\User;
use App\Services\Automations;
use App\Services\Pricing\PriceHistory;
use App\Services\RfqService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

/** Price history from every PO, and rate contracts with suppliers. */
class PricesTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    private Organization $buyer;
    private User $buyerUser;
    /** @var array<string, array{0: Organization, 1: User}> */
    private array $s = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-10-05 04:30:00', 'UTC')); // 10:00 IST
        [$this->buyer, $this->buyerUser] = $this->buyer('Acme Buyers');
        foreach (['A' => 'Alpha Packaging', 'B' => 'Beta Corrugators', 'C' => 'Gamma Boxes'] as $k => $name) {
            [$org, $user] = $this->supplier($name);
            BuyerSupplier::create(['buyer_org_id' => $this->buyer->id, 'company_name' => $name, 'status' => 'active',
                'contact_email' => $user->email, 'supplier_org_id' => $org->id]);
            $this->s[$k] = [$org, $user];
        }
    }

    private function titles(User $u): array
    {
        return $u->fresh()->notifications()->get()->pluck('data.title')->all();
    }

    /** Box 1000 pcs + tape 200 rolls. A quotes $a[0]/$a[1], B a bit more; awarded to A. */
    private function po(array $a = [90, 20], string $boxName = 'Box 5-ply'): Award
    {
        $svc = app(RfqService::class);
        $rfq = $svc->saveDraft($this->buyer, $this->buyerUser, [
            'title' => 'Packing',
            'quote_deadline' => now()->addHours(3)->setTimezone('Asia/Kolkata')->format('Y-m-d\TH:i'),
            'items' => [['name' => $boxName, 'qty' => 1000, 'unit' => 'pcs'], ['name' => 'BOPP tape', 'qty' => 200, 'unit' => 'roll']],
            'terms' => ['payment' => 'credit_30', 'freight' => 'included'],
        ]);
        $svc->invite($rfq, $this->buyerUser, BuyerSupplier::where('buyer_org_id', $this->buyer->id)->whereIn('supplier_org_id', [$this->s['A'][0]->id, $this->s['B'][0]->id])->pluck('id')->all());
        $svc->publish($rfq->fresh(), $this->buyerUser);
        app(CurrentOrganization::class)->set(null);
        $rfq = Rfq::withoutGlobalScopes()->find($rfq->id);
        foreach (['A' => $a, 'B' => [$a[0] + 5, $a[1] + 5]] as $k => $p) {
            $invite = RfqInvite::where('rfq_id', $rfq->id)->where('supplier_org_id', $this->s[$k][0]->id)->firstOrFail();
            $this->actingAs($this->s[$k][1])->post(route('supplier.rfqs.accept', $invite->id), ['agree' => 1]);
            $items = [];
            foreach ($rfq->items()->orderBy('line_no')->get() as $i => $item) {
                $items[$item->id] = ['unit_price' => $p[$i], 'gst_rate' => '18', 'freight' => 0];
            }
            $this->actingAs($this->s[$k][1])->post(route('supplier.rfqs.quote', $invite->id), ['items' => $items, 'valid_till' => now()->addDays(10)->toDateString()])->assertSessionHasNoErrors();
            app(CurrentOrganization::class)->set(null);
        }
        $this->travel(4)->hours();
        $this->actingAs($this->buyerUser)->post(route('buyer.awards.store', $rfq->id), ['supplier_org_id' => $this->s['A'][0]->id])->assertSessionHasNoErrors();
        app(CurrentOrganization::class)->set(null);

        return Award::withoutGlobalScopes()->where('rfq_id', $rfq->id)->firstOrFail()->fresh();
    }

    public function test_every_po_adds_to_price_history_and_fills_the_next_rfqs_last_price(): void
    {
        $first = $this->po([90, 20]);
        $this->assertSame(2, PricePoint::withoutGlobalScopes()->where('award_id', $first->id)->count());
        PriceHistory::record($first); // again: no duplicates
        $this->assertSame(2, PricePoint::withoutGlobalScopes()->count());

        $this->travel(10)->days();
        $this->po([85, 21], '  box   5-PLY ');  // same item, different spacing/case

        $box = PriceHistory::last($this->buyer->id, 'Box 5-ply', 'pcs');
        $this->assertEquals(85, (float) $box->rate);
        $this->assertSame('Alpha Packaging', $box->supplier->name);

        // A new RFQ for the same item gets the last price filled in; a typed price is kept.
        $rfq = app(RfqService::class)->saveDraft($this->buyer, $this->buyerUser, [
            'title' => 'Next month', 'items' => [
                ['name' => 'BOX 5-ply', 'qty' => 500, 'unit' => 'pcs'],
                ['name' => 'BOPP tape', 'qty' => 100, 'unit' => 'roll', 'last_purchase_price' => '19.50'],
                ['name' => 'New item', 'qty' => 1, 'unit' => 'pcs'],
            ],
        ]);
        app(CurrentOrganization::class)->set(null);
        $items = $rfq->items()->orderBy('line_no')->get();
        $this->assertEquals(85, (float) $items[0]->last_purchase_price);
        $this->assertEquals(19.5, (float) $items[1]->last_purchase_price);
        $this->assertNull($items[2]->last_purchase_price);

        // Pages.
        $this->actingAs($this->buyerUser)->get(route('buyer.prices.index'))->assertOk()
            ->assertSee('Box 5-ply')->assertSee('₹85.00')->assertSee('5.6%'); // 90 → 85
        $this->get(route('buyer.prices.index', ['q' => 'tape']))->assertOk()->assertSee('BOPP tape')->assertDontSee('Box 5-ply');
        $this->get(route('buyer.prices.item', ['key' => PriceHistory::key('Box 5-ply', 'pcs')]))->assertOk()
            ->assertSee('₹90.00')->assertSee('₹85.00')->assertSee($first->po_number);
        $this->get(route('buyer.prices.item', ['key' => 'nothing|pcs']))->assertNotFound();
        $this->getJson(route('buyer.prices.lookup', ['name' => 'box 5-ply', 'unit' => 'pcs']))->assertOk()
            ->assertJsonPath('last.rate', 85)->assertJsonPath('last.supplier', 'Alpha Packaging')->assertJsonPath('contract', null);
    }

    public function test_same_name_with_different_specs_keeps_separate_prices(): void
    {
        $award = $this->po();
        $award->forceFill(['lines' => ['items' => [
            ['rfq_item_id' => 1, 'name' => 'MS pipe', 'spec' => '25 mm', 'unit' => 'mtr', 'qty' => 100, 'unit_price' => 210.5, 'gst_rate' => 18],
            ['rfq_item_id' => 2, 'name' => 'MS pipe', 'spec' => '40  MM', 'unit' => 'mtr', 'qty' => 50, 'unit_price' => 0.0125, 'gst_rate' => 18],
        ]]])->save();
        PriceHistory::record($award);

        $this->assertEquals(210.5, (float) PriceHistory::last($this->buyer->id, 'MS pipe', 'mtr', '25 mm')->rate);
        $this->assertEquals(0.0125, (float) PriceHistory::last($this->buyer->id, 'ms  pipe', 'mtr', '40 mm')->rate); // 4 decimals kept
        $this->assertNull(PriceHistory::last($this->buyer->id, 'MS pipe', 'mtr'));

        // A contract made from this PO keeps both lines.
        $this->actingAs($this->buyerUser)->post(route('buyer.contracts.store'), [
            'title' => 'Pipes', 'supplier_org_id' => $this->s['A'][0]->id, 'valid_from' => '2026-10-05', 'valid_to' => '2027-03-31',
            'items' => [['name' => 'MS pipe', 'spec' => '25 mm', 'unit' => 'mtr', 'rate' => '210.5'], ['name' => 'MS pipe', 'spec' => '40 mm', 'unit' => 'mtr', 'rate' => '0.0125']],
        ])->assertSessionHasNoErrors();
        $this->getJson(route('buyer.prices.lookup', ['name' => 'MS pipe', 'unit' => 'mtr', 'spec' => '40 mm']))->assertJsonPath('contract.rate', 0.0125);
    }

    public function test_price_history_is_private_to_each_buyer(): void
    {
        $this->po();
        [$other, $otherUser] = $this->buyer('Other Buyer');
        $this->actingAs($otherUser)->get(route('buyer.prices.index'))->assertOk()->assertDontSee('Box 5-ply');
        $this->actingAs($otherUser)->get(route('buyer.prices.item', ['key' => PriceHistory::key('Box 5-ply', 'pcs')]))->assertNotFound();
        $this->actingAs($otherUser)->getJson(route('buyer.prices.lookup', ['name' => 'Box 5-ply', 'unit' => 'pcs']))->assertJsonPath('last', null);
        // Suppliers never reach it.
        $this->actingAs($this->s['A'][1])->get(route('buyer.prices.index'))->assertForbidden();
    }

    public function test_rate_contract_from_a_po_confirmed_by_the_supplier(): void
    {
        $award = $this->po([90, 20]);
        $this->actingAs($this->buyerUser)->get(route('buyer.orders.show', $award->id))->assertSee('Make rate contract');
        $this->get(route('buyer.contracts.create', ['award' => $award->id]))->assertOk()
            ->assertSee('value="Box 5-ply"', false)->assertSee('value="90"', false);

        $res = $this->post(route('buyer.contracts.store'), [
            'title' => 'Packing FY 2026-27', 'supplier_org_id' => $this->s['A'][0]->id, 'award_id' => $award->id,
            'valid_from' => '2026-10-05', 'valid_to' => '2027-09-30', 'terms' => 'Delivery within 7 days of each order.',
            'items' => [['name' => 'Box 5-ply', 'unit' => 'pcs', 'rate' => '88', 'gst_rate' => '18'], ['name' => 'BOPP tape', 'unit' => 'roll', 'rate' => '20', 'gst_rate' => '18']],
        ]);
        $res->assertSessionHasNoErrors();
        $rc = RateContract::withoutGlobalScopes()->firstOrFail();
        $res->assertRedirect(route('buyer.contracts.show', $rc->id));
        $this->assertSame('RC-2026-0001', $rc->rc_number);
        $this->assertSame('active', $rc->state());
        $this->assertSame($award->id, $rc->award_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'rate_contract_created']);
        $this->assertContains('Rate contract from Acme Buyers: RC-2026-0001', $this->titles($this->s['A'][1]));

        // The contract shows wherever the item comes up.
        $this->getJson(route('buyer.prices.lookup', ['name' => 'box 5-ply', 'unit' => 'pcs']))->assertJsonPath('contract.rate', 88)->assertJsonPath('contract.number', 'RC-2026-0001');
        $rfq = app(RfqService::class)->saveDraft($this->buyer, $this->buyerUser, ['title' => 'Boxes again', 'items' => [['name' => 'Box 5-ply', 'qty' => 10, 'unit' => 'pcs']]]);
        app(CurrentOrganization::class)->set(null);
        $this->actingAs($this->buyerUser)->get(route('buyer.rfqs.show', $rfq->id))->assertOk()->assertSee('Contract ₹88.00');

        // Supplier: sees and confirms only its own; another supplier can't.
        $this->actingAs($this->s['B'][1])->get(route('supplier.contracts.show', $rc->id))->assertNotFound();
        $this->actingAs($this->s['B'][1])->post(route('supplier.contracts.accept', $rc->id))->assertNotFound();
        $this->actingAs($this->s['A'][1])->get(route('supplier.contracts.index'))->assertOk()->assertSee('Packing FY 2026-27')->assertSee('Please confirm');
        $this->get(route('supplier.contracts.show', $rc->id))->assertOk()->assertSee('₹88.00')->assertSee('Delivery within 7 days');
        $this->post(route('supplier.contracts.accept', $rc->id))->assertRedirect();
        $this->assertNotNull($rc->fresh()->supplier_accepted_at);
        $this->assertContains('Rate contract confirmed: RC-2026-0001', $this->titles($this->buyerUser));

        // Ending early needs a reason and tells the supplier.
        $this->actingAs($this->buyerUser)->post(route('buyer.contracts.cancel', $rc->id), ['cancel_reason' => 'x'])->assertSessionHasErrors('cancel_reason');
        $this->post(route('buyer.contracts.cancel', $rc->id), ['cancel_reason' => 'Supplier missed two deliveries'])->assertRedirect();
        $this->assertSame('cancelled', $rc->fresh()->state());
        $this->assertContains('Rate contract ended: RC-2026-0001', $this->titles($this->s['A'][1]));
        $this->getJson(route('buyer.prices.lookup', ['name' => 'box 5-ply', 'unit' => 'pcs']))->assertJsonPath('contract', null);
    }

    public function test_supplier_cannot_confirm_an_ended_contract_and_is_told_so(): void
    {
        $this->po();
        $this->actingAs($this->buyerUser)->post(route('buyer.contracts.store'), [
            'title' => 'Boxes', 'supplier_org_id' => $this->s['A'][0]->id, 'valid_from' => '2026-10-05', 'valid_to' => '2026-10-20',
            'items' => [['name' => 'Box 5-ply', 'unit' => 'pcs', 'rate' => '88']],
        ])->assertSessionHasNoErrors();
        $rc = RateContract::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('30', $rc->expiry_alerted); // made with 15 days to run: no "ending soon" reminder straight away
        $this->post(route('buyer.contracts.cancel', $rc->id), ['cancel_reason' => 'Rates changed'])->assertRedirect();
        $this->actingAs($this->s['A'][1])->post(route('supplier.contracts.accept', $rc->id))->assertSessionHasErrors('contract');
        $this->assertNull($rc->fresh()->supplier_accepted_at);
    }

    public function test_contract_rules_and_who_can_do_what(): void
    {
        $award = $this->po();
        $base = ['title' => 'T', 'valid_from' => '2026-10-05', 'valid_to' => '2027-03-31',
            'items' => [['name' => 'Box 5-ply', 'unit' => 'pcs', 'rate' => '88']]];

        $this->actingAs($this->buyerUser);
        // Only suppliers you have ordered from (C was never ordered from).
        $this->post(route('buyer.contracts.store'), $base + ['supplier_org_id' => $this->s['C'][0]->id])->assertSessionHasErrors('supplier_org_id');
        // Dates and items.
        $this->post(route('buyer.contracts.store'), ['valid_to' => '2026-10-01'] + $base + ['supplier_org_id' => $this->s['A'][0]->id])->assertSessionHasErrors('valid_to');
        $this->post(route('buyer.contracts.store'), ['valid_to' => '2030-01-01'] + $base + ['supplier_org_id' => $this->s['A'][0]->id])->assertSessionHasErrors('valid_to');
        $this->post(route('buyer.contracts.store'), ['items' => [['name' => 'Box', 'unit' => 'pcs', 'rate' => '1'], ['name' => ' box ', 'unit' => 'pcs', 'rate' => '2']]] + $base + ['supplier_org_id' => $this->s['A'][0]->id])->assertSessionHasErrors('items');
        $this->assertSame(0, RateContract::withoutGlobalScopes()->count());

        // Someone else's PO can't be linked.
        [$other, $otherUser] = $this->buyer('Other Buyer');
        $this->actingAs($otherUser)->get(route('buyer.contracts.create', ['award' => $award->id]))->assertNotFound();

        // Approvers view but don't create; requesters never reach it.
        $approver = $this->memberOf($this->buyer, OrgRole::Approver);
        $this->actingAs($approver)->get(route('buyer.contracts.index'))->assertOk();
        $this->actingAs($approver)->get(route('buyer.contracts.create'))->assertForbidden();
        $this->actingAs($approver)->post(route('buyer.contracts.store'), $base + ['supplier_org_id' => $this->s['A'][0]->id])->assertForbidden();
        $requester = $this->memberOf($this->buyer, OrgRole::Requester);
        $this->actingAs($requester)->get(route('buyer.prices.index'))->assertRedirect(route('buyer.requests.index'));
        $this->actingAs($requester)->getJson(route('buyer.prices.lookup', ['name' => 'Box 5-ply', 'unit' => 'pcs']))->assertRedirect();
    }

    public function test_buyer_is_reminded_30_and_7_days_before_a_contract_ends(): void
    {
        $this->po();
        $this->actingAs($this->buyerUser)->post(route('buyer.contracts.store'), [
            'title' => 'Boxes', 'supplier_org_id' => $this->s['A'][0]->id, 'valid_from' => '2026-10-05', 'valid_to' => '2026-12-31',
            'items' => [['name' => 'Box 5-ply', 'unit' => 'pcs', 'rate' => '88']],
        ])->assertSessionHasNoErrors();
        $run = fn () => app(Automations::class)->run()['contract_reminders'];
        $count = fn () => collect($this->titles($this->buyerUser))->filter(fn ($t) => str_starts_with($t, 'Rate contract ending'))->count();

        $this->assertSame(0, $run());
        $this->travelTo(Carbon::parse('2026-12-02 04:00:00', 'UTC')); // 09:30 IST, 29 days left
        $this->assertSame(1, $run());
        $this->assertSame(0, $run()); // once
        $this->travelTo(Carbon::parse('2026-12-25 04:00:00', 'UTC')); // 6 days left
        $this->assertSame(1, $run());
        $this->assertSame(0, $run());
        $this->assertSame(2, $count());

        // Expired: shown as ended, no longer offered.
        $this->travelTo(Carbon::parse('2027-01-01 04:00:00', 'UTC'));
        $this->assertSame('expired', RateContract::withoutGlobalScopes()->first()->state());
        $this->assertTrue(PriceHistory::contractsFor($this->buyer->id, 'Box 5-ply', 'pcs')->isEmpty());
        $this->assertSame(0, $run());
    }
}
