<?php

namespace App\Http\Controllers\Buyer;

use App\Enums\AuctionStatus;
use App\Enums\RfqStatus;
use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\BuyerSupplier;
use App\Models\Category;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\RfqAttachment;
use App\Models\RfqInvite;
use App\Services\Auction\AuctionService;
use App\Services\LiveVersion;
use App\Services\RfqService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Buyer RFQs. Rfq uses the organization global scope, so another company's RFQ id
 * simply isn't found (404).
 */
class RfqController extends Controller
{
    public function __construct(private CurrentOrganization $current, private RfqService $rfqs) {}

    public function index(Request $request): View
    {
        $status = in_array($request->query('status'), ['draft', 'published', 'cancelled'], true) ? $request->query('status') : null;

        return view('buyer.rfqs.index', [
            'rfqs' => Rfq::withCount(['invites', 'items'])
                ->when($status, fn ($q) => $q->where('status', $status))
                ->latest()
                ->paginate(20)
                ->withQueryString(),
            'status' => $status,
            'live' => LiveVersion::buyerIndex($this->current->id()),
        ]);
    }

    /** Fingerprint for self-refreshing pages (list). */
    public function liveIndex(): JsonResponse
    {
        return $this->liveResponse(LiveVersion::buyerIndex($this->current->id()));
    }

    /** Fingerprint for self-refreshing pages (one RFQ). */
    public function live(int $rfq): JsonResponse
    {
        return $this->liveResponse(LiveVersion::buyerRfq(Rfq::findOrFail($rfq)));
    }

    private function liveResponse(array $live): JsonResponse
    {
        return response()->json($live + ['server_time' => now()->getTimestampMs()])->header('Cache-Control', 'no-store');
    }

    public function create(): View
    {
        return view('buyer.rfqs.form', $this->formData(new Rfq));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(RfqService::rules(), RfqService::messages());
        $rfq = $this->rfqs->saveDraft($this->current->get(), $request->user(), $data);

        return redirect()->route('buyer.rfqs.show', $rfq->id)->with('status', 'Draft saved. Next, invite suppliers and publish.');
    }

    public function show(int $rfq): View
    {
        $rfq = Rfq::with(['items', 'attachments', 'invites.listEntry', 'invites.supplier', 'category', 'creator'])->findOrFail($rfq);

        $unsealed = $rfq->quotesAreUnsealed();
        $canInvite = $rfq->isDraft() || $rfq->isOpenForQuotes();
        $quoteCount = Quote::where('rfq_id', $rfq->id)->whereNotNull('submitted_at')->count();
        $auction = Auction::where('rfq_id', $rfq->id)->where('status', '!=', AuctionStatus::Cancelled)->latest('id')->first();

        $awards = app(\App\Services\AwardService::class);
        $currentAward = $unsealed ? $awards->current($rfq) : null;
        $awardBlocker = $unsealed && ! $currentAward ? $awards->blocker($rfq) : null;

        return view('buyer.rfqs.show', [
            'rfq' => $rfq,
            'live' => LiveVersion::buyerRfq($rfq),
            'award' => $currentAward?->load(['supplier', 'awarder', 'approver']),
            'canDecide' => $currentAward && $awards->canDecide($currentAward, request()->user()),
            'awardBlocker' => $awardBlocker,
            'candidates' => $unsealed && ! $currentAward && ! $awardBlocker ? $awards->candidates($rfq) : collect(),
            'rejectedAwards' => \App\Models\Award::with(['supplier', 'approver'])->where('rfq_id', $rfq->id)
                ->where('status', \App\Enums\AwardStatus::Rejected)->latest()->get(),
            'approvalLimit' => $this->current->get()->award_approval_limit,
            'hasApprover' => $this->current->get()->users()->wherePivot('role', \App\Enums\OrgRole::Approver->value)->exists(),
            'activity' => self::activity($rfq),
            'unsealed' => $unsealed,
            // Sealed: only a count, never amounts.
            'quoteCount' => $quoteCount,
            'auction' => $auction,
            'canScheduleAuction' => ! $auction && $unsealed && $rfq->status === RfqStatus::Published
                && $quoteCount >= AuctionService::MIN_PARTICIPANTS,
            'quotedSupplierIds' => Quote::where('rfq_id', $rfq->id)->whereNotNull('submitted_at')->pluck('supplier_org_id')->all(),
            'comparison' => $unsealed ? $this->rfqs->comparison($rfq) : collect(),
            'canInvite' => $canInvite,
            'available' => $canInvite
                ? BuyerSupplier::with('supplier')
                    ->where('buyer_org_id', $rfq->organization_id)
                    ->where('status', 'active')
                    ->whereNotIn('id', $rfq->invites->pluck('buyer_supplier_list_id')->filter())
                    ->orderBy('company_name')
                    ->get()
                : collect(),
            'paymentTerms' => RfqService::PAYMENT_TERMS,
            'freightTerms' => RfqService::FREIGHT_TERMS,
        ]);
    }

    public function edit(int $rfq): View|RedirectResponse
    {
        $rfq = Rfq::with('items')->findOrFail($rfq);
        if (! $rfq->isDraft()) {
            return redirect()->route('buyer.rfqs.show', $rfq->id)->withErrors(['rfq' => 'Only drafts can be edited.']);
        }

        return view('buyer.rfqs.form', $this->formData($rfq));
    }

    public function update(Request $request, int $rfq): RedirectResponse
    {
        $rfq = Rfq::findOrFail($rfq);
        $data = $request->validate(RfqService::rules(), RfqService::messages());
        $this->rfqs->saveDraft($this->current->get(), $request->user(), $data, $rfq);

        return redirect()->route('buyer.rfqs.show', $rfq->id)->with('status', 'Draft updated.');
    }

