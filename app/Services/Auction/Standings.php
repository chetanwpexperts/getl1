<?php

namespace App\Services\Auction;

use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Models\Bid;
use Illuminate\Support\Collection;

/**
 * Live standings: each supplier's current price is their latest bid (sealed quote or live bid).
 * Ranked by price, then by who reached that price first (earlier wins ties).
 */
class Standings
{
    /**
     * @return Collection<int, array{supplier_org_id:int, amount:float, at:\Carbon\CarbonInterface, bids:int, rank:int}>
     */
    public static function for(Auction $auction): Collection
    {
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
        if ($auction->status === AuctionStatus::Live && ! $auction->ends_at->isFuture()) {
            return AuctionStatus::Closed;
        }

        return $auction->status;
    }
}
