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
            if ($user->roleIn($org)?->value === 'requester') {
                return; // no buying badges, counts or auction button for requesters
            }

            // Menu badges: awards waiting for approval (buyer) / orders not yet accepted (supplier).
            $badgeKey = 'getl1.badges.'.$org->id;
            if (! request()->attributes->has($badgeKey)) {
                request()->attributes->set($badgeKey, $org->isBuyer()
                    ? ['pendingApprovals' => \App\Models\Award::withoutGlobalScopes()->where('organization_id', $org->id)->where('status', \App\Enums\AwardStatus::PendingApproval->value)->count(),
                        // Invoices to review, plus MSME invoices due within 7 days or overdue.
                        'paymentsAttention' => \App\Models\SupplierInvoice::withoutGlobalScopes()->where('organization_id', $org->id)
                            ->where(fn ($q) => $q->where('status', \App\Models\SupplierInvoice::SUBMITTED)
                                ->orWhere(fn ($w) => $w->where('status', \App\Models\SupplierInvoice::APPROVED)->where('is_msme', true)
                                    ->where('due_date', '<=', now()->setTimezone(config('app.display_timezone'))->addDays(7)->toDateString())))
                            ->count()]
                    : ['openOrders' => \App\Models\Award::withoutGlobalScopes()->where('supplier_org_id', $org->id)->where('status', \App\Enums\AwardStatus::PoSent->value)->whereNull('supplier_accepted_at')->count(),
                        // Rate contracts in force or upcoming that the supplier hasn't confirmed yet.
                        'contractsToConfirm' => \App\Models\RateContract::withoutGlobalScopes()->where('supplier_org_id', $org->id)
                            ->where('status', \App\Models\RateContract::ACTIVE)->whereNull('supplier_accepted_at')
                            ->where('valid_to', '>=', \App\Models\RateContract::today())->count()]);
            }
            $view->with(request()->attributes->get($badgeKey));

            // Purchase requests needing this person: to approve (approvers/admins) or to buy (buyers).
            $role = $user->roleIn($org)?->value;
            if ($org->isBuyer() && in_array($role, ['buyer_admin', 'buyer_user', 'approver'], true)) {
                $statuses = array_merge(in_array($role, ['buyer_admin', 'approver'], true) ? [\App\Models\PurchaseRequest::PENDING] : [],
                    in_array($role, ['buyer_admin', 'buyer_user'], true) ? [\App\Models\PurchaseRequest::APPROVED] : []);
                $view->with('requestsAttention', \App\Models\PurchaseRequest::withoutGlobalScopes()->where('organization_id', $org->id)
                    ->whereIn('status', $statuses)
                    ->where(fn ($q) => $q->where('status', '!=', \App\Models\PurchaseRequest::PENDING)->orWhere('requested_by', '!=', $user->id))
                    ->count());
            }

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
