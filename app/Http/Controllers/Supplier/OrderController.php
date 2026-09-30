<?php

namespace App\Http\Controllers\Supplier;

use App\Enums\AwardStatus;
use App\Http\Controllers\Controller;
use App\Mail\PoAcceptedMail;
use App\Models\Award;
use App\Models\Rfq;
use App\Services\AuditLogger;
use App\Services\Automations;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Purchase orders a supplier has received. Other companies' orders are always 404. */
class OrderController extends Controller
{
    public function __construct(private CurrentOrganization $current) {}

    public function index(): View
    {
        return view('supplier.orders.index', [
            'orders' => Award::withoutGlobalScopes()->with(['rfq.organization'])
                ->where('supplier_org_id', $this->current->id())
                ->where('status', AwardStatus::PoSent)
                ->latest('po_sent_at')->paginate(20),
        ]);
    }

    public function show(int $award): View
    {
        $award = $this->own($award);

        return view('supplier.orders.show', ['award' => $award, 'rfq' => $award->rfq, 'buyer' => $award->rfq->organization]);
    }

    public function po(int $award): StreamedResponse
    {
        $award = $this->own($award);
        abort_unless(Storage::disk('local')->exists($award->po_pdf_path), 404);

        return Storage::disk('local')->download($award->po_pdf_path, $award->po_number.'.pdf', ['Content-Type' => 'application/pdf']);
    }

    public function accept(Request $request, int $award, AuditLogger $audit, Automations $automations): RedirectResponse
    {
        $accepted = DB::transaction(function () use ($award, $request, $audit) {
            $award = Award::withoutGlobalScopes()->whereKey($this->own($award)->id)->lockForUpdate()->firstOrFail();
            if ($award->supplier_accepted_at) {
                return null;
            }
            $award->update(['supplier_accepted_at' => now(), 'supplier_accepted_by' => $request->user()->id]);
            $audit->log('po_accepted', $award, after: ['po_number' => $award->po_number], organizationId: $award->organization_id);

            return $award;
        });

        if ($accepted) {
            foreach ($automations->buyerTeam(Rfq::withoutGlobalScopes()->with('organization')->findOrFail($accepted->rfq_id)) as $user) {
                Mail::to($user->email)->queue(new PoAcceptedMail($accepted));
            }
        }

        return back()->with('status', 'Order accepted. The buyer has been told.');
    }

    private function own(int $id): Award
    {
        return Award::withoutGlobalScopes()->with('rfq.organization')
            ->where('supplier_org_id', $this->current->id())
            ->where('status', AwardStatus::PoSent)
            ->findOrFail($id);
    }
}
