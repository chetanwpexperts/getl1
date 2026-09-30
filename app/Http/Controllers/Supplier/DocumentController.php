<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Models\SupplierDocument;
use App\Services\KycService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function __construct(private CurrentOrganization $current) {}

    public function index(): View
    {
        $org = $this->current->get();

        return view('supplier.documents', [
            'org' => $org,
            'documents' => $org->documents()->latest()->get(),
            'types' => ['gst' => 'GST certificate', 'udyam' => 'Udyam certificate', 'pan' => 'PAN card', 'other' => 'Other'],
            'maxDocs' => KycService::MAX_DOCUMENTS,
        ]);
    }

    public function store(Request $request, KycService $kyc): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(SupplierDocument::TYPES)],
            'file' => ['required', 'file', 'max:'.KycService::MAX_KB, 'extensions:pdf,jpg,jpeg,png'],
        ], [
            'file.max' => 'File is too large (max 5 MB).',
            'file.extensions' => 'Upload a PDF, JPG or PNG file.',
        ]);

        $kyc->store($this->current->get(), $request->user(), $data['type'], $request->file('file'));

        return back()->with('status', 'Document uploaded. We usually review within 1 working day.');
    }

    public function download(int $document): StreamedResponse
    {
        $doc = $this->findOwn($document);

        return Storage::disk('local')->download($doc->file_path, $doc->original_name, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function destroy(Request $request, int $document, KycService $kyc): RedirectResponse
    {
        $kyc->delete($this->findOwn($document), $request->user());

        return back()->with('status', 'Document deleted.');
    }

    /** 404 (not 403) for other companies' documents, so IDs can't be probed. */
    private function findOwn(int $id): SupplierDocument
    {
        return SupplierDocument::where('organization_id', $this->current->id())->findOrFail($id);
    }
}
