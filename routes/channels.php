<?php

use App\Models\Auction;
use App\Models\Bid;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 * Private auction channels. Nothing about an auction is ever sent on a public channel.
 *
 * - auction.{id}.buyer             full board (supplier names, all prices). Buyer company only.
 * - auction.{id}.supplier.{orgId}  one supplier's own view (rank, own price). That supplier only.
 * - user.{id}                      one person's own alerts (the bell). That person only.
 */

Broadcast::channel('user.{userId}', fn (User $user, int $userId) => (int) $user->id === $userId && ! $user->locked_at);

Broadcast::channel('auction.{auctionId}.buyer', function (User $user, int $auctionId) {
    $auction = Auction::withoutGlobalScopes()->find($auctionId);

    // Any member of the buyer company except requesters, who never see prices.
    return $auction !== null && $user->hasRoleIn($auction->organization_id, 'buyer_admin', 'buyer_user', 'approver');
});

Broadcast::channel('auction.{auctionId}.supplier.{orgId}', function (User $user, int $auctionId, int $orgId) {
    return $user->belongsToOrganization($orgId)
        && Bid::where('auction_id', $auctionId)->where('supplier_org_id', $orgId)->exists();
});
