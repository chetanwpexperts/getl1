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

        return view('supplier.orders.show', [
            'award' => $award, 'rfq' => $award->rfq, 'buyer' => $award->rfq->organization,
            'summary' => \App\Services\Payables\ReceiptService::summary($award),
            'receipts' => \App\Models\GoodsReceipt::withoutGlobalScopes()->where('award_id', $award->id)->latest('received_on')->latest('id')->get(),
            'invoices' => \App\Models\SupplierInvoice::withoutGlobalScopes()->where('award_id', $award->id)->where('supplier_org_id', $this->current->id())->orderByDesc('id')->get(),
        ]);
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
            $team = $automations->buyerTeam(Rfq::withoutGlobalScopes()->with('organization')->findOrFail($accepted->rfq_id));
            foreach ($team as $user) {
                Mail::to($user->email)->queue(new PoAcceptedMail($accepted));
            }
            \App\Services\Notifier::toUsers($team, $accepted->organization_id, 'orders', "PO accepted: {$accepted->po_number}",
                \App\Models\Organization::whereKey($accepted->supplier_org_id)->value('name').' accepted the purchase order.', route('buyer.orders.show', $accepted->id));
        }

        return back()->with('status', 'Order accepted. The buyer has been told.');
    }

    public function submitInvoice(Request $request, int $award, \App\Services\Payables\InvoiceService $invoices): RedirectResponse
    {
        $award = $this->own($award);
        $data = $request->validate(\App\Services\Payables\InvoiceService::rules(), \App\Services\Payables\InvoiceService::messages());
        $inv = $invoices->submit($award, $request->user(), $data, $request->file('file'));

        return redirect()->to(route('supplier.orders.show', $award->id).'#invoices')
            ->with('status', "Invoice {$inv->invoice_number} sent to {$award->rfq->organization->name}. Payment due by {$inv->due_date?->format('d M Y')}.");
    }

    public function invoiceFile(int $award, int $invoice): StreamedResponse
    {
        $award = $this->own($award);
        $inv = \App\Models\SupplierInvoice::withoutGlobalScopes()->where('award_id', $award->id)->where('supplier_org_id', $this->current->id())->findOrFail($invoice);
        abort_unless(Storage::disk('local')->exists($inv->file_path), 404);

        return Storage::disk('local')->download($inv->file_path, $inv->original_name);
    }

    private function own(int $id): Award
    {
        return Award::withoutGlobalScopes()->with('rfq.organization')
            ->where('supplier_org_id', $this->current->id())
            ->where('status', AwardStatus::PoSent)
            ->findOrFail($id);
    }
}
