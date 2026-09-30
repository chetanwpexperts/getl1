<?php

namespace App\Http\Controllers\Supplier;

use App\Enums\AuctionStatus;
use App\Enums\InviteStatus;
use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\Quote;
use App\Models\RfqAttachment;
use App\Models\RfqInvite;
use App\Services\InviteService;
use App\Services\QuoteService;
use App\Services\LiveVersion;
use App\Services\RfqService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Supplier view of RFQs they're invited to. Everything is looked up through the invite,
 * scoped to the current supplier company, so a supplier can only ever reach its own invites.
 * The buyer's last purchase price is never sent to supplier pages.
 */
class RfqController extends Controller
{
    public function __construct(private CurrentOrganization $current) {}

    public function index(): View
    {
        return view('supplier.rfqs.index', [
            'invites' => RfqInvite::with('rfq.organization')
                ->where('supplier_org_id', $this->current->id())
                ->whereHas('rfq', fn ($q) => $q->where('status', '!=', 'draft'))
                ->latest()
                ->paginate(20),
            'quotedRfqIds' => Quote::where('supplier_org_id', $this->current->id())->whereNotNull('submitted_at')->pluck('rfq_id')->all(),
            'auctions' => $this->myAuctions()->get()->keyBy('rfq_id'),
            'live' => LiveVersion::supplierIndex($this->current->id()),
        ]);
    }

    public function liveIndex(): JsonResponse
    {
        return $this->liveResponse(LiveVersion::supplierIndex($this->current->id()));
    }

    public function live(int $invite): JsonResponse
    {
        return $this->liveResponse(LiveVersion::supplierRfq($this->findOwn($invite)));
    }

    private function liveResponse(array $live): JsonResponse
    {
        return response()->json($live + ['server_time' => now()->getTimestampMs()])->header('Cache-Control', 'no-store');
    }

    public function show(int $invite): View
    {
        $invite = $this->findOwn($invite);
        $rfq = $invite->rfq;
        $rfq->load(['items', 'attachments', 'organization']);

        return view('supplier.rfqs.show', [
            'invite' => $invite,
            'live' => LiveVersion::supplierRfq($invite),
            'rfq' => $rfq,
            'quote' => Quote::with('items')->where('rfq_id', $rfq->id)->where('supplier_org_id', $this->current->id())->first(),
            'auction' => $this->myAuctions()->where('rfq_id', $rfq->id)->latest('id')->first(),
            'gstRates' => QuoteService::GST_RATES,
            'paymentTerms' => RfqService::PAYMENT_TERMS,
            'freightTerms' => RfqService::FREIGHT_TERMS,
        ]);
    }

    /** Non-cancelled auctions this supplier takes part in (it has an opening sealed bid). */
    private function myAuctions()
    {
        return Auction::withoutGlobalScopes()
            ->where('status', '!=', AuctionStatus::Cancelled)
            ->whereIn('id', Bid::where('supplier_org_id', $this->current->id())->select('auction_id'));
    }

    public function accept(Request $request, int $invite, InviteService $invites): RedirectResponse
    {
        $request->validate(['agree' => ['accepted']], ['agree.accepted' => 'Please confirm you agree to the RFQ terms.']);
        $invites->accept($this->findOwn($invite), $request->user());

        return back()->with('status', 'Terms accepted. You can now submit your quote.');
    }

    public function decline(Request $request, int $invite, InviteService $invites): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $invites->decline($this->findOwn($invite), $request->user(), $data['reason'] ?? null);

        return redirect()->route('supplier.rfqs.index')->with('status', 'You declined this RFQ.');
    }

    public function quote(Request $request, int $invite, QuoteService $quotes): RedirectResponse
    {
        $data = $request->validate(QuoteService::rules(), QuoteService::messages());
        $quote = $quotes->submit($this->findOwn($invite), $request->user(), $data);

        return back()->with('status', 'Quote submitted and sealed until the deadline. You can revise it until then.');
    }

    public function downloadAttachment(int $invite, int $attachment): StreamedResponse
    {
        $invite = $this->findOwn($invite);
        $att = RfqAttachment::where('rfq_id', $invite->rfq_id)->findOrFail($attachment);

        return Storage::disk('local')->download($att->file_path, $att->original_name, [
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private',
        ]);
    }

    /** Only this supplier's invites on RFQs that were actually sent (not drafts). */
    private function findOwn(int $id): RfqInvite
    {
        $invite = RfqInvite::with('rfq')->where('supplier_org_id', $this->current->id())->findOrFail($id);
        abort_if($invite->rfq === null || $invite->rfq->isDraft(), 404);

        return $invite;
    }
}
