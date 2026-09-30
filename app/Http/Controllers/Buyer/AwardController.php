<?php

namespace App\Http\Controllers\Buyer;

use App\Enums\AwardStatus;
use App\Http\Controllers\Controller;
use App\Models\Award;
use App\Models\Rfq;
use App\Services\AwardService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AwardController extends Controller
{
    public function __construct(private AwardService $awards, private CurrentOrganization $current) {}

    public function store(Request $request, int $rfq): RedirectResponse
    {
        $data = $request->validate([
            'supplier_org_id' => ['required', 'integer'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ], ['supplier_org_id.required' => 'Choose the supplier to award.']);

        $award = $this->awards->award(Rfq::findOrFail($rfq), $request->user(), (int) $data['supplier_org_id'], $data['reason'] ?? null, $data['remarks'] ?? null);

        return redirect()->to(route('buyer.rfqs.show', $rfq).'#award')->with('status', $award->isPending()
            ? 'Award sent for approval. The purchase order goes out automatically once it is approved.'
            : 'Awarded. The purchase order is being generated and emailed to the supplier.');
    }

    public function approve(Request $request, int $award): RedirectResponse
    {
        $data = $request->validate(['decision_note' => ['nullable', 'string', 'max:1000']]);
        $award = $this->awards->approve(Award::findOrFail($award), $request->user(), $data['decision_note'] ?? null);

        return redirect()->to(route('buyer.rfqs.show', $award->rfq_id).'#award')
            ->with('status', 'Approved. The purchase order is being generated and emailed to the supplier.');
    }

    public function reject(Request $request, int $award): RedirectResponse
    {
        $data = $request->validate(['decision_note' => ['required', 'string', 'min:5', 'max:1000']],
            ['decision_note.required' => 'Please say why you are rejecting it.']);
        $award = $this->awards->reject(Award::findOrFail($award), $request->user(), $data['decision_note']);

        return redirect()->to(route('buyer.rfqs.show', $award->rfq_id).'#award')->with('status', 'Award rejected. The buyer has been told.');
    }

    public function po(int $award): StreamedResponse
    {
        $award = Award::findOrFail($award);
        abort_unless($award->po_pdf_path && Storage::disk('local')->exists($award->po_pdf_path), 404);

        return Storage::disk('local')->download($award->po_pdf_path, $award->po_number.'.pdf', ['Content-Type' => 'application/pdf']);
    }

    /** Awards waiting for this user's approval. */
    public function approvals(Request $request): View
    {
        $pending = Award::with(['rfq', 'supplier', 'awarder'])
            ->where('status', AwardStatus::PendingApproval)->latest()->get();

        return view('buyer.approvals', [
            'pending' => $pending,
            'mine' => $pending->filter(fn ($a) => $this->awards->canDecide($a, $request->user())),
        ]);
    }
}
