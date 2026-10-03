<?php

namespace Tests\Feature;

use App\Enums\OrgRole;
use App\Mail\GoodsReceivedMail;
use App\Mail\InvoiceDecisionMail;
use App\Mail\InvoiceSubmittedMail;
use App\Mail\PaymentsDueMail;
use App\Models\AuditLog;
use App\Models\Award;
use App\Models\BuyerSupplier;
use App\Models\GoodsReceipt;
use App\Models\Organization;
use App\Models\Rfq;
use App\Models\RfqInvite;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Services\Automations;
use App\Services\Payables\MsmeDueDate;
use App\Services\RfqService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

/** Goods receipts, supplier invoices with 3-way checks, payments and the MSME 45-day rule. */
class PayablesTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    private Organization $buyer;
    private User $buyerUser;
    /** @var array<string, array{0: Organization, 1: User}> */
    private array $s = [];
    private string $diskRoot;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->diskRoot = sys_get_temp_dir().'/getl1-payables-'.bin2hex(random_bytes(6));
        Storage::set('local', Storage::createLocalDriver(['root' => $this->diskRoot, 'throw' => true]));
        $this->travelTo(Carbon::parse('2026-10-05 04:30:00', 'UTC')); // 10:00 IST

        [$this->buyer, $this->buyerUser] = $this->buyer('Acme Buyers');
        $this->buyer->update(['gstin' => '06AAACA1234A1ZO', 'state' => 'Haryana']);
        foreach ([
            'A' => ['Alpha Packaging', '06AAPFU0939F1ZZ', 'UDYAM-HR-05-0012345'], // MSME (Udyam)
            'B' => ['Beta Corrugators', '03AAGCB7383J1ZI', null],
        ] as $k => [$name, $gstin, $udyam]) {
            [$org, $user] = $this->supplier($name);
            $org->update(['gstin' => $gstin, 'udyam_no' => $udyam, 'state' => 'Haryana']);
            BuyerSupplier::create(['buyer_org_id' => $this->buyer->id, 'company_name' => $name, 'status' => 'active',
                'contact_email' => $user->email, 'supplier_org_id' => $org->id]);
            $this->s[$k] = [$org, $user];
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->diskRoot);
        parent::tearDown();
    }

    /** Box 1000 pcs + tape 200 rolls, 18% GST; awarded to $winner. A quotes 90/20 (94,000), B 95/25. */
    private function po(string $winner = 'A', ?string $payment = 'credit_60'): Award
    {
        $svc = app(RfqService::class);
        $rfq = $svc->saveDraft($this->buyer, $this->buyerUser, [
            'title' => 'Packing',
            'quote_deadline' => now()->addHours(3)->setTimezone('Asia/Kolkata')->format('Y-m-d\TH:i'),
            'items' => [['name' => 'Box 5-ply', 'qty' => 1000, 'unit' => 'pcs'], ['name' => 'BOPP tape', 'qty' => 200, 'unit' => 'roll']],
            'terms' => array_filter(['payment' => $payment, 'freight' => 'included']),
        ]);
        $svc->invite($rfq, $this->buyerUser, BuyerSupplier::where('buyer_org_id', $this->buyer->id)->pluck('id')->all());
        $svc->publish($rfq->fresh(), $this->buyerUser);
        app(CurrentOrganization::class)->set(null);
        $rfq = Rfq::withoutGlobalScopes()->find($rfq->id);
        foreach (['A' => [90, 20], 'B' => [95, 25]] as $k => $p) {
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
        $this->actingAs($this->buyerUser)->post(route('buyer.awards.store', $rfq->id), ['supplier_org_id' => $this->s[$winner][0]->id, 'reason' => $winner === 'A' ? null : 'Closer to our plant'])->assertSessionHasNoErrors();
        app(CurrentOrganization::class)->set(null);

        return Award::withoutGlobalScopes()->where('rfq_id', $rfq->id)->firstOrFail()->fresh();
    }

    private function items(Award $a): array
    {
        $ids = collect($a->lines['items'])->pluck('rfq_item_id')->all();

        return ['box' => $ids[0], 'tape' => $ids[1]];
    }

    private function receive(Award $a, array $lines, ?string $date = null, ?User $as = null): \Illuminate\Testing\TestResponse
    {
        $ids = $this->items($a);
        $payload = [];
        foreach ($lines as $item => $l) {
            $payload[$ids[$item]] = $l;
        }

        return $this->actingAs($as ?? $this->buyerUser)->post(route('buyer.orders.receive', $a->id), [
            'received_on' => $date ?? now()->setTimezone('Asia/Kolkata')->toDateString(), 'challan_no' => 'DC-101', 'lines' => $payload,
        ]);
    }

    private function accept(string $k, Award $a): void
    {
        $this->actingAs($this->s[$k][1])->post(route('supplier.orders.accept', $a->id))->assertRedirect();
    }

    private function invoice(string $k, Award $a, array $o = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->s[$k][1])->post(route('supplier.orders.invoices.store', $a->id), array_merge([
            'invoice_number' => 'INV/26-27/045',
            'invoice_date' => now()->setTimezone('Asia/Kolkata')->toDateString(),
            'taxable_amount' => '49500', 'gst_amount' => '8910', 'total_amount' => '58410',
            'supplier_gstin' => $this->s[$k][0]->gstin,
            'file' => UploadedFile::fake()->createWithContent('invoice.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n"),
        ], $o));
    }

    public function test_goods_receipt_records_part_deliveries_with_rules(): void
    {
        $a = $this->po();
        $this->actingAs($this->buyerUser)->get(route('buyer.orders.show', $a->id))->assertOk()->assertSee('Record a delivery')->assertSee('MSME supplier');

        $this->receive($a, ['box' => ['received' => 600, 'rejected' => 50]])->assertSessionHasErrors('lines.'.$this->items($a)['box'].'.reason');
        $this->receive($a, ['box' => ['received' => 600, 'rejected' => 50, 'reason' => 'Crushed corners']])->assertSessionHasNoErrors();
        $grn = GoodsReceipt::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('GRN-2026-0001', $grn->grn_number);
        $this->assertEquals(550, $grn->lines[0]['accepted']);
        Mail::assertQueued(GoodsReceivedMail::class, fn ($m) => $m->hasTo($this->s['A'][1]->email));
        $this->assertStringContainsString('Crushed corners', (new GoodsReceivedMail($grn))->render());

        // Only 450 is still pending: accepting 500 more is refused; a future date too.
        $this->receive($a, ['box' => ['received' => 500]])->assertSessionHasErrors();
        $this->receive($a, ['tape' => ['received' => 10]], now()->addDays(2)->toDateString())->assertSessionHasErrors('received_on');
        $this->receive($a, [])->assertSessionHasErrors();

        // The supplier sees what was accepted and rejected.
        $this->actingAs($this->s['A'][1])->get(route('supplier.orders.show', $a->id))->assertOk()->assertSee('Crushed corners')->assertSee('550');

        // Approvers can't record receipts; other companies can't see the PO.
        $approver = $this->memberOf($this->buyer, OrgRole::Approver);
        $this->receive($a, ['tape' => ['received' => 10]], null, $approver)->assertForbidden();
        [, $other] = $this->buyer('Other Buyer');
        $this->actingAs($other)->get(route('buyer.orders.show', $a->id))->assertNotFound();
        $this->assertTrue(AuditLog::where('action', 'grn_recorded')->exists());
    }

    public function test_supplier_uploads_invoice_with_msme_due_date_and_checks(): void
    {
        $a = $this->po();
        $this->invoice('A', $a)->assertSessionHasErrors('invoice_number'); // accept the PO first
        $this->accept('A', $a);
        $this->travel(3)->days();
        $this->receive($a, ['box' => ['received' => 550]], now()->subDays(2)->setTimezone('Asia/Kolkata')->toDateString())->assertSessionHasNoErrors();

        $this->invoice('A', $a)->assertSessionHasNoErrors()->assertRedirect();
        $inv = SupplierInvoice::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('submitted', $inv->status);
        $this->assertTrue($inv->is_msme);
        // Agreed 60 days is capped at 45, counted from the goods receipt (2 days before the invoice).
        $this->assertSame(now()->setTimezone('Asia/Kolkata')->subDays(2)->addDays(45)->toDateString(), $inv->due_date->toDateString());
        $this->assertStringContainsString('capped at 45', $inv->due_basis);
        Mail::assertQueued(InvoiceSubmittedMail::class, fn ($m) => $m->hasTo($this->buyerUser->email));
        $this->assertStringContainsString('INV/26-27/045', (new InvoiceSubmittedMail($inv))->render());

        $checks = collect(\App\Services\Payables\InvoiceService::checks($inv));
        $this->assertTrue($checks->every(fn ($c) => $c['ok']), json_encode($checks));

        // Same number again, over the PO value, wrong total, bad file: refused.
        $this->invoice('A', $a)->assertSessionHasErrors('invoice_number');
        $this->invoice('A', $a, ['invoice_number' => 'INV-2', 'taxable_amount' => '90000', 'gst_amount' => '16200', 'total_amount' => '106200'])->assertSessionHasErrors('taxable_amount');
        $this->invoice('A', $a, ['invoice_number' => 'INV-3', 'total_amount' => '60000'])->assertSessionHasErrors('total_amount');
        $this->invoice('A', $a, ['invoice_number' => 'INV-4', 'file' => UploadedFile::fake()->createWithContent('x.pdf', '<?php echo 1;')])->assertSessionHasErrors();
        $this->assertSame(1, SupplierInvoice::withoutGlobalScopes()->count());
        $this->assertCount(1, Storage::disk('local')->allFiles('invoices'), 'Refused uploads leave no file behind');

        // Buyer sees it on the PO page with the file.
        $this->actingAs($this->buyerUser)->get(route('buyer.orders.show', $a->id))->assertOk()->assertSee('INV/26-27/045')->assertSee('Pay by');
        $this->actingAs($this->buyerUser)->get(route('buyer.invoices.file', $inv->id))->assertOk();
    }

    public function test_failed_checks_need_a_note_to_approve(): void
    {
        $a = $this->po();
        $this->accept('A', $a);
        // No goods received yet, and GST doesn't match 18%.
        $this->invoice('A', $a, ['gst_amount' => '2475', 'total_amount' => '51975'])->assertSessionHasNoErrors();
        $inv = SupplierInvoice::withoutGlobalScopes()->firstOrFail();
        $checks = collect(\App\Services\Payables\InvoiceService::checks($inv))->keyBy('key');
        $this->assertFalse($checks['received']['ok']);
        $this->assertFalse($checks['gst']['ok']);
        $this->assertTrue($checks['po']['ok']);

        $this->actingAs($this->buyerUser)->post(route('buyer.invoices.review', $inv->id), ['decision' => 'approve'])->assertSessionHasErrors('review_note');
        $this->actingAs($this->buyerUser)->post(route('buyer.invoices.review', $inv->id), ['decision' => 'approve', 'review_note' => 'Goods arrived, GRN to follow; GST checked with CA'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $inv->fresh()->status);
        Mail::assertQueued(InvoiceDecisionMail::class, fn ($m) => $m->hasTo($this->s['A'][1]->email));
        $this->actingAs($this->buyerUser)->post(route('buyer.invoices.review', $inv->id), ['decision' => 'dispute', 'review_note' => 'Too late now'])->assertSessionHasErrors('review_note');
    }

    public function test_dispute_correct_and_pay(): void
    {
        $a = $this->po();
        $this->accept('A', $a);
        $this->receive($a, ['box' => ['received' => 550]]);
        $this->invoice('A', $a, ['taxable_amount' => '49000', 'gst_amount' => '8820', 'total_amount' => '57820']);
        $bad = SupplierInvoice::withoutGlobalScopes()->firstOrFail();
        $this->actingAs($this->buyerUser)->post(route('buyer.invoices.review', $bad->id), ['decision' => 'dispute'])->assertSessionHasErrors('review_note');
        $this->actingAs($this->buyerUser)->post(route('buyer.invoices.review', $bad->id), ['decision' => 'dispute', 'review_note' => 'Rate should be 90 per box'])->assertSessionHasNoErrors();
        $this->actingAs($this->s['A'][1])->get(route('supplier.orders.show', $a->id))->assertSee('Rate should be 90 per box')->assertSee('Needs correction');

        // A corrected invoice (new number) is accepted; the disputed one doesn't count towards the PO value.
        $this->invoice('A', $a, ['invoice_number' => 'INV/26-27/046'])->assertSessionHasNoErrors();
        $good = SupplierInvoice::withoutGlobalScopes()->where('invoice_number', 'INV/26-27/046')->firstOrFail();
        $this->actingAs($this->buyerUser)->post(route('buyer.invoices.paid', $good->id), ['paid_on' => now()->toDateString(), 'paid_amount' => '58410'])->assertSessionHasErrors('paid_on'); // not approved yet
        $this->actingAs($this->buyerUser)->post(route('buyer.invoices.review', $good->id), ['decision' => 'approve']);

        // Paid with TDS deducted, after the due date.
        $this->travelTo($good->fresh()->due_date->copy()->addDays(3)->setTime(6, 0));
        $this->actingAs($this->buyerUser)->post(route('buyer.invoices.paid', $good->id), [
            'paid_on' => now()->setTimezone('Asia/Kolkata')->toDateString(), 'paid_amount' => '58360.50', 'payment_ref' => 'UTR123456',
        ])->assertSessionHasNoErrors();
        $good->refresh();
        $this->assertSame('paid', $good->status);
        $this->assertEquals(58360.50, (float) $good->paid_amount);
        $this->assertTrue(AuditLog::where('action', 'invoice_paid')->where('after->late', true)->exists());
        $this->assertStringContainsString('TDS', (new InvoiceDecisionMail($good))->render());

        // Live alerts on both sides at each step.
        $supplier = $this->s['A'][1]->fresh()->notifications()->get()->pluck('data.title')->all();
        $this->assertContains("Goods received: {$a->po_number}", $supplier);
        $this->assertContains('Invoice disputed: INV/26-27/045', $supplier);
        $this->assertContains('Invoice approved: INV/26-27/046', $supplier);
        $this->assertContains('Payment recorded: INV/26-27/046', $supplier);
        $this->assertSame(2, collect($this->buyerUser->fresh()->notifications()->get()->pluck('data.title')->all())->filter(fn ($x) => $x === 'New invoice to review')->count());
    }

    public function test_due_date_rules(): void
    {
        $a = $this->po('A', null);  // MSME, no credit period agreed → 15 days
        $d = MsmeDueDate::for($a, Carbon::parse('2026-11-01'), null);
        $this->assertTrue($d['is_msme']);
        $this->assertSame('2026-11-16', $d['due_date']->toDateString());

        $b = $this->po('B', 'credit_30'); // not MSME → 30 days from invoice date, whatever the receipt date
        $d = MsmeDueDate::for($b, Carbon::parse('2026-11-10'), Carbon::parse('2026-11-01'));
        $this->assertFalse($d['is_msme']);
        $this->assertSame('2026-12-10', $d['due_date']->toDateString());

        // The buyer can mark B as MSME in its supplier list: then 30 days from the (earlier) receipt.
        BuyerSupplier::where('buyer_org_id', $this->buyer->id)->where('supplier_org_id', $this->s['B'][0]->id)->update(['is_msme' => true]);
        $d = MsmeDueDate::for($b, Carbon::parse('2026-11-10'), Carbon::parse('2026-11-01'));
        $this->assertTrue($d['is_msme']);
        $this->assertSame('2026-12-01', $d['due_date']->toDateString());
    }

    public function test_payments_page_badge_and_daily_reminder(): void
    {
        $a = $this->po('A', 'credit_15');
        $this->accept('A', $a);
        $this->receive($a, ['box' => ['received' => 550]]);
        $this->invoice('A', $a);
        $inv = SupplierInvoice::withoutGlobalScopes()->firstOrFail();
        $this->actingAs($this->buyerUser)->post(route('buyer.invoices.review', $inv->id), ['decision' => 'approve']);

        $this->actingAs($this->buyerUser)->get(route('buyer.payments.index'))->assertOk()->assertSee('INV/26-27/045')->assertSee('MSME');

        // 10 days later it's within the 7-day window: one digest per day to the buyer admins.
        $this->travelTo($inv->due_date->copy()->subDays(5)->setTimezone('Asia/Kolkata')->setTime(9, 30));
        $this->actingAs($this->buyerUser)->get(route('dashboard'))->assertOk()->assertSee('Payments');
        $this->assertGreaterThan(0, app(Automations::class)->remindMsmePayments());
        Mail::assertQueued(PaymentsDueMail::class, fn ($m) => $m->hasTo($this->buyerUser->email));
        $this->assertSame(0, app(Automations::class)->remindMsmePayments(), 'Once a day only');

        // Overdue: the page warns about the 45-day rule.
        $this->travelTo($inv->due_date->copy()->addDays(2)->setTimezone('Asia/Kolkata')->setTime(10, 0));
        $this->actingAs($this->buyerUser)->get(route('buyer.payments.index'))->assertSee('overdue')->assertSee('43B(h)');
        $this->assertStringContainsString('overdue', (new PaymentsDueMail($this->buyer, collect([$inv->fresh()])))->render());
    }

    public function test_suppliers_only_reach_their_own_orders_and_invoices(): void
    {
        $a = $this->po();
        $this->accept('A', $a);
        $this->invoice('A', $a);
        $inv = SupplierInvoice::withoutGlobalScopes()->firstOrFail();

        $this->invoice('B', $a, ['invoice_number' => 'B-1'])->assertNotFound();
        $this->actingAs($this->s['B'][1])->get(route('supplier.orders.invoices.file', [$a->id, $inv->id]))->assertNotFound();
        $this->actingAs($this->s['A'][1])->get(route('supplier.orders.invoices.file', [$a->id, $inv->id]))->assertOk();
        [, $other] = $this->buyer('Other Buyer');
        $this->actingAs($other)->get(route('buyer.invoices.file', $inv->id))->assertNotFound();
        $this->actingAs($this->s['A'][1])->get(route('buyer.payments.index'))->assertForbidden();
    }

    public function test_review_fixes_dates_numbers_and_digest(): void
    {
        $a = $this->po('A', 'on_delivery'); // MSME, pay on delivery → due on acceptance day
        $this->accept('A', $a);
        $this->receive($a, ['box' => ['received' => 550]]);
        $this->invoice('A', $a)->assertSessionHasNoErrors();
        $inv = SupplierInvoice::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($inv->invoice_date->toDateString(), $inv->due_date->toDateString());

        // Disputed: the same number can be used again for the corrected invoice.
        $this->actingAs($this->buyerUser)->post(route('buyer.invoices.review', $inv->id), ['decision' => 'dispute', 'review_note' => 'Wrong GST split']);
        $this->invoice('A', $a)->assertSessionHasNoErrors();
        $fixed = SupplierInvoice::withoutGlobalScopes()->where('status', 'submitted')->firstOrFail();
        $this->actingAs($this->buyerUser)->post(route('buyer.invoices.review', $fixed->id), ['decision' => 'approve']);

        // Paid on the invoice date itself is fine (IST vs UTC dates).
        $this->actingAs($this->buyerUser)->post(route('buyer.invoices.paid', $fixed->id), [
            'paid_on' => $fixed->invoice_date->toDateString(), 'paid_amount' => '58410',
        ])->assertSessionHasNoErrors();

        // Next financial year the supplier may restart its numbering.
        $b = $this->po('A', 'credit_30');
        $this->accept('A', $b);
        $this->travelTo(Carbon::parse('2027-04-02 05:00:00', 'UTC'));
        $this->invoice('A', $b)->assertSessionHasNoErrors();
        $this->invoice('A', $b)->assertSessionHasErrors('invoice_number'); // but not twice in the same year
    }

    public function test_first_overdue_day_counts_as_overdue_and_one_digest_a_day(): void
    {
        $a = $this->po('A', 'credit_15');
        $this->accept('A', $a);
        $this->receive($a, ['box' => ['received' => 550]]);
        $this->invoice('A', $a);
        $inv = SupplierInvoice::withoutGlobalScopes()->firstOrFail();

        // 10:00 IST on the day after the due date.
        $this->travelTo(Carbon::parse($inv->due_date->toDateString().' 10:00', 'Asia/Kolkata')->addDay());
        $this->assertSame(-1, $inv->fresh()->daysLeft());
        $this->assertTrue($inv->fresh()->isOverdue());

        $this->assertGreaterThan(0, app(Automations::class)->remindMsmePayments());
        // A new MSME invoice later the same day doesn't trigger a second digest.
        $this->invoice('A', $a, ['invoice_number' => 'INV/26-27/099', 'taxable_amount' => '10', 'gst_amount' => '1.80', 'total_amount' => '11.80']);
        $this->assertSame(0, app(Automations::class)->remindMsmePayments());
    }

    public function test_backdated_receipt_does_not_move_the_due_date(): void
    {
        $a = $this->po('A', 'credit_30');
        $this->accept('A', $a);
        $this->travel(10)->days();
        $this->receive($a, ['box' => ['received' => 300]], now()->subDays(2)->setTimezone('Asia/Kolkata')->toDateString());
        $this->invoice('A', $a, ['taxable_amount' => '27000', 'gst_amount' => '4860', 'total_amount' => '31860']);
        $inv = SupplierInvoice::withoutGlobalScopes()->firstOrFail();
        $before = $inv->due_date->toDateString();
        // A delivery from earlier, entered late, mustn't pull the due date earlier.
        $this->receive($a, ['box' => ['received' => 100]], now()->subDays(8)->setTimezone('Asia/Kolkata')->toDateString())->assertSessionHasNoErrors();
        $this->assertSame($before, $inv->fresh()->due_date->toDateString());
    }

    public function test_marking_msme_later_reworks_unpaid_due_dates(): void
    {
        $b = $this->po('B', 'credit_60');   // B is not MSME: 60 days from invoice date
        $this->accept('B', $b);
        $this->invoice('B', $b);
        $inv = SupplierInvoice::withoutGlobalScopes()->firstOrFail();
        $this->assertFalse($inv->is_msme);
        $this->assertSame($inv->invoice_date->copy()->addDays(60)->toDateString(), $inv->due_date->toDateString());

        // The buyer ticks MSME in its supplier list: capped at 45 days.
        $entry = BuyerSupplier::where('buyer_org_id', $this->buyer->id)->where('supplier_org_id', $this->s['B'][0]->id)->firstOrFail();
        $this->actingAs($this->buyerUser)->put(route('buyer.suppliers.update', $entry->id), [
            'company_name' => 'Beta Corrugators', 'contact_email' => $entry->contact_email, 'is_msme' => '1',
        ])->assertSessionHasNoErrors();
        $inv->refresh();
        $this->assertTrue($inv->is_msme);
        $this->assertSame($inv->invoice_date->copy()->addDays(45)->toDateString(), $inv->due_date->toDateString());
    }
}
