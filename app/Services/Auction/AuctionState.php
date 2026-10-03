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
            ->get(['supplier_org_id', 'rfq_item_id', 'amount', 'rank_at_submit', 'created_at']);
        $itemNames = $auction->isPerItem() ? self::items($auction)->pluck('name', 'id') : collect();

        return self::common($auction) + ($auction->isPerItem() ? self::buyerItems($auction, $names) : []) + [
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
                'item' => $b->rfq_item_id ? ($itemNames[$b->rfq_item_id] ?? null) : null,
                'amount' => (float) $b->amount,
                'rank' => $b->rank_at_submit,
                'at' => $b->created_at->getTimestampMs(),
            ])->all(),
        ];
    }

    public static function forSupplier(Auction $auction, int $supplierOrgId): array
    {
        if ($auction->isPerItem()) {
            return self::supplierItems($auction, $supplierOrgId);
        }

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

    /** Item-wise, buyer: every line with its L1 rate and supplier, and the full ranking. */
    private static function buyerItems(Auction $auction, \Illuminate\Support\Collection $names): array
    {
        $byItem = Standings::byItem($auction);

        return [
            'items' => self::items($auction)->map(function ($item) use ($byItem, $names) {
                $rows = $byItem[$item->id] ?? collect();
                $l1 = $rows->first();

                return [
                    'id' => $item->id,
                    'line' => $item->line_no,
                    'name' => $item->name,
                    'qty' => (float) $item->qty,
                    'unit' => $item->unit,
                    'l1_rate' => $l1['amount'] ?? null,
                    'l1_total' => $l1 ? round($l1['amount'] * (float) $item->qty, 2) : null,
                    'l1_supplier' => $l1 ? ($names[$l1['supplier_org_id']]->name ?? 'Supplier') : null,
                    'bids' => $rows->sum('bids'),
                    'ranking' => $rows->map(fn ($r) => [
                        'rank' => $r['rank'],
                        'supplier' => $names[$r['supplier_org_id']]->name ?? 'Supplier',
                        'rate' => $r['amount'],
                    ])->all(),
                ];
            })->values()->all(),
        ];
    }

    /**
     * Item-wise, supplier: own rate and rank per line; the line's L1 rate only with "rank + L1".
     * Never other suppliers' names or rates.
     */
    private static function supplierItems(Auction $auction, int $supplierOrgId): array
    {
        $byItem = Standings::byItem($auction);
        $items = self::items($auction);
        $showL1 = $auction->visibility === 'rank_and_l1';
        $lines = [];
        $total = 0.0;
        $leading = 0;
        foreach ($items as $item) {
            $rows = $byItem[$item->id] ?? collect();
            $me = $rows->firstWhere('supplier_org_id', $supplierOrgId);
            if (! $me) {
                continue;
            }
            $l1 = (float) $rows->first()['amount'];
            $total += $me['amount'] * (float) $item->qty;
            $leading += $me['rank'] === 1 ? 1 : 0;
            $lines[] = [
                'id' => $item->id,
                'line' => $item->line_no,
                'name' => $item->name,
                'qty' => (float) $item->qty,
                'unit' => $item->unit,
                'my_rank' => $me['rank'],
                'my_rate' => $me['amount'],
                'l1_rate' => $showL1 ? $l1 : null,
                'max_next_bid' => Standings::maxNextBid($auction, $me['amount']),
                'min_decrement' => Standings::minDecrement($auction, $me['amount']),
                'floor' => Standings::itemFloor($auction, $l1),
            ];
        }

        $names = $items->pluck('name', 'id');
        $mine = Bid::where('auction_id', $auction->id)->where('supplier_org_id', $supplierOrgId)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(15)
            ->get(['rfq_item_id', 'amount', 'kind', 'rank_at_submit', 'created_at']);

        return self::common($auction) + [
            'role' => 'supplier',
            'participants' => $byItem->first()?->count() ?? 0,
            'items' => $lines,
            'leading' => $leading,
            'my_total' => round($total, 2),
            // Kept for shared code paths; per-line values are in items.
            'my_rank' => null,
            'my_amount' => round($total, 2),
            'l1_amount' => null,
            'max_next_bid' => null,
            'min_decrement' => null,
            'floor' => null,
            'my_bids' => $mine->map(fn ($b) => [
                'item' => $b->rfq_item_id ? ($names[$b->rfq_item_id] ?? null) : null,
                'amount' => (float) $b->amount,
                'kind' => $b->kind,
                'rank' => $b->rank_at_submit,
                'at' => $b->created_at->getTimestampMs(),
            ])->all(),
        ];
    }

    private static function items(Auction $auction): \Illuminate\Support\Collection
    {
        return \App\Models\RfqItem::where('rfq_id', $auction->rfq_id)->orderBy('line_no')->orderBy('id')
            ->get(['id', 'line_no', 'name', 'qty', 'unit']);
    }

    private static function common(Auction $auction): array
    {
        return [
            'id' => $auction->id,
            'basis' => $auction->isPerItem() ? 'per_item' : 'lot_total',
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
