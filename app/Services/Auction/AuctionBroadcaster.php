<?php

namespace App\Services\Auction;

use App\Events\AuctionStateChanged;
use App\Models\Auction;
use App\Models\Bid;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends fresh state to the buyer channel and to each participating supplier's own channel.
 * Never throws: a bid is already committed by the time this runs, and browsers fall back to
 * polling if the socket server is unreachable.
 */
class AuctionBroadcaster
{
    public function push(Auction $auction): void
    {
        if (config('broadcasting.default') === 'null') {
            return;
        }

        $this->send($auction, "auction.{$auction->id}.buyer", fn () => AuctionState::forBuyer($auction));

        $suppliers = Bid::where('auction_id', $auction->id)->distinct()->pluck('supplier_org_id');
        foreach ($suppliers as $orgId) {
            $this->send($auction, "auction.{$auction->id}.supplier.{$orgId}", fn () => AuctionState::forSupplier($auction, (int) $orgId));
        }
    }

    /** One channel failing (or the socket server being down) never stops the others. */
    private function send(Auction $auction, string $channel, \Closure $state): void
    {
        try {
            broadcast(new AuctionStateChanged($channel, $state()));
        } catch (Throwable $e) {
            Log::warning('auction_broadcast_failed', ['auction_id' => $auction->id, 'channel' => $channel, 'error' => $e->getMessage()]);
        }
    }
}
