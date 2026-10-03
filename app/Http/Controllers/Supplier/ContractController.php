<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Models\RateContract;
use App\Services\Pricing\RateContractService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Rate contracts buyers have made with this supplier: view and confirm. Only its own. */
class ContractController extends Controller
{
    public function __construct(private CurrentOrganization $current) {}

    private function own(int $id): RateContract
    {
        return RateContract::withoutGlobalScopes()->with(['buyer:id,name,city', 'award:id,po_number'])
            ->where('supplier_org_id', $this->current->id())->findOrFail($id);
    }

    public function index(): View
    {
        return view('supplier.contracts.index', [
            'contracts' => RateContract::withoutGlobalScopes()->with('buyer:id,name,city')->where('supplier_org_id', $this->current->id())
                ->latest('id')->paginate(25),
        ]);
    }

    public function show(int $id): View
    {
        return view('supplier.contracts.show', ['rc' => $this->own($id)]);
    }

    public function accept(Request $request, int $id, RateContractService $service): RedirectResponse
    {
        $rc = $this->own($id);
        if (! $service->accept($rc, $request->user())) {
            return back()->withErrors(['contract' => in_array(RateContract::withoutGlobalScopes()->find($rc->id)?->state(), ['cancelled', 'expired'], true)
                ? 'This rate contract has ended, so it can no longer be confirmed.' : 'Already confirmed.']);
        }

        return back()->with('status', 'Confirmed. The buyer has been told.');
    }
}
