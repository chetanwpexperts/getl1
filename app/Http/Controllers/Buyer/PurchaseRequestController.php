<?php

namespace App\Http\Controllers\Buyer;

use App\Enums\OrgRole;
use App\Http\Controllers\Controller;
use App\Models\PurchaseRequest;
use App\Services\PurchaseRequestService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Purchase requests. Requesters see only their own; everyone else in the buyer company sees all.
 * Approvers and admins decide; admins and buyers turn approved requests into an RFQ.
 */
class PurchaseRequestController extends Controller
{
    public const TABS = ['mine' => 'My requests', 'approve' => 'To approve', 'ready' => 'Ready to buy', 'all' => 'All'];

    public function __construct(private CurrentOrganization $current, private PurchaseRequestService $service) {}

    private function role(Request $request): ?OrgRole
    {
        return $request->user()->roleIn($this->current->get());
    }

    private function find(Request $request, int $id): PurchaseRequest
    {
        $pr = PurchaseRequest::with(['requester', 'decider'])->findOrFail($id);
        abort_if($this->role($request) === OrgRole::Requester && $pr->requested_by !== $request->user()->id, 404);

        return $pr;
    }

    public function index(Request $request): View
    {
        $role = $this->role($request);
        $isRequester = $role === OrgRole::Requester;
        $canDecide = in_array($role, PurchaseRequestService::DECIDERS, true);
        $canBuy = in_array($role, [OrgRole::BuyerAdmin, OrgRole::BuyerUser], true);
        $me = $request->user()->id;

        $tabs = $isRequester ? ['mine' => self::TABS['mine']] : array_filter(self::TABS, fn ($k) => match ($k) {
            'approve' => $canDecide, 'ready' => $canBuy, default => true,
        }, ARRAY_FILTER_USE_KEY);
        $default = $canBuy && PurchaseRequest::where('status', PurchaseRequest::APPROVED)->exists() ? 'ready'
            : ($canDecide && PurchaseRequest::where('status', PurchaseRequest::PENDING)->where('requested_by', '!=', $me)->exists() ? 'approve' : 'mine');
        $tab = array_key_exists((string) $request->query('tab'), $tabs) ? (string) $request->query('tab') : $default;
        if (! isset($tabs[$tab])) {
            $tab = 'mine';
        }

        $query = PurchaseRequest::with('requester')->latest('id');
        match ($tab) {
            'mine' => $query->where('requested_by', $me),
            'approve' => $query->where('status', PurchaseRequest::PENDING)->where('requested_by', '!=', $me),
            'ready' => $query->where('status', PurchaseRequest::APPROVED),
            default => null,
        };

        $counts = $isRequester ? [] : [
            'approve' => $canDecide ? PurchaseRequest::where('status', PurchaseRequest::PENDING)->where('requested_by', '!=', $me)->count() : 0,
            'ready' => $canBuy ? PurchaseRequest::where('status', PurchaseRequest::APPROVED)->count() : 0,
        ];

        return view('buyer.requests.index', [
            'requests' => $query->paginate(25)->withQueryString(),
            'tabs' => $tabs, 'tab' => $tab, 'counts' => $counts,
            'canBuy' => $canBuy, 'isRequester' => $isRequester,
        ]);
    }

    public function create(): View
    {
        return view('buyer.requests.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(PurchaseRequestService::rules(), PurchaseRequestService::messages());
        $pr = $this->service->create($this->current->get(), $request->user(), $data);

        return redirect()->route('buyer.requests.show', $pr->id)->with('status', $pr->status === PurchaseRequest::APPROVED
            ? "{$pr->pr_number} saved. As an admin, it needs no approval."
            : "{$pr->pr_number} sent for approval. You'll be told when it's decided.");
    }

    public function show(Request $request, int $id): View
    {
        $pr = $this->find($request, $id);
        $role = $this->role($request);

        return view('buyer.requests.show', [
            'pr' => $pr,
            'progress' => $pr->progress(),
            'isRequester' => $role === OrgRole::Requester,
            'canDecide' => $pr->status === PurchaseRequest::PENDING && in_array($role, PurchaseRequestService::DECIDERS, true) && $pr->requested_by !== $request->user()->id,
            'canBuy' => $pr->status === PurchaseRequest::APPROVED && in_array($role, [OrgRole::BuyerAdmin, OrgRole::BuyerUser], true),
            'canCancel' => $pr->isOpen() && ($pr->requested_by === $request->user()->id || $role === OrgRole::BuyerAdmin),
        ]);
    }

    public function approve(Request $request, int $id): RedirectResponse
    {
        $pr = $this->find($request, $id);
        $note = $request->validate(['decision_note' => ['nullable', 'string', 'max:1000']])['decision_note'] ?? null;
        $this->service->approve($pr, $request->user(), $note);

        return back()->with('status', "{$pr->pr_number} approved. The purchase team has been told.");
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        $pr = $this->find($request, $id);
        $note = $request->validate(['decision_note' => ['required', 'string', 'min:5', 'max:1000']], [
            'decision_note.required' => 'Say why it’s rejected, so the requester knows what to change.',
            'decision_note.min' => 'Say why it’s rejected, so the requester knows what to change.',
        ])['decision_note'];
        $this->service->reject($pr, $request->user(), $note);

        return back()->with('status', "{$pr->pr_number} rejected. The requester has been told.");
    }

    public function cancel(Request $request, int $id): RedirectResponse
    {
        $pr = $this->find($request, $id);
        abort_unless($pr->requested_by === $request->user()->id || $this->role($request) === OrgRole::BuyerAdmin, 403);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:1000']])['reason'] ?? null;
        $this->service->cancel($pr, $request->user(), $reason);

        return back()->with('status', "{$pr->pr_number} cancelled.");
    }

    public function convert(Request $request): RedirectResponse
    {
        abort_unless(in_array($this->role($request), [OrgRole::BuyerAdmin, OrgRole::BuyerUser], true), 403);
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:50'], 'ids.*' => ['integer']], ['ids.required' => 'Choose at least one approved request.']);
        $rfq = $this->service->convert($this->current->get(), $request->user(), $data['ids']);

        return redirect()->route('buyer.rfqs.edit', $rfq->id)
            ->with('status', 'Draft RFQ created from '.count($data['ids']).' '.(count($data['ids']) === 1 ? 'request' : 'requests').'. Add the deadline and suppliers, then publish.');
    }
}