    public function publish(Request $request, int $rfq): RedirectResponse
    {
        $rfq = Rfq::findOrFail($rfq);
        $this->rfqs->publish($rfq, $request->user());

        return redirect()->route('buyer.rfqs.show', $rfq->id)
            ->with('status', 'Published. Invitations are on their way. Suppliers without email: use the WhatsApp button next to their name.');
    }

    public function extend(Request $request, int $rfq): RedirectResponse
    {
        $rfq = Rfq::findOrFail($rfq);
        $data = $request->validate(['quote_deadline' => ['required', 'date_format:Y-m-d\TH:i']]);
        $this->rfqs->extendDeadline($rfq, $request->user(), RfqService::parseDeadline($data['quote_deadline']));

        return back()->with('status', 'Deadline extended. Invited suppliers have been notified.');
    }

    public function cancel(Request $request, int $rfq): RedirectResponse
    {
        $rfq = Rfq::findOrFail($rfq);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']]);
        $this->rfqs->cancel($rfq, $request->user(), $data['reason']);

        return redirect()->route('buyer.rfqs.show', $rfq->id)->with('status', 'RFQ cancelled.');
    }

    public function invite(Request $request, int $rfq): RedirectResponse
    {
        $rfq = Rfq::findOrFail($rfq);
        $data = $request->validate([
            'suppliers' => ['required', 'array', 'min:1', 'max:200'],
            'suppliers.*' => ['integer', Rule::exists('buyer_supplier_lists', 'id')->where('buyer_org_id', $rfq->organization_id)],
        ], ['suppliers.required' => 'Select at least one supplier.']);

        $count = $this->rfqs->invite($rfq, $request->user(), $data['suppliers']);

        return back()->with('status', $count ? "{$count} supplier(s) invited." : 'Those suppliers were already invited.');
    }

    public function removeInvite(Request $request, int $rfq, int $invite): RedirectResponse
    {
        $rfq = Rfq::findOrFail($rfq);
        $this->rfqs->removeInvite(RfqInvite::where('rfq_id', $rfq->id)->findOrFail($invite), $request->user());

        return back()->with('status', 'Invite removed.');
    }

    public function addAttachment(Request $request, int $rfq): RedirectResponse
    {
        $rfq = Rfq::findOrFail($rfq);
        $request->validate(['file' => ['required', 'file', 'max:'.RfqService::ATTACHMENT_MAX_KB, 'extensions:pdf,jpg,jpeg,png,xlsx,docx']],
            ['file.extensions' => 'Upload a PDF, JPG, PNG, XLSX or DOCX file.', 'file.max' => 'File is too large (max 10 MB).']);
        $this->rfqs->addAttachment($rfq, $request->user(), $request->file('file'));

        return back()->with('status', 'Attachment added.');
    }

    public function removeAttachment(Request $request, int $rfq, int $attachment): RedirectResponse
    {
        $rfq = Rfq::findOrFail($rfq);
        $this->rfqs->removeAttachment(RfqAttachment::where('rfq_id', $rfq->id)->findOrFail($attachment), $request->user());

        return back()->with('status', 'Attachment removed.');
    }

    public function downloadAttachment(int $rfq, int $attachment): StreamedResponse
    {
        $rfq = Rfq::findOrFail($rfq);
        $att = RfqAttachment::where('rfq_id', $rfq->id)->findOrFail($attachment);

        return Storage::disk('local')->download($att->file_path, $att->original_name, [
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private',
        ]);
    }

    private function formData(Rfq $rfq): array
    {
        return [
            'rfq' => $rfq,
            'items' => $rfq->exists ? $rfq->items : collect(),
            'categories' => Category::whereNull('parent_id')->with('children')->orderBy('sort')->get(),
            'units' => RfqService::UNITS,
            'paymentTerms' => RfqService::PAYMENT_TERMS,
            'freightTerms' => RfqService::FREIGHT_TERMS,
            'ai' => $rfq->exists ? null : app(\App\Services\Billing\PlanService::class)->aiAllowance($this->current->get())
                + ['configured' => app(\App\Services\Ai\Claude::class)->isConfigured()],
        ];
    }

    /**
     * Everything that happened on this RFQ, newest first: from the append-only audit log of
     * the RFQ, its invites, quotes, auctions and awards. Buyer company only.
     */
    public static function activity(Rfq $rfq): \Illuminate\Support\Collection
    {
        $ids = [
            'rfq' => [$rfq->id],
            'rfq_invite' => \App\Models\RfqInvite::where('rfq_id', $rfq->id)->pluck('id')->all(),
            'quote' => Quote::where('rfq_id', $rfq->id)->pluck('id')->all(),
            'auction' => \App\Models\Auction::withoutGlobalScopes()->where('rfq_id', $rfq->id)->pluck('id')->all(),
            'award' => \App\Models\Award::withoutGlobalScopes()->where('rfq_id', $rfq->id)->pluck('id')->all(),
        ];

        return \App\Models\AuditLog::with('user:id,name')
            ->where('organization_id', $rfq->organization_id)
            ->where(function ($q) use ($ids) {
                foreach ($ids as $type => $list) {
                    if ($list) {
                        $q->orWhere(fn ($w) => $w->where('auditable_type', $type)->whereIn('auditable_id', $list));
                    }
                }
            })
            ->orderByDesc('created_at')->orderByDesc('id')->limit(200)->get();
    }
}
