<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\Billing\PlanService;
use App\Services\Reports\SavingsReport;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private CurrentOrganization $current, private SavingsReport $report, private PlanService $plans) {}

    public function savings(Request $request): View
    {
        $period = array_key_exists($request->query('period'), SavingsReport::PERIODS) ? $request->query('period') : 'this_fy';

        return view('buyer.reports.savings', [
            'r' => $this->report->build($this->current->id(), $period),
            'periods' => SavingsReport::PERIODS,
            // Free plan sees the headline numbers; the detail is part of paid plans.
            'locked' => ! $this->plans->hasFeature($this->current->get(), 'savings_report'),
        ]);
    }

    public function savingsCsv(Request $request, AuditLogger $audit): StreamedResponse
    {
        abort_unless($this->plans->hasFeature($this->current->get(), 'savings_report'), 403, 'The savings report export is part of paid plans.');
        $period = array_key_exists($request->query('period'), SavingsReport::PERIODS) ? $request->query('period') : 'this_fy';
        $r = $this->report->build($this->current->id(), $period);
        $audit->log('savings_report_exported', $this->current->get(), after: ['period' => $period]);

        $safe = fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v;
        $n = fn ($v) => $v === null ? '' : number_format((float) $v, 2, '.', '');

        return response()->streamDownload(function () use ($r, $safe, $n) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, ['Awarded on (IST)', 'RFQ', 'Title', 'Category', 'Supplier', 'How', 'Quotes', 'Awarded price (₹, before GST)',
                'Best sealed quote', 'Saved vs sealed', 'Last purchase value', 'Saved vs last price', 'PO']);
            foreach ($r['rows'] as $row) {
                fputcsv($out, array_map($safe, [
                    $row['award']->approved_at?->ist()->format('Y-m-d'), $row['rfq']?->ref_no, $row['rfq']?->title, $row['category'], $row['supplier'],
                    $row['source'] === 'auction' ? 'Live auction' : 'Sealed quotes', $row['quotes'], $n($row['paid']), $n($row['best_sealed']),
                    $n($row['vs_sealed']), $n($row['last_total']), $n($row['vs_last']), $row['award']->po_number,
                ]));
            }
            fclose($out);
        }, 'getl1-savings-'.$period.'-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
