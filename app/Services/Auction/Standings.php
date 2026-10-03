<?php

namespace App\Services\Auction;

use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Models\Bid;
use Illuminate\Support\Collection;

/**
 * Live standings: each supplier's current price is their latest bid (sealed quote or live bid).
 * Ranked by price, then by who reached that price first (earlier wins ties).
 *
 * Item-wise auctions: every RFQ line is ranked on its own (byItem). for() then returns each
 * supplier's lot total (its current unit prices × quantities), so overviews, the admin monitor
 * and the "award everything to one supplier" view keep working.
 */
class Standings
{
    /**
     * @return Collection<int, array{supplier_org_id:int, amount:float, at:\Carbon\CarbonInterface, bids:int, rank:int}>
     */
    public static function for(Auction $auction): Collection
    {
        if ($auction->isPerItem()) {
            return self::lotTotals($auction);
        }

        $bids = Bid::where('auction_id', $auction->id)
            ->orderBy('created_at')->orderBy('id')
            ->get(['id', 'supplier_org_id', 'amount', 'kind', 'created_at']);

        return $bids->groupBy('supplier_org_id')
            ->map(function (Collection $rows, $orgId) {
                $latest = $rows->last();

                return [
                    'supplier_org_id' => (int) $orgId,
                    'amount' => (float) $latest->amount,
                    'at' => $latest->created_at,
                    'bids' => $rows->where('kind', Bid::KIND_LIVE)->count(),
                ];
            })
            ->pipe(fn ($rows) => self::rank($rows));
    }

    /**
     * Item-wise: ranked suppliers per RFQ line. amount = current unit price.
     *
     * @return Collection<int, Collection<int, array{supplier_org_id:int, amount:float, at:\Carbon\CarbonInterface, bids:int, rank:int}>>
     */
    public static function byItem(Auction $auction): Collection
    {
        $bids = Bid::where('auction_id', $auction->id)->whereNotNull('rfq_item_id')
            ->orderBy('created_at')->orderBy('id')
            ->get(['id', 'supplier_org_id', 'rfq_item_id', 'amount', 'kind', 'created_at']);

        return $bids->groupBy('rfq_item_id')->map(fn (Collection $itemBids) => self::rank(
            $itemBids->groupBy('supplier_org_id')->map(fn (Collection $rows, $orgId) => [
                'supplier_org_id' => (int) $orgId,
                'amount' => (float) $rows->last()->amount,
                'at' => $rows->last()->created_at,
                'bids' => $rows->where('kind', Bid::KIND_LIVE)->count(),
            ])->values()
        ));
    }

    /** rfq_item_id => quantity, for turning unit prices into totals. */
    public static function quantities(Auction $auction): Collection
    {
        return \App\Models\RfqItem::where('rfq_id', $auction->rfq_id)->pluck('qty', 'id')->map(fn ($q) => (float) $q);
    }

    /** Item-wise: the best total if every line goes to its own L1 (Σ item L1 × qty). */
    public static function combinedL1(Collection $byItem, Collection $qty): float
    {
        $total = 0.0;
        foreach ($byItem as $itemId => $rows) {
            $total += (float) ($rows->first()['amount'] ?? 0) * ($qty[$itemId] ?? 0);
        }

        return round($total, 2);
    }

    /** Item-wise: each supplier's lot total from its current unit prices, ranked. */
    public static function lotTotals(Auction $auction, ?Collection $byItem = null): Collection
    {
        $byItem ??= self::byItem($auction);
        $qty = self::quantities($auction);
        $totals = [];
        foreach ($byItem as $itemId => $rows) {
            foreach ($rows as $row) {
                $id = $row['supplier_org_id'];
                $totals[$id] ??= ['supplier_org_id' => $id, 'amount' => 0.0, 'at' => $row['at'], 'bids' => 0];
                $totals[$id]['amount'] += $row['amount'] * ($qty[$itemId] ?? 0);
                $totals[$id]['bids'] += $row['bids'];
                if ($row['at']->greaterThan($totals[$id]['at'])) {
                    $totals[$id]['at'] = $row['at'];
                }
            }
        }

        return self::rank(collect($totals)->map(fn ($t) => ['amount' => round($t['amount'], 2)] + $t)->values());
    }

    /** Item-wise typo guard: lowest unit price accepted for a line right now. */
    public static function itemFloor(Auction $auction, float $itemL1): float
    {
        return round($itemL1 * (1 - (float) $auction->max_decrement_pct / 100), 2);
    }

    /** Sort by price, then earliest time, and number the ranks. */
    public static function rank(Collection $rows): Collection
    {
        return $rows
            ->sort(fn ($a, $b) => [$a['amount'], $a['at']->format('U.u')] <=> [$b['amount'], $b['at']->format('U.u')])
            ->values()
            ->map(function ($row, $i) {
                unset($row['rank']);

                return $row + ['rank' => $i + 1];
            });
    }

    /** Minimum drop from a supplier's own current price. */
    public static function minDecrement(Auction $auction, float $from): float
    {
        return round($auction->minDecrementFrom($from), 2);
    }

    /** Highest amount this supplier may bid next (must go at least one decrement below own price). */
    public static function maxNextBid(Auction $auction, float $own): float
    {
        return round($own - self::minDecrement($auction, $own), 2);
    }

    /** Lowest amount accepted right now (fat-finger guard against current L1). */
    public static function floor(Auction $auction): float
    {
        return round((float) $auction->current_l1 * (1 - (float) $auction->max_decrement_pct / 100), 2);
    }

    /** Effective status by the server clock (a scheduled auction past its start is live, etc.). */
    public static function effectiveStatus(Auction $auction): AuctionStatus
    {
        if ($auction->status === AuctionStatus::Scheduled && ! $auction->starts_at->isFuture()) {
            return $auction->ends_at->isFuture() ? AuctionStatus::Live : AuctionStatus::Closed;
        }
        // Paused by GetL1: the clock is stopped, so it can't run out.
        if ($auction->status === AuctionStatus::Live && ! $auction->ends_at->isFuture() && $auction->paused_at === null) {
            return AuctionStatus::Closed;
        }

        return $auction->status;
    }
}
