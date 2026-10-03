<?php

namespace Tests\Feature;

use App\Enums\OrgRole;
use App\Models\AuditLog;
use App\Models\Award;
use App\Models\BuyerSupplier;
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

/** Buyer PO register and the Tally / Excel exports. */
class TallyExportTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    private Organization $buyer;
    private User $buyerUser;
    /** @var array<string, array{0: Organization, 1: User}> */
    private array $s = [];
    private Carbon $t0;
    private string $diskRoot;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->diskRoot = sys_get_temp_dir().'/getl1-tally-'.bin2hex(random_bytes(6));
        Storage::set('local', Storage::createLocalDriver(['root' => $this->diskRoot, 'throw' => true]));
        $this->t0 = Carbon::parse('2026-10-05 04:30:00', 'UTC');
        $this->travelTo($this->t0);

        [$this->buyer, $this->buyerUser] = $this->buyer('Acme Buyers');
        $this->buyer->update(['gstin' => '06AAACA1234A1Z5', 'state' => 'Haryana']);
        foreach ([
            'A' => ['Alpha & Sons <Packaging>', '06AAPFU0939F1ZV', 'Haryana'],   // same state → CGST + SGST
            'B' => ['Beta Corrugators', '03AAGCB7383J1Z4', 'Punjab'],          // other state → IGST
        ] as $k => [$name, $gstin, $state]) {
            [$org, $user] = $this->supplier($name);
            $org->update(['gstin' => $gstin, 'state' => $state, 'address' => 'Plot 12, Industrial Area', 'city' => 'Mohali']);
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

    /** Publish an RFQ, collect quotes, pass the deadline and award L1. Returns the issued PO. */
    private function po(array $prices, string $winner, array $freight = [0, 0]): Award
    {
        $svc = app(RfqService::class);
        $rfq = $svc->saveDraft($this->buyer, $this->buyerUser, [
            'title' => 'Packing & tape',
            'quote_deadline' => now()->addHours(3)->setTimezone('Asia/Kolkata')->format('Y-m-d\TH:i'),
            'items' => [['name' => 'Box 5-ply', 'qty' => 1000, 'unit' => 'pcs'], ['name' => 'BOPP tape', 'qty' => 200, 'unit' => 'roll']],
            'terms' => ['freight' => 'extra'],
        ]);
        $svc->invite($rfq, $this->buyerUser, BuyerSupplier::where('buyer_org_id', $this->buyer->id)->pluck('id')->all());
        $svc->publish($rfq->fresh(), $this->buyerUser);
        app(CurrentOrganization::class)->set(null);
        $rfq = Rfq::withoutGlobalScopes()->find($rfq->id);

        foreach ($prices as $k => $p) {
            $invite = RfqInvite::where('rfq_id', $rfq->id)->where('supplier_org_id', $this->s[$k][0]->id)->firstOrFail();
            $this->actingAs($this->s[$k][1])->post(route('supplier.rfqs.accept', $invite->id), ['agree' => 1]);
            $items = [];
            foreach ($rfq->items()->orderBy('line_no')->get() as $i => $item) {
                $items[$item->id] = ['unit_price' => $p[$i], 'gst_rate' => $i === 0 ? '12' : '18', 'freight' => $freight[$i]];
            }
            $this->actingAs($this->s[$k][1])->post(route('supplier.rfqs.quote', $invite->id), ['items' => $items, 'valid_till' => now()->addDays(10)->toDateString()])->assertSessionHasNoErrors();
            app(CurrentOrganization::class)->set(null);
        }
        $this->travel(4)->hours();
        $this->actingAs($this->buyerUser)->post(route('buyer.awards.store', $rfq->id), ['supplier_org_id' => $this->s[$winner][0]->id])->assertSessionHasNoErrors();
        app(CurrentOrganization::class)->set(null);

        return Award::withoutGlobalScopes()->where('rfq_id', $rfq->id)->latest('id')->firstOrFail()->fresh();
    }

    private function xml(string $route, array $q = []): \SimpleXMLElement
    {
        $res = $this->actingAs($this->buyerUser)->get(route($route, $q))->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $xml = simplexml_load_string($res->getContent());
        $this->assertNotFalse($xml, 'Export is not well-formed XML');

        return $xml;
    }

    public function test_register_lists_issued_pos_with_totals(): void
    {
        $a = $this->po(['A' => [90, 20], 'B' => [95, 25]], 'A');
        $b = $this->po(['A' => [99, 30], 'B' => [85, 21]], 'B');

        $this->actingAs($this->buyerUser)->get(route('buyer.orders.index'))->assertOk()
            ->assertSee($a->po_number)->assertSee($b->po_number)->assertSee('Export to Tally')
            ->assertSee(\App\Support\Money::inr((float) $a->total + (float) $b->total));

        // Filters
        $this->actingAs($this->buyerUser)->get(route('buyer.orders.index', ['supplier' => $this->s['B'][0]->id]))->assertOk()
            ->assertSee($b->po_number)->assertDontSee($a->po_number);
        $this->actingAs($this->buyerUser)->get(route('buyer.orders.index', ['accepted' => 'yes']))->assertOk()->assertDontSee($a->po_number);

        // Other buyers never see them.
        [, $other] = $this->buyer('Other Buyer');
        $this->actingAs($other)->get(route('buyer.orders.index'))->assertOk()->assertDontSee($a->po_number);
        $this->actingAs($other)->get(route('buyer.orders.tally.vouchers'))->assertOk()->assertDontSee($a->po_number);
        // Suppliers can't open the buyer register.
        $this->actingAs($this->s['A'][1])->get(route('buyer.orders.index'))->assertForbidden();
    }

    public function test_masters_have_suppliers_units_and_items(): void
    {
        $this->po(['A' => [90, 20], 'B' => [95, 25]], 'A');
        $this->po(['A' => [99, 30], 'B' => [85, 21]], 'B');

        $xml = $this->xml('buyer.orders.tally.masters');
        $ledgers = $xml->xpath('//LEDGER');
        $this->assertCount(2, $ledgers);
        $alpha = $xml->xpath('//LEDGER[@NAME="Alpha & Sons <Packaging>"]')[0] ?? null;
        $this->assertNotNull($alpha, 'Special characters are escaped and survive the round trip');
        $this->assertSame('Sundry Creditors', (string) $alpha->PARENT);
        $this->assertSame('06AAPFU0939F1ZV', (string) $alpha->PARTYGSTIN);
        $this->assertSame('Haryana', (string) $alpha->LEDSTATENAME);
        $this->assertEqualsCanonicalizing(['pcs', 'roll'], array_map('strval', $xml->xpath('//UNIT/NAME')));
        $this->assertCount(2, $xml->xpath('//STOCKITEM'), 'Same item on two POs is created once');
    }

    public function test_vouchers_balance_and_split_gst_by_state(): void
    {
        $a = $this->po(['A' => [90, 20], 'B' => [95, 25]], 'A', [500, 0]);
        $b = $this->po(['A' => [99, 30], 'B' => [85.55, 21.35]], 'B');

        $xml = $this->xml('buyer.orders.tally.vouchers');
        $vouchers = $xml->xpath('//VOUCHER');
        $this->assertCount(2, $vouchers);

        foreach ($vouchers as $v) {
            $sum = 0.0;
            foreach ($v->{'LEDGERENTRIES.LIST'} as $e) {
                $sum += (float) $e->AMOUNT;
            }
            foreach ($v->{'ALLINVENTORYENTRIES.LIST'} as $e) {
                $sum += (float) $e->AMOUNT;
            }
            $this->assertEqualsWithDelta(0, $sum, 0.001, 'Voucher '.$v->VOUCHERNUMBER.' does not balance');
            $this->assertSame('Purchase Order', (string) $v->VOUCHERTYPENAME);
        }

        $va = $xml->xpath('//VOUCHER[VOUCHERNUMBER="'.$a->po_number.'"]')[0];
        $names = array_map('strval', $va->xpath('LEDGERENTRIES.LIST/LEDGERNAME'));
        $this->assertContains('CGST', $names);
        $this->assertContains('SGST', $names);
        $this->assertNotContains('IGST', $names);
        $this->assertContains('Freight Inward', $names);
        $this->assertEquals((float) $a->grand_total, (float) $va->xpath('LEDGERENTRIES.LIST[ISPARTYLEDGER="Yes"]/AMOUNT')[0]);
        $this->assertSame(' 1000 pcs', (string) $va->{'ALLINVENTORYENTRIES.LIST'}[0]->ACTUALQTY);
        $this->assertSame('90/pcs', (string) $va->{'ALLINVENTORYENTRIES.LIST'}[0]->RATE);
        $this->assertSame('20261005', (string) $va->DATE);

        $vb = $xml->xpath('//VOUCHER[VOUCHERNUMBER="'.$b->po_number.'"]')[0];
        $names = array_map('strval', $vb->xpath('LEDGERENTRIES.LIST/LEDGERNAME'));
        $this->assertContains('IGST', $names);
        $this->assertNotContains('CGST', $names);

        $this->assertSame(1, AuditLog::where('action', 'po_exported')->count());
    }

    public function test_ledger_names_are_the_buyers_own(): void
    {
        $a = $this->po(['A' => [90, 20], 'B' => [95, 25]], 'A');

        $this->actingAs($this->buyerUser)->post(route('buyer.orders.tally.settings'), ['purchase_ledger' => 'Purchase @ GST', 'cgst_ledger' => 'Input CGST', 'sgst_ledger' => 'Input SGST', 'company' => 'Acme Buyers 2026-27'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->buyerUser)->post(route('buyer.orders.tally.settings'), ['purchase_ledger' => 'Bad <ledger>'])->assertSessionHasErrors('purchase_ledger');
        $this->assertTrue(AuditLog::where('action', 'tally_settings_changed')->exists());

        $xml = $this->xml('buyer.orders.tally.vouchers');
        $this->assertSame('Acme Buyers 2026-27', (string) $xml->xpath('//SVCURRENTCOMPANY')[0]);
        $names = array_map('strval', $xml->xpath('//LEDGERENTRIES.LIST/LEDGERNAME'));
        $this->assertContains('Input CGST', $names);
        $this->assertSame('Purchase @ GST', (string) $xml->xpath('//ACCOUNTINGALLOCATIONS.LIST/LEDGERNAME')[0]);

        // Approvers can export but not change the names.
        $approver = $this->memberOf($this->buyer, OrgRole::Approver);
        $this->actingAs($approver)->get(route('buyer.orders.tally.masters'))->assertOk();
        $this->actingAs($approver)->post(route('buyer.orders.tally.settings'), ['purchase_ledger' => 'Mine'])->assertForbidden();
    }

    public function test_excel_register_has_a_row_per_line(): void
    {
        $a = $this->po(['A' => [90, 20], 'B' => [95, 25]], 'A');
        $csv = $this->actingAs($this->buyerUser)->get(route('buyer.orders.csv'))->assertOk()->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim(ltrim($csv, "\u{FEFF}")))));
        $this->assertCount(3, $rows); // header + 2 lines
        $this->assertSame($a->po_number, $rows[1][0]);
        $this->assertSame('Box 5-ply', $rows[1][7]);
        $this->assertSame('90000.00', $rows[1][11]);
        $this->assertSame('Alpha & Sons <Packaging>', $rows[1][4]);
        $this->assertTrue(AuditLog::where('action', 'po_exported')->where('after->format', 'excel')->exists());
    }
}
