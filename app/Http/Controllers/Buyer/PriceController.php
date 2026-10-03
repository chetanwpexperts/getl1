<?php

namespace App\Http\Controllers\Buyer;

use App\Enums\AwardStatus;
use App\Http\Controllers\Controller;
use App\Models\Award;
use App\Models\RateContract;
use App\Services\Pricing\PriceHistory;
use App\Services\Pricing\RateContractService;
use App\Services\RfqService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Price history (what was paid per item, from every PO) and rate contracts (agreed rates with a
 * supplier for a period). Buyer company only; everyone except requesters can view, buyers and
 * admins make and end contracts.
 */
class PriceController extends Controller
{
    public function __construct(private CurrentOrganization $current, private RateContractService $contracts) {}

    private function canEdit(Request $request): bool
    {
        return $request->user()->hasRoleIn($this->current->get(), 'buyer_admin', 'buyer_user');
    }

    public function index(Request $request): View
    {
        $search = trim(mb_substr((string) $request->query('q'), 0, 80));
        $page = max(1, min(10000, (int) $request->query('page', 1)));

        return view('buyer.prices.index', [
            'items' => PriceHistory::items($this->current->id(), $search ?: null, 30, $page, $request->url(), $request->query()),
            'search' => $search,
            'activeContracts' => RateContract::inForce()->count(),
        ]);
    }

    public function item(Request $request): View
    {
        $key = (string) $request->query('key');
        $points = PriceHistory::forItem($this->current->id(), mb_substr($key, 0, 191));
        abort_if($points->isEmpty(), 404);
        $first = $points->first();

        return view('buyer.prices.item', [
            'points' => $points,
            'name' => $points->last()->item_name,
            'unit' => $first->unit,
            'contracts' => PriceHistory::contractsFor($this->current->id(), $first->item_name, $first->unit, $first->spec),
        ]);
    }

    /** For the RFQ form: last price paid and any rate contract for an item (JSON, buyer team only). */
    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'unit' => ['required', 'in:'.implode(',', RfqService::UNITS)], 'spec' => ['nullable', 'string', 'max:1000']]);
        $orgId = $this->current->id();
        $last = PriceHistory::last($orgId, $data['name'], $data['unit'], $data['spec'] ?? null);
        $contract = PriceHistory::contractsFor($orgId, $data['name'], $data['unit'], $data['spec'] ?? null)->first();

        return response()->json([
            'last' => $last ? ['rate' => (float) $last->rate, 'supplier' => $last->supplier?->name, 'on' => $last->priced_on->format('d M Y')] : null,
            'contract' => $contract ? [
                'rate' => $contract['rate'], 'supplier' => $contract['contract']->supplier?->name, 'number' => $contract['contract']->rc_number,
                'till' => $contract['contract']->valid_to->format('d M Y'), 'url' => route('buyer.contracts.show', $contract['contract']->id),
            ] : null,
        ])->header('Cache-Control', 'no-store');
    }

    public function contracts(Request $request): View
    {
        $state = in_array($request->query('state'), ['active', 'ended'], true) ? $request->query('state') : 'active';
        $today = RateContract::today();
        $query = RateContract::with('supplier:id,name')->latest('id');
        $state === 'active'
            ? $query->where('status', RateContract::ACTIVE)->where('valid_to', '>=', $today)
            : $query->where(fn ($q) => $q->where('status', RateContract::CANCELLED)->orWhere('valid_to', '<', $today));

        return view('buyer.prices.contracts', [
            'contracts' => $query->paginate(25)->withQueryString(),
            'state' => $state,
            'canEdit' => $this->canEdit($request),
        ]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        abort_unless($this->canEdit($request), 403);
        $prefill = [];
        if ($request->filled('award')) {
            $award = Award::where('status', AwardStatus::PoSent)->findOrFail((int) $request->query('award'));
            $prefill = RateContractService::fromAward($award);
        }
        $suppliers = RateContractService::suppliers($this->current->id());
        if ($suppliers->isEmpty()) {
            return redirect()->route('buyer.contracts.index')->with('status', 'Rate contracts are made with suppliers you have ordered from. Issue a PO first.');
        }
        $today = RateContract::today();

        return view('buyer.prices.contract-form', [
            'suppliers' => $suppliers,
            'prefill' => $prefill + ['valid_from' => $today, 'valid_to' => \Illuminate\Support\Carbon::parse($today)->addYear()->subDay()->toDateString()],
            'units' => RfqService::UNITS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->canEdit($request), 403);
        $data = $request->validate(RateContractService::rules(), RateContractService::messages());
        $rc = $this->contracts->create($this->current->get(), $request->user(), $data);

        return redirect()->route('buyer.contracts.show', $rc->id)->with('status', "{$rc->rc_number} created. The supplier has been asked to confirm it.");
    }

    public function show(Request $request, int $id): View
    {
        $rc = RateContract::with(['supplier', 'creator:id,name', 'award:id,po_number'])->findOrFail($id);
        $keyOf = fn ($i) => PriceHistory::key($i['name'], $i['unit'], $i['spec'] ?? null);
        $last = PriceHistory::lastMany($this->current->id(), array_map($keyOf, $rc->items));
        $rows = collect($rc->items)->map(fn ($i) => $i + ['last' => $last[$keyOf($i)] ?? null]);

        return view('buyer.prices.contract', ['rc' => $rc, 'rows' => $rows, 'canEdit' => $this->canEdit($request)]);
    }

    public function cancel(Request $request, int $id): RedirectResponse
    {
        abort_unless($this->canEdit($request), 403);
        $rc = RateContract::findOrFail($id);
        $reason = $request->validate(['cancel_reason' => ['required', 'string', 'min:5', 'max:1000']], [
            'cancel_reason.required' => 'Say why it’s being ended; the supplier is told.', 'cancel_reason.min' => 'Say why it’s being ended; the supplier is told.',
        ])['cancel_reason'];
        $this->contracts->cancel($rc, $request->user(), $reason);

        return back()->with('status', "{$rc->rc_number} ended. The supplier has been told.");
    }
}
