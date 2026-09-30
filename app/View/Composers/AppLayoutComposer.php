<?php

namespace App\View\Composers;

use App\Support\Tenancy\CurrentOrganization;
use Illuminate\View\View;

/**
 * Gives every page using the app layout the same header: current company, role and menu,
 * even on pages that run outside the organization middleware (e.g. admin pages).
 */
class AppLayoutComposer
{
    public function __construct(private CurrentOrganization $current) {}

    public function compose(View $view): void
    {
        $user = auth()->user();

        if (! $user) {
            return;
        }

        $org = $this->current->get();

        if (! $org && $user->current_organization_id) {
            $candidate = $user->currentOrganization;
            // Only show a company the user still belongs to.
            if ($candidate && $user->belongsToOrganization($candidate)) {
                $org = $candidate;
            }
        }

        if ($org) {
            $view->with('currentOrg', $org);
            $view->with('currentRole', $user->roleIn($org));

            // Running or starting-soon auction: shown as a button in the header on every page.
            // Same result for the header and both menus: look it up once per request.
            $memo = 'getl1.active_auction.'.$org->id;
            if (! request()->attributes->has($memo)) {
                request()->attributes->set($memo, \App\Services\Auction\ActiveAuctions::for($org)->first());
            }
            $auction = request()->attributes->get($memo);
            if ($auction && ! request()->routeIs('buyer.auctions.show', 'supplier.auctions.show')) {
                $view->with('navAuction', [
                    'url' => \App\Services\Auction\ActiveAuctions::url($org, $auction),
                    'live' => \App\Services\Auction\Standings::effectiveStatus($auction) === \App\Enums\AuctionStatus::Live,
                    'starts_ms' => $auction->starts_at->getTimestampMs(),
                    'title' => $auction->rfq?->title,
                ]);
            }
        }
    }
}
