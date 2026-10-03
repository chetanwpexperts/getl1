<?php

namespace Tests\Feature;

use App\Models\Award;
use App\Models\BuyerSupplier;
use App\Models\Organization;
use App\Models\Rfq;
use App\Models\RfqInvite;
use App\Models\User;
use App\Services\RfqService;
use App\Services\Suppliers\SupplierProfile;
use App\Support\IndianIds;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

/** Supplier page: GST/PAN checks and the scorecard from the buyer's own orders. */
class SupplierProfileTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    private Organization $buyer;
    private User $buyerUser;
    /** @var array<string, array{0: Organization, 1: User, 2: BuyerSupplier}> */
    private array $s = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-10-05 04:30:00', 'UTC'));
        [$this->buyer, $this->buyerUser] = $this->buyer('Acme Buyers');
        foreach (['A' => 'Alpha Packaging', 'B' => 'Beta Corrugators'] as $k => $name) {
            [$org, $user] = $this->supplier($name);
            $entry = BuyerSupplier::create(['buyer_org_id' => $this->buyer->id, 'company_name' => $name, 'status' => 'active',
                'contact_email' => $user->email, 'supplier_org_id' => $org->id]);
            $this->s[$k] = [$org, $user, $entry];
        }
    }

    public function test_registration_checks(): void
    {
        $this->assertSame('Haryana', IndianIds::gstinState('06AAPFU0939F1ZZ'));
        $this->assertTrue(IndianIds::sameState('NCT of Delhi', 'delhi'));
        $this->assertTrue(IndianIds::sameState('Jammu & Kashmir', 'Jammu and Kashmir'));

        $org = $this->s['A'][0];
        $status = fn () => collect(SupplierProfile::checks($org->fresh()))->pluck('status', 'key')->all();

        $this->assertSame(['gstin' => 'missing', 'pan' => 'missing', 'kyc' => 'missing'], $status());

        $org->update(['gstin' => '06AAPFU0939F1ZZ', 'state' => 'Haryana']);
        $this->assertSame(['gstin' => 'ok', 'state' => 'ok', 'pan' => 'ok', 'kyc' => 'missing'], $status());

        $org->update(['state' => 'Punjab', 'pan' => 'ABCPE1234F', 'udyam_no' => 'UDYAM-HR-05-0012345', 'verified_at' => now()]);
        $this->assertSame(['gstin' => 'ok', 'state' => 'warn', 'pan' => 'warn', 'udyam' => 'ok', 'kyc' => 'ok'], $status());
        $this->assertSame('warn', SupplierProfile::checksSummary(SupplierProfile::checks($org->fresh())));

        $org->update(['gstin' => '06AAPFU0939F1ZA']); // wrong check digit
        $this->assertSame('warn', $status()['gstin']);
    }

    /** One PO to A with a due date; returns the award. */
    private function po(string $neededBy): Award
    {
        $svc = app(RfqService::class);
        $rfq = $svc->saveDraft($this->buyer, $this->buyerUser, [
            'title' => 'Boxes', 'quote_deadline' => now()->addHours(3)->setTimezone('Asia/Kolkata')->format('Y-m-d\TH:i'),
            'items' => [['name' => 'Box', 'qty' => 100, 'unit' => 'pcs', 'delivery_date' => $neededBy]],
            'terms' => ['payment' => 'credit_30', 'freight' => 'included'],
        ]);
        $svc->invite($rfq, $this->buyerUser, [$this->s['A'][2]->id, $this->s['B'][2]->id]);
        $svc->publish($rfq->fresh(), $this->buyerUser);
        app(CurrentOrganization::class)->set(null);
        $rfq = Rfq::withoutGlobalScopes()->find($rfq->id);
        foreach (['A' => 50, 'B' => 60] as $k => $price) {
            $invite = RfqInvite::where('rfq_id', $rfq->id)->where('supplier_org_id', $this->s[$k][0]->id)->firstOrFail();
            $this->actingAs($this->s[$k][1])->post(route('supplier.rfqs.accept', $invite->id), ['agree' => 1]);
            if ($k === 'B' && $price === 60 && $rfq->id % 2 === 0) {
                app(CurrentOrganization::class)->set(null);
                continue; // B skips every other RFQ
            }
            $item = $rfq->items()->first();
            $this->actingAs($this->s[$k][1])->post(route('supplier.rfqs.quote', $invite->id), [
                'items' => [$item->id => ['unit_price' => $price, 'gst_rate' => '18', 'freight' => 0]], 'valid_till' => now()->addDays(10)->toDateString(),
            ])->assertSessionHasNoErrors();
            app(CurrentOrganization::class)->set(null);
        }
        $this->travel(4)->hours();
        $this->actingAs($this->buyerUser)->post(route('buyer.awards.store', $rfq->id), ['supplier_org_id' => $this->s['A'][0]->id])->assertSessionHasNoErrors();
        app(CurrentOrganization::class)->set(null);

        return Award::withoutGlobalScopes()->where('rfq_id', $rfq->id)->firstOrFail()->fresh();
    }

    private function receive(Award $a, int $received, int $rejected = 0): void
    {
        $item = collect($a->lines['items'])->first()['rfq_item_id'];
        $this->actingAs($this->buyerUser)->post(route('buyer.orders.receive', $a->id), [
            'received_on' => now()->setTimezone('Asia/Kolkata')->toDateString(),
            'lines' => [$item => ['received' => $received, 'rejected' => $rejected, 'reason' => $rejected ? 'Wet cartons' : null]],
        ])->assertSessionHasNoErrors();
        app(CurrentOrganization::class)->set(null);
    }

    public function test_scorecard_from_deliveries_quality_quotes_and_po_acceptance(): void
    {
        $this->assertNull(SupplierProfile::score($this->buyer->id, $this->s['A'][0]->id)['score']); // nothing yet

        // PO 1: accepted in 2 hours, delivered in full on time with 10% rejected.
        $p1 = $this->po('2026-10-12');
        $this->travel(2)->hours();
        $this->actingAs($this->s['A'][1])->post(route('supplier.orders.accept', $p1->id));
        $this->receive($p1, 100, 10);
        $this->receive($p1, 10);        // the 10 rejected are replaced the same day: delivered in full
        // PO 2: due on the 15th, still not delivered on the 20th: late.
        $this->po('2026-10-15');
        $this->travelTo(Carbon::parse('2026-10-20 06:00:00', 'UTC'));

        $s = SupplierProfile::score($this->buyer->id, $this->s['A'][0]->id);
        $this->assertSame(2, $s['pos']);
        $this->assertSame(50, $s['parts']['delivery']['score']);      // 1 of 2 on time
        $this->assertSame(55, $s['parts']['quality']['score']);       // 10 of 110 rejected (9.1%) → 100 − 45
        $this->assertSame(100, $s['parts']['response']['score']);     // quoted on both
        $this->assertSame(100, $s['parts']['acceptance']['score']);   // within a day
        $this->assertNull($s['parts']['invoices']['score']);
        // (50×35 + 50×30 + 100×15 + 100×10) / 90
        $this->assertSame((int) round((50 * 35 + 55 * 30 + 100 * 15 + 100 * 10) / 90), $s['score']);
        $this->assertSame('Fair', $s['grade']);

        // Pages: list badge, supplier page, comparison.
        $this->actingAs($this->buyerUser)->get(route('buyer.suppliers.index'))->assertOk()->assertSee((string) $s['score'])->assertSee('Fair');
        $this->get(route('buyer.suppliers.show', $this->s['A'][2]->id))->assertOk()
            ->assertSee('Scorecard')->assertSee('1 of 2 POs delivered in full by the date needed')->assertSee('9.1% of goods received were rejected')
            ->assertSee($p1->po_number);
    }

    public function test_scorecard_is_private_to_each_buyer(): void
    {
        $this->po('2026-10-12');
        [$other, $otherUser] = $this->buyer('Other Buyer');
        $theirs = BuyerSupplier::create(['buyer_org_id' => $other->id, 'company_name' => 'Alpha Packaging', 'status' => 'active',
            'contact_email' => 'x@alpha.test', 'supplier_org_id' => $this->s['A'][0]->id]);

        // The other buyer has no orders with A: no score, and can't open the first buyer's entry.
        $this->assertNull(SupplierProfile::score($other->id, $this->s['A'][0]->id)['score']);
        $this->actingAs($otherUser)->get(route('buyer.suppliers.show', $this->s['A'][2]->id))->assertNotFound();
        $this->actingAs($otherUser)->get(route('buyer.suppliers.show', $theirs->id))->assertOk()->assertSee('nothing to score yet');
        $this->actingAs($this->s['A'][1])->get(route('buyer.suppliers.show', $this->s['A'][2]->id))->assertForbidden();
    }
}
