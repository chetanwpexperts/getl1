<?php

namespace App\Services\Auction;

use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\Organization;
use Illuminate\Support\Collection;

/**
 * Auctions that need someone's attention right now: running, or starting soon.
 * Used for the login landing, the header button and the dashboard card.
 */
class ActiveAuctions
{
    public const SOON_MINUTES = 30;

    /** @return Collection<int, Auction> live first, then soonest start */
    public static function for(Organization $org, int $soonMinutes = self::SOON_MINUTES): Collection
    {
        $query = Auction::withoutGlobalScopes()
            ->whereIn('status', [AuctionStatus::Scheduled->value, AuctionStatus::Live->value])
            ->where(fn ($q) => $q->where('ends_at', '>', now())->orWhereNotNull('paused_at')) // paused: clock stopped
            ->where('starts_at', '<=', now()->addMinutes($soonMinutes));

        $org->isBuyer()
            ? $query->where('organization_id', $org->id)
            : $query->whereIn('id', Bid::where('supplier_org_id', $org->id)->select('auction_id'));

        return $query->with('rfq:id,title,ref_no')->get()
            ->sortBy(fn (Auction $a) => [Standings::effectiveStatus($a) === AuctionStatus::Live ? 0 : 1, $a->starts_at->getTimestamp()])
            ->values();
    }

    public static function url(Organization $org, Auction $auction): string
    {
        return route($org->isBuyer() ? 'buyer.auctions.show' : 'supplier.auctions.show', $auction->id);
    }
}
