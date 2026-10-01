<?php

namespace App\Services\Reports;

use App\Enums\AwardStatus;
use App\Models\Award;
use App\Models\Quote;
use App\Models\Rfq;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What GetL1 saved a buyer company, from finished awards only (approved / PO sent).
 *
 * Per award, before GST:
 * - vs sealed quotes: best sealed quote − awarded price (what the live auction or choice gained)
 * - vs last price:   last purchase price × qty − awarded price (only if every item has a last price)
 */
class SavingsReport
{
    public const PERIODS = [
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'last_3_months' => 'Last 3 months',
        'this_fy' => 'This financial year',
        'last_fy' => 'Last financial year',
        'all' => 'All time',
    ];

    /** @return array{0: ?Carbon, 1: ?Carbon} UTC range for a period, in Indian calendar terms */
    public static function range(string $period): array
    {
        $now = now()->setTimezone(config('app.display_timezone'));
        // Indian financial year: 1 April to 31 March.
        $fyStart = Carbon::create($now->month >= 4 ? $now->year : $now->year - 1, 4, 1, 0, 0, 0, $now->getTimezone());

        [$from, $to] = match ($period) {
            'this_month' => [$now->copy()->startOfMonth(), null],
            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->startOfMonth()],
            'last_3_months' => [$now->copy()->subMonthsNoOverflow(2)->startOfMonth(), null],
            'this_fy' => [$fyStart, null],
            'last_fy' => [$fyStart->copy()->subYear(), $fyStart],
            default => [null, null],
        };

        return [$from?->utc(), $to?->utc()];
    }

    public function build(int $orgId, string $period = 'this_fy'): array
    {
        [$from, $to] = self::range($period);

        $awards = Award::withoutGlobalScopes()->with(['supplier:id,name', 'auction'])
            ->where('organization_id', $orgId)
            ->whereIn('status', [AwardStatus::Approved->value, AwardStatus::PoSent->value])
            ->when($from, fn ($q) => $q->where('approved_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('approved_at', '<', $to))
            ->orderByDesc('approved_at')->get();

        $rfqs = Rfq::withoutGlobalScopes()->with(['items', 'category:id,name'])->whereIn('id', $awards->pluck('rfq_id'))->get()->keyBy('id');
        $quoteStats = Quote::whereIn('rfq_id', $awards->pluck('rfq_id'))->whereNotNull('submitted_at')
            ->selectRaw('rfq_id, MIN(total) as best, COUNT(*) as n')->groupBy('rfq_id')->get()->keyBy('rfq_id');

        $rows = $awards->map(function (Award $a) use ($rfqs, $quoteStats) {
            $rfq = $rfqs[$a->rfq_id] ?? null;
            $paid = (float) $a->total;
            $bestSealed = isset($quoteStats[$a->rfq_id]) ? (float) $quoteStats[$a->rfq_id]->best : null;
            $last = $rfq && $rfq->items->isNotEmpty() && $rfq->items->every(fn ($i) => $i->last_purchase_price !== null)
                ? round($rfq->items->sum(fn ($i) => (float) $i->last_purchase_price * (float) $i->qty), 2) : null;

            return [
                'award' => $a,
                'rfq' => $rfq,
                'category' => $rfq?->category?->name ?? 'Uncategorised',
                'supplier' => $a->supplier?->name,
                'source' => $a->source,
                'quotes' => (int) ($quoteStats[$a->rfq_id]->n ?? 0),
                'paid' => $paid,
                'best_sealed' => $bestSealed,
                'vs_sealed' => $bestSealed !== null ? round($bestSealed - $paid, 2) : null,
                'last_total' => $last,
                'vs_last' => $last !== null ? round($last - $paid, 2) : null,
            ];
        });

        $withLast = $rows->whereNotNull('vs_last');
        $sum = fn (Collection $c, string $k) => round($c->sum($k), 2);

        return [
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'totals' => [
                'awards' => $rows->count(),
                'auctions' => $rows->where('source', 'auction')->count(),
                'spend' => $sum($rows, 'paid'),
                'vs_sealed' => $sum($rows->whereNotNull('vs_sealed'), 'vs_sealed'),
                'vs_sealed_pct' => self::pct($sum($rows->whereNotNull('vs_sealed'), 'vs_sealed'), $sum($rows->whereNotNull('vs_sealed'), 'best_sealed')),
                'vs_last' => $sum($withLast, 'vs_last'),
                'vs_last_pct' => self::pct($sum($withLast, 'vs_last'), $sum($withLast, 'last_total')),
                'vs_last_count' => $withLast->count(),
                'avg_quotes' => $rows->count() ? round($rows->avg('quotes'), 1) : 0,
                'l1_rate' => $rows->count() ? round($rows->filter(fn ($r) => $r['award']->rank === 1)->count() / $rows->count() * 100) : 0,
            ],
            'by_category' => $rows->groupBy('category')->map(fn ($g, $name) => [
                'category' => $name,
                'awards' => $g->count(),
                'spend' => $sum($g, 'paid'),
                'vs_sealed' => $sum($g->whereNotNull('vs_sealed'), 'vs_sealed'),
                'vs_last' => $sum($g->whereNotNull('vs_last'), 'vs_last'),
            ])->sortByDesc('spend')->values(),
        ];
    }

    private static function pct(float $part, float $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : null;
    }
}
