<?php

namespace App\Http\Controllers;

use App\Enums\AuctionStatus;
use App\Enums\InviteStatus;
use App\Enums\RfqStatus;
use App\Models\Auction;
use App\Models\Rfq;
use App\Models\RfqInvite;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(CurrentOrganization $current): View
    {
        $org = $current->get();

        if ($org->isBuyer()) {
            $org->load('subscription.plan');

            return view('dashboard.buyer', [
                'openRfqs' => Rfq::whereNotIn('status', [RfqStatus::Awarded, RfqStatus::Cancelled])->count(),
                'liveAuctions' => Auction::where('status', AuctionStatus::Live)->count(),
                'auctionsThisMonth' => Auction::where('created_at', '>=', now()->startOfMonth())->count(),
                'recentRfqs' => Rfq::latest()->limit(5)->get(),
                'suppliersCount' => $org->supplierList()->where('status', 'active')->count(),
            ]);
        }

        return view('dashboard.supplier', [
            'pendingInvites' => RfqInvite::with('rfq.organization')
                ->where('supplier_org_id', $org->id)
                ->where('status', InviteStatus::Invited)
                ->latest()->limit(10)->get(),
            'acceptedCount' => RfqInvite::where('supplier_org_id', $org->id)
                ->where('status', InviteStatus::Accepted)->count(),
            'verified' => $org->isVerified(),
        ]);
    }
}
