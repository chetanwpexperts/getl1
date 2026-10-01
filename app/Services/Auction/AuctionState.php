<?php

namespace App\Services\Auction;

use App\Models\Auction;
use App\Models\Bid;
use App\Models\Organization;

/**
 * What each side may see. These arrays are the only auction data ever sent to a browser,
 * over the socket or the polling endpoint.
 *
 * Buyer: full board with supplier names. Supplier: own rank and price only; the L1 price only
 * if the buyer chose "rank + L1 price"; never other suppliers' names or prices.
 */
class AuctionState
{
    public static function forBuyer(Auction $auction): array
    {
        $standings = Standings::for($auction);
        $names = Organization::whereIn('id', $standings->pluck('supplier_org_id'))->get(['id', 'name', 'verified_at'])->keyBy('id');

        $recent = Bid::where('auction_id', $auction->id)->where('kind', Bid::KIND_LIVE)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(25)
            ->get(['supplier_org_id', 'amount', 'rank_at_submit', 'created_at']);

        return self::common($auction) + [
            'role' => 'buyer',
            'start_price' => (float) $auction->start_price,
            'current_l1' => $auction->current_l1 !== null ? (float) $auction->current_l1 : null,
            'savings_pct' => $auction->savingsPct(),
            'bid_count' => $auction->bid_count,
            'standings' => $standings->map(fn ($s) => [
                'id' => $s['supplier_org_id'],
                'rank' => $s['rank'],
                'supplier' => $names[$s['supplier_org_id']]->name ?? 'Supplier',
                'verified' => (bool) ($names[$s['supplier_org_id']]->verified_at ?? false),
                'amount' => $s['amount'],
                'bids' => $s['bids'],
                'at' => $s['at']->getTimestampMs(),
            ])->all(),
            'recent' => $recent->map(fn ($b) => [
                'supplier' => $names[$b->supplier_org_id]->name ?? 'Supplier',
                'amount' => (float) $b->amount,
                'rank' => $b->rank_at_submit,
                'at' => $b->created_at->getTimestampMs(),
            ])->all(),
        ];
    }

    public static function forSupplier(Auction $auction, int $supplierOrgId): array
    {
        $standings = Standings::for($auction);
        $me = $standings->firstWhere('supplier_org_id', $supplierOrgId);
        $own = $me['amount'] ?? null;

        $mine = Bid::where('auction_id', $auction->id)->where('supplier_org_id', $supplierOrgId)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(10)
            ->get(['amount', 'kind', 'rank_at_submit', 'created_at']);

        return self::common($auction) + [
            'role' => 'supplier',
            'participants' => $standings->count(),
            'my_rank' => $me['rank'] ?? null,
            'my_amount' => $own,
            'l1_amount' => $auction->visibility === 'rank_and_l1' && $auction->current_l1 !== null ? (float) $auction->current_l1 : null,
            'max_next_bid' => $own !== null ? Standings::maxNextBid($auction, $own) : null,
            'min_decrement' => $own !== null ? Standings::minDecrement($auction, $own) : null,
            'floor' => $auction->current_l1 !== null ? Standings::floor($auction) : null,
            'my_bids' => $mine->map(fn ($b) => [
                'amount' => (float) $b->amount,
                'kind' => $b->kind,
                'rank' => $b->rank_at_submit,
                'at' => $b->created_at->getTimestampMs(),
            ])->all(),
        ];
    }

    private static function common(Auction $auction): array
    {
        return [
            'id' => $auction->id,
            'status' => Standings::effectiveStatus($auction)->value,
            'server_time' => now()->getTimestampMs(),
            'starts_at' => $auction->starts_at->getTimestampMs(),
            'ends_at' => $auction->ends_at->getTimestampMs(),
            'extensions_used' => $auction->extensions_used,
            'max_extensions' => $auction->max_extensions,
            'extend_window_sec' => $auction->extend_window_sec,
            'extend_by_sec' => $auction->extend_by_sec,
            'visibility' => $auction->visibility,
            'paused' => $auction->isPaused(),
            'paused_remaining_ms' => $auction->isPaused() ? $auction->remainingMs() : null,
            'notice' => $auction->isPaused()
                ? 'Bidding is paused by GetL1 for a technical check. The clock is stopped and resumes with the same time left.' : null,
        ];
    }
}
