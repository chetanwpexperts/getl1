<?php

namespace Tests\Feature;

use App\Enums\OrgRole;
use App\Enums\RfqStatus;
use App\Mail\PurchaseRequestMail;
use App\Models\Award;
use App\Models\BuyerSupplier;
use App\Models\Organization;
use App\Models\PurchaseRequest;
use App\Models\Rfq;
use App\Models\RfqInvite;
use App\Models\User;
use App\Services\RfqService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

/** Purchase requests: raise → approve → RFQ → PO → delivery, with the requester kept informed. */
class PurchaseRequestTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    private Organization $buyer;
    private User $admin;
    private User $buyerUser;
    private User $approver;
    private User $requester;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-10-05 04:30:00', 'UTC'));
        [$this->buyer, $this->admin] = $this->buyer('Acme Buyers');
        $this->buyerUser = $this->memberOf($this->buyer, OrgRole::BuyerUser);
        $this->approver = $this->memberOf($this->buyer, OrgRole::Approver);
        $this->requester = $this->memberOf($this->buyer, OrgRole::Requester);
    }

    private function raise(User $as, array $o = []): \Illuminate\Testing\TestResponse
    {
        $r = $this->actingAs($as)->post(route('buyer.requests.store'), array_merge([
            'title' => 'Packing for November',
            'department' => 'Stores',
            'needed_by' => '2026-10-20',
            'notes' => 'Stock runs out mid-month',
            'items' => [
                ['name' => 'Box 5-ply', 'spec' => '18x12x12', 'qty' => '500', 'unit' => 'pcs', 'est_rate' => '42'],
                ['name' => 'BOPP tape', 'spec' => '', 'qty' => '50', 'unit' => 'roll', 'est_rate' => ''],
            ],
        ], $o));
        app(CurrentOrganization::class)->set(null);

        return $r;
    }

    private function titles(User $u): array
    {
        return $u->fresh()->notifications()->get()->pluck('data.title')->all();
    }

    public function test_requester_raises_and_approver_decides(): void
    {
        $this->raise($this->requester)->assertRedirect()->assertSessionHasNoErrors();
        $pr = PurchaseRequest::firstOrFail();
        $this->assertSame('PR-2026-0001', $pr->pr_number);
        $this->assertSame(PurchaseRequest::PENDING, $pr->status);
        $this->assertEquals(21000, (float) $pr->estimated_total);
        $this->assertSame([null, null], [$pr->items[1]['spec'], $pr->items[1]['est_rate']]);

        // Approvers and admins are asked; the requester and plain buyers aren't.
        Mail::assertQueued(PurchaseRequestMail::class, fn ($m) => $m->hasTo($this->approver->email) && $m->kind === 'submitted');
        Mail::assertQueued(PurchaseRequestMail::class, fn ($m) => $m->hasTo($this->admin->email));
        Mail::assertNotQueued(PurchaseRequestMail::class, fn ($m) => $m->hasTo($this->requester->email));
        $this->assertContains('Purchase request to approve: PR-2026-0001', $this->titles($this->approver));
        $this->assertSame([], $this->titles($this->buyerUser));

        // Nobody approves their own; plain buyers can't approve at all.
        $this->actingAs($this->buyerUser)->post(route('buyer.requests.approve', $pr->id))->assertForbidden();
        $this->actingAs($this->approver)->post(route('buyer.requests.reject', $pr->id), ['decision_note' => 'no'])->assertSessionHasErrors('decision_note');
        $this->actingAs($this->approver)->get(route('buyer.requests.show', $pr->id))->assertOk()->assertSee('Your decision');
        $this->actingAs($this->approver)->post(route('buyer.requests.approve', $pr->id), ['decision_note' => 'OK, buy it'])->assertSessionHasNoErrors();

        $pr->refresh();
        $this->assertSame(PurchaseRequest::APPROVED, $pr->status);
        $this->assertSame($this->approver->id, $pr->decided_by);
        Mail::assertQueued(PurchaseRequestMail::class, fn ($m) => $m->hasTo($this->requester->email) && $m->kind === 'decided');
        $this->assertContains('Request approved: PR-2026-0001', $this->titles($this->requester));
        $this->assertContains('Approved request to buy: PR-2026-0001', $this->titles($this->buyerUser));
        $this->assertDatabaseHas('audit_logs', ['action' => 'purchase_request_approved']);

        // Decided once only.
        $this->actingAs($this->admin)->post(route('buyer.requests.reject', $pr->id), ['decision_note' => 'Too late now'])->assertSessionHasErrors('decision_note');
        $this->assertStringContainsString('approved', (new PurchaseRequestMail($pr, 'decided'))->render());
    }

    public function test_approver_cannot_approve_own_request_and_admin_requests_skip_approval(): void
    {
        $this->raise($this->approver);
        $own = PurchaseRequest::firstOrFail();
        $this->actingAs($this->approver)->post(route('buyer.requests.approve', $own->id))->assertSessionHasErrors('decision_note');
        $this->assertSame(PurchaseRequest::PENDING, $own->fresh()->status);
        Mail::assertNotQueued(PurchaseRequestMail::class, fn ($m) => $m->hasTo($this->approver->email));

        $this->raise($this->admin, ['title' => 'Urgent spares']);
        $adm = PurchaseRequest::where('title', 'Urgent spares')->firstOrFail();
        $this->assertSame('PR-2026-0002', $adm->pr_number);
        $this->assertSame(PurchaseRequest::APPROVED, $adm->status);
        $this->assertContains('Approved request to buy: PR-2026-0002', $this->titles($this->buyerUser));
    }

    public function test_requester_only_sees_own_requests_and_nothing_else(): void
    {
        $this->raise($this->buyerUser, ['title' => 'Someone else’s']);
        $other = PurchaseRequest::firstOrFail();
        $this->raise($this->requester);
        $mine = PurchaseRequest::where('requested_by', $this->requester->id)->firstOrFail();

        $this->actingAs($this->requester);
        $this->get(route('buyer.requests.index'))->assertOk()->assertSee('Packing for November')->assertDontSee('Someone else’s')->assertDontSee('To approve');
        $this->get(route('buyer.requests.show', $mine->id))->assertOk()->assertDontSee('Your decision');
        $this->get(route('buyer.requests.show', $other->id))->assertNotFound();
        $this->post(route('buyer.requests.approve', $other->id))->assertNotFound();
        $this->post(route('buyer.requests.convert'), ['ids' => [$mine->id]])->assertForbidden();

        // Every other company page sends them back to their requests; changes are refused.
        foreach (['dashboard', 'buyer.rfqs.index', 'buyer.orders.index', 'buyer.payments.index', 'buyer.suppliers.index', 'buyer.reports.savings', 'company.edit', 'team.index'] as $route) {
            $this->get(route($route))->assertRedirect(route('buyer.requests.index'));
        }
        $this->post(route('buyer.rfqs.store'), ['title' => 'x'])->assertForbidden();

        // The menu shows only their requests.
        $page = $this->get(route('buyer.requests.index'))->getContent();
        $this->assertStringContainsString('My requests', $page);
        $this->assertStringNotContainsString('RFQs &amp; auctions', $page);
        $this->assertStringNotContainsString('Plan &amp; billing', $page);
    }

    public function test_approved_requests_become_one_draft_rfq_and_go_back_if_it_is_cancelled(): void
    {
        $this->raise($this->requester);
        $this->raise($this->approver, ['title' => 'Plant 2 packing', 'needed_by' => '2026-10-15', 'items' => [
            ['name' => 'box 5-ply', 'spec' => '18X12X12', 'qty' => '300', 'unit' => 'pcs'],
            ['name' => 'Stretch film', 'qty' => '20', 'unit' => 'roll'],
        ]]);
        [$a, $b] = PurchaseRequest::orderBy('id')->get()->all();

        // Not approved yet: refused.
        $this->actingAs($this->buyerUser)->post(route('buyer.requests.convert'), ['ids' => [$a->id]])->assertSessionHasErrors('ids');
        $this->actingAs($this->approver)->post(route('buyer.requests.approve', $a->id));
        $this->actingAs($this->admin)->post(route('buyer.requests.approve', $b->id));
        app(CurrentOrganization::class)->set(null);

        $this->actingAs($this->buyerUser)->get(route('buyer.requests.index', ['tab' => 'ready']))->assertOk()->assertSee('Create RFQ from selected');
        $res = $this->actingAs($this->buyerUser)->post(route('buyer.requests.convert'), ['ids' => [$a->id, $b->id]]);
        app(CurrentOrganization::class)->set(null);
        $rfq = Rfq::withoutGlobalScopes()->latest('id')->firstOrFail();
        $res->assertRedirect(route('buyer.rfqs.edit', $rfq->id));

        $this->assertSame(RfqStatus::Draft, $rfq->status);
        // Suppliers see the RFQ: the requests' titles only, no PR numbers or internal notes.
        $this->assertSame('Packing for November, Plant 2 packing', $rfq->title);
        $this->assertNull($rfq->description);
        $items = $rfq->items()->orderBy('line_no')->get();
        $this->assertSame(['Box 5-ply', 'BOPP tape', 'Stretch film'], $items->pluck('name')->all()); // same box combined
        $this->assertEquals(800, (float) $items[0]->qty);
        $this->assertSame('2026-10-15', $items[0]->delivery_date?->toDateString() ?? substr((string) $items[0]->delivery_date, 0, 10));
        $this->assertSame([PurchaseRequest::CONVERTED, PurchaseRequest::CONVERTED], PurchaseRequest::orderBy('id')->pluck('status')->all());
        $this->assertContains('Request in progress: PR-2026-0001', $this->titles($this->requester));
        $this->actingAs($this->buyerUser)->get(route('buyer.rfqs.show', $rfq->id))->assertOk()->assertSee('PR-2026-0001')->assertSee('PR-2026-0002');

        // Used once only.
        $this->actingAs($this->buyerUser)->post(route('buyer.requests.convert'), ['ids' => [$a->id]])->assertSessionHasErrors('ids');

        // RFQ cancelled: the requests go back to "approved".
        app(RfqService::class)->cancel($rfq, $this->buyerUser, 'Merged into another RFQ');
        $this->assertSame([PurchaseRequest::APPROVED, PurchaseRequest::APPROVED], PurchaseRequest::orderBy('id')->pluck('status')->all());
        $this->assertNull($a->fresh()->rfq_id);
        $this->assertContains('Request back with purchase: PR-2026-0001', $this->titles($this->requester));
    }

    public function test_requester_follows_it_to_po_and_delivery(): void
    {
        [$sup, $supUser] = $this->supplier('Alpha Packaging');
        $entry = BuyerSupplier::create(['buyer_org_id' => $this->buyer->id, 'company_name' => 'Alpha Packaging', 'status' => 'active',
            'contact_email' => $supUser->email, 'supplier_org_id' => $sup->id]);
        $this->raise($this->requester, ['items' => [['name' => 'Box 5-ply', 'qty' => '500', 'unit' => 'pcs']]]);
        $pr = PurchaseRequest::firstOrFail();
        $this->actingAs($this->approver)->post(route('buyer.requests.approve', $pr->id));
        $this->actingAs($this->buyerUser)->post(route('buyer.requests.convert'), ['ids' => [$pr->id]]);
        app(CurrentOrganization::class)->set(null);
        $this->assertSame('Purchase team preparing the RFQ', $pr->fresh()->progress()['label']);

        $rfq = Rfq::withoutGlobalScopes()->findOrFail($pr->fresh()->rfq_id);
        $rfq->update(['quote_deadline' => now()->addHours(3)]);
        $svc = app(RfqService::class);
        $svc->invite($rfq, $this->buyerUser, [$entry->id]);
        $svc->publish($rfq->fresh(), $this->buyerUser);
        app(CurrentOrganization::class)->set(null);
        $this->assertSame('Collecting quotes from suppliers', $pr->fresh()->progress()['label']);

        $invite = RfqInvite::where('rfq_id', $rfq->id)->firstOrFail();
        $this->actingAs($supUser)->post(route('supplier.rfqs.accept', $invite->id), ['agree' => 1]);
        $item = $rfq->items()->first();
        $this->actingAs($supUser)->post(route('supplier.rfqs.quote', $invite->id), [
            'items' => [$item->id => ['unit_price' => 40, 'gst_rate' => '18', 'freight' => 0]], 'valid_till' => now()->addDays(10)->toDateString(),
        ])->assertSessionHasNoErrors();
        app(CurrentOrganization::class)->set(null);
        $this->travel(4)->hours();
        $this->actingAs($this->buyerUser)->post(route('buyer.awards.store', $rfq->id), ['supplier_org_id' => $sup->id])->assertSessionHasNoErrors();
        app(CurrentOrganization::class)->set(null);
        $award = Award::withoutGlobalScopes()->where('rfq_id', $rfq->id)->firstOrFail();
        $this->actingAs($this->approver)->post(route('buyer.awards.approve', $award->id))->assertRedirect(); // company has an approver
        app(CurrentOrganization::class)->set(null);
        $award->refresh();

        $progress = $pr->fresh()->progress();
        $this->assertSame(['ordered', [$award->po_number]], [$progress['key'], $progress['pos']]);
        $this->assertContains("Ordered: {$pr->pr_number}", $this->titles($this->requester));

        // The requester sees the PO number but never the supplier or the price.
        $page = $this->actingAs($this->requester)->get(route('buyer.requests.show', $pr->id))->assertOk()->assertSee($award->po_number)->getContent();
        $this->assertStringNotContainsString('Alpha Packaging', $page);
        $this->assertStringNotContainsString('20,000', $page);
        app(CurrentOrganization::class)->set(null);

        $this->actingAs($supUser)->post(route('supplier.orders.accept', $award->id));
        $this->actingAs($this->buyerUser)->post(route('buyer.orders.receive', $award->id), [
            'received_on' => now()->setTimezone('Asia/Kolkata')->toDateString(), 'challan_no' => 'DC-1',
            'lines' => [$item->id => ['received' => 500]],
        ])->assertSessionHasNoErrors();
        app(CurrentOrganization::class)->set(null);
        $this->assertSame('received', $pr->fresh()->progress()['key']);
        $this->assertContains("Delivery received: {$pr->pr_number}", $this->titles($this->requester));
    }

    public function test_cancel_and_validation(): void
    {
        $this->raise($this->requester, ['items' => []])->assertSessionHasErrors('items');
        $this->raise($this->requester, ['needed_by' => '2026-10-01'])->assertSessionHasErrors('needed_by');
        $this->raise($this->requester);
        $pr = PurchaseRequest::firstOrFail();

        $this->actingAs($this->buyerUser)->post(route('buyer.requests.cancel', $pr->id))->assertForbidden();
        $this->actingAs($this->requester)->post(route('buyer.requests.cancel', $pr->id), ['reason' => 'Got it from Plant 1'])->assertSessionHasNoErrors();
        $this->assertSame(PurchaseRequest::CANCELLED, $pr->fresh()->status);
        $this->actingAs($this->approver)->post(route('buyer.requests.approve', $pr->id))->assertSessionHasErrors();

        // Team page offers the Requester role.
        $this->actingAs($this->admin)->get(route('team.index'))->assertOk()->assertSee('Requester')->assertSee('Sees no prices');
    }

    public function test_requester_cannot_listen_to_the_buyer_auction_board(): void
    {
        $this->assertTrue($this->admin->hasRoleIn($this->buyer->id, 'buyer_admin', 'buyer_user', 'approver'));
        $this->assertFalse($this->requester->hasRoleIn($this->buyer->id, 'buyer_admin', 'buyer_user', 'approver'));
        $this->assertStringContainsString("hasRoleIn(\$auction->organization_id, 'buyer_admin', 'buyer_user', 'approver')", file_get_contents(base_path('routes/channels.php')));
    }
}
