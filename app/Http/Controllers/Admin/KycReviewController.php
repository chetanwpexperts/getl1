<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupplierDocument;
use App\Services\AuditLogger;
use App\Services\KycService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GetL1 staff: review supplier KYC documents. Routes require 'platform.admin'.
 */
class KycReviewController extends Controller
{
    public function index(Request $request): View
    {
        $status = in_array($request->query('status'), ['pending', 'verified', 'rejected'], true)
            ? $request->query('status') : 'pending';

        return view('admin.kyc', [
            'status' => $status,
            'documents' => SupplierDocument::with('organization')
                ->where('status', $status)
                ->oldest()
                ->paginate(30)
                ->withQueryString(),
            'counts' => SupplierDocument::selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status'),
        ]);
    }

    public function download(Request $request, SupplierDocument $document, AuditLogger $audit): StreamedResponse
    {
        // Staff viewing a company's KYC document is itself worth recording.
        $audit->log('kyc_document_viewed_by_admin', $document, organizationId: $document->organization_id);

        return Storage::disk('local')->download($document->file_path, $document->original_name, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function approve(Request $request, SupplierDocument $document, KycService $kyc): RedirectResponse
    {
        abort_unless($document->status === 'pending', 409, 'Already reviewed.');
        $kyc->approve($document, $request->user());

        return back()->with('status', "Approved {$document->type} for {$document->organization->name}.");
    }

    public function reject(Request $request, SupplierDocument $document, KycService $kyc): RedirectResponse
    {
        abort_unless(in_array($document->status, ['pending', 'verified'], true), 409, 'Already rejected.');
        $data = $request->validate(['remarks' => ['required', 'string', 'min:5', 'max:255']]);
        $kyc->reject($document, $request->user(), $data['remarks']);

        return back()->with('status', "Rejected {$document->type} for {$document->organization->name}.");
    }
}
