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

            $plans = app(\App\Services\Billing\PlanService::class);

            return view('dashboard.buyer', [
                'plan' => $plans->current($org),
                'savings' => app(\App\Services\Reports\SavingsReport::class)->build($org->id, 'this_fy')['totals'],
                'liveSub' => $plans->liveSubscription($org),
                'activeAuctions' => \App\Services\Auction\ActiveAuctions::for($org, 24 * 60),
                'openRfqs' => Rfq::whereNotIn('status', [RfqStatus::Awarded, RfqStatus::Cancelled])->count(),
                'liveAuctions' => Auction::where('status', AuctionStatus::Live)->count(),
                'auctionsThisMonth' => Auction::where('created_at', '>=', now()->startOfMonth())->count(),
                'recentRfqs' => Rfq::latest()->limit(6)->get(),
                'suppliersCount' => $org->supplierList()->where('status', 'active')->count(),
                'allowance' => $plans->auctionAllowance($org),
                // Quotes closing in the next 48 hours: worth a look before the deadline.
                'closingSoon' => Rfq::where('status', RfqStatus::Published)
                    ->whereBetween('quote_deadline', [now(), now()->addHours(48)])->orderBy('quote_deadline')->limit(5)->get(),
                'setup' => $this->buyerSetup($org),
                'waitingApprovals' => in_array(auth()->user()->roleIn($org)?->value, ['buyer_admin', 'approver'], true)
                    ? \App\Models\Award::where('status', \App\Enums\AwardStatus::PendingApproval)->count() : 0,
            ]);
        }

        return view('dashboard.supplier', [
            'activeAuctions' => \App\Services\Auction\ActiveAuctions::for($org, 24 * 60),
            'pendingInvites' => RfqInvite::with('rfq.organization')
                ->where('supplier_org_id', $org->id)
                ->where('status', InviteStatus::Invited)
                ->whereHas('rfq', fn ($q) => $q->where('status', 'published')) // never show unsent drafts
                ->latest()->limit(10)->get(),
            'acceptedCount' => RfqInvite::where('supplier_org_id', $org->id)
                ->where('status', InviteStatus::Accepted)
                ->whereHas('rfq', fn ($q) => $q->where('status', 'published'))->count(),
            'verified' => $org->isVerified(),
            'ordersWon' => \App\Models\Award::withoutGlobalScopes()->where('supplier_org_id', $org->id)->whereNotNull('po_number')->count(),
            'setup' => [
                ['Complete your company profile', 'Address, city and phone so buyers know who you are.', $this->profileComplete($org), route('company.edit')],
                ['Get verified', 'Upload your GST or Udyam certificate. Verified suppliers get invited more.', $org->isVerified(), route('supplier.documents.index')],
                ['Reply to your first invitation', 'Accept an RFQ and send your quote.', RfqInvite::where('supplier_org_id', $org->id)->where('status', InviteStatus::Accepted)->exists(), route('supplier.rfqs.index')],
            ],
        ]);
    }

    /** First-run checklist for buyers: [title, help, done, link]. */
    private function buyerSetup(\App\Models\Organization $org): array
    {
        return [
            ['Complete your company profile', 'Address and GSTIN appear on your RFQs and purchase orders.', $this->profileComplete($org), route('company.edit')],
            ['Add your suppliers', 'Add the suppliers you buy from, one by one or from Excel.', $org->supplierList()->exists(), route('buyer.suppliers.index')],
            ['Create your first RFQ', 'Type it, or paste a WhatsApp message and let AI fill it in.', Rfq::exists(), route('buyer.rfqs.create')],
            ['Run your first live auction', 'Suppliers bid the price down in a short, timed auction.', Auction::exists(), route('buyer.rfqs.index')],
        ];
    }

    private function profileComplete(\App\Models\Organization $org): bool
    {
        return filled($org->address) && filled($org->city) && filled($org->phone);
    }
}
