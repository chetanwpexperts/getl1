<?php

namespace Tests\Feature;

use App\Enums\AwardStatus;
use App\Enums\RfqStatus;
use App\Models\Award;
use App\Models\Organization;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\RfqItem;
use App\Models\User;
use App\Services\Reports\SavingsReport;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class SavingsReportTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    private Organization $buyer;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        $this->travelTo(Carbon::parse('2026-10-05 04:30:00', 'UTC'));
        [$this->buyer, $this->admin] = $this->buyer('Acme Buyers');
    }

    /** A finished award: sealed quotes [best, other], awarded at $paid, last price per unit (qty 1000). */
    private function finished(float $best, float $paid, ?float $lastUnit, string $source = 'auction', int $rank = 1, ?Carbon $when = null): Award
    {
        $rfq = Rfq::create(['organization_id' => $this->buyer->id, 'created_by' => $this->admin->id, 'title' => 'Order '.uniqid(),
            'status' => RfqStatus::Awarded, 'quote_deadline' => now()->subDay(), 'published_at' => now()->subDays(2)]);
        RfqItem::create(['rfq_id' => $rfq->id, 'line_no' => 1, 'name' => 'Box', 'qty' => 1000, 'unit' => 'pcs', 'last_purchase_price' => $lastUnit]);
        [$s1, $u1] = $this->supplier('Winner '.uniqid());
        [$s2, $u2] = $this->supplier('Other '.uniqid());
        Quote::create(['rfq_id' => $rfq->id, 'supplier_org_id' => $s1->id, 'submitted_by' => $u1->id, 'total' => $best, 'submitted_at' => now()->subDays(2)]);
        Quote::create(['rfq_id' => $rfq->id, 'supplier_org_id' => $s2->id, 'submitted_by' => $u2->id, 'total' => $best + 5000, 'submitted_at' => now()->subDays(2)]);
        app(CurrentOrganization::class)->set(null);

        return Award::create(['rfq_id' => $rfq->id, 'organization_id' => $this->buyer->id, 'supplier_org_id' => $s1->id, 'source' => $source,
            'rank' => $rank, 'total' => $paid, 'status' => AwardStatus::PoSent, 'awarded_by' => $this->admin->id,
            'approved_at' => $when ?? now(), 'po_number' => 'PO-2026-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT)]);
    }

    public function test_totals_vs_sealed_and_vs_last_price(): void
    {
        $this->finished(100000, 92000, 110);          // auction: saved 8,000 vs sealed; 18,000 vs last (110,000)
        $this->finished(50000, 50000, null, 'quote'); // direct award to L1, no last price
        $this->finished(80000, 85000, 90, 'quote', 2); // not L1: -5,000 vs sealed; +5,000 vs last (90,000)
        Award::create(['rfq_id' => Rfq::first()->id, 'organization_id' => $this->buyer->id, 'supplier_org_id' => Organization::where('type', 'supplier')->first()->id,
            'total' => 1, 'status' => AwardStatus::PendingApproval, 'awarded_by' => $this->admin->id]); // pending: excluded

        $t = app(SavingsReport::class)->build($this->buyer->id, 'this_fy')['totals'];
        $this->assertSame(3, $t['awards']);
        $this->assertSame(1, $t['auctions']);
        $this->assertEquals(227000, $t['spend']);
        $this->assertEquals(3000, $t['vs_sealed']);          // 8000 + 0 - 5000
        $this->assertEquals(23000, $t['vs_last']);           // 18000 + 5000
        $this->assertSame(2, $t['vs_last_count']);
        $this->assertEquals(11.5, $t['vs_last_pct']);        // 23000 / 200000
        $this->assertEquals(67.0, $t['l1_rate']);
    }

    public function test_periods_follow_the_indian_financial_year(): void
    {
        $this->finished(100000, 90000, null, 'auction', 1, Carbon::parse('2026-03-20 06:00', 'UTC')); // FY 2025-26
        $this->finished(100000, 95000, null, 'auction', 1, Carbon::parse('2026-04-02 06:00', 'UTC')); // FY 2026-27

        $r = app(SavingsReport::class);
        $this->assertSame(1, $r->build($this->buyer->id, 'this_fy')['totals']['awards']);
        $this->assertSame(1, $r->build($this->buyer->id, 'last_fy')['totals']['awards']);
        $this->assertSame(2, $r->build($this->buyer->id, 'all')['totals']['awards']);
        $this->assertSame(0, $r->build($this->buyer->id, 'this_month')['totals']['awards']);
    }

    public function test_page_export_and_free_plan_teaser(): void
    {
        $this->finished(100000, 92000, 110);

        // Trial (Growth): full report and export.
        $this->actingAs($this->admin)->get(route('buyer.reports.savings'))->assertOk()->assertSee('₹18,000')->assertSee('Export (CSV)');
        $csv = $this->actingAs($this->admin)->get(route('buyer.reports.savings.csv', ['period' => 'all']))->assertOk()->streamedContent();
        $this->assertStringContainsString('92000.00', $csv);
        $this->assertStringContainsString('Live auction', $csv);
        $this->actingAs($this->admin)->get('/dashboard')->assertOk()->assertSee('Saved this year')->assertSee('this financial year');

        // Free plan: headline numbers, details behind an upgrade.
        $this->travel(15)->days();
        $this->actingAs($this->admin)->get(route('buyer.reports.savings'))->assertOk()
            ->assertSee('₹18,000')->assertSee('part of the Starter plan')->assertDontSee('Export (CSV)');
        $this->actingAs($this->admin)->get(route('buyer.reports.savings.csv'))->assertForbidden();

        // Other companies never see these numbers.
        [, $rival] = $this->buyer('Rival Industries');
        $this->actingAs($rival)->get(route('buyer.reports.savings'))->assertOk()->assertDontSee('₹18,000');
    }
}
