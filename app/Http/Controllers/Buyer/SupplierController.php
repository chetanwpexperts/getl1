<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\BuyerSupplier;
use App\Services\SecurityLog;
use App\Services\SupplierListService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplierController extends Controller
{
    public function __construct(
        private CurrentOrganization $current,
        private SupplierListService $list,
    ) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $status = in_array($request->query('status'), ['active', 'blocked'], true) ? $request->query('status') : null;
        $tag = $request->query('tag');

        $suppliers = BuyerSupplier::with('supplier')
            ->where('buyer_org_id', $this->current->id())
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.addcslashes($q, '%_\\').'%';
                $query->where(fn ($w) => $w->where('company_name', 'like', $like)
                    ->orWhere('contact_name', 'like', $like)
                    ->orWhere('contact_email', 'like', $like)
                    ->orWhere('contact_phone', 'like', $like));
            })
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when(is_string($tag) && $tag !== '', fn ($query) => $query->where('tag', $tag))
            ->orderBy('company_name')
            ->paginate(25)
            ->withQueryString();

        return view('buyer.suppliers.index', [
            'suppliers' => $suppliers,
            'tags' => BuyerSupplier::where('buyer_org_id', $this->current->id())->whereNotNull('tag')->distinct()->orderBy('tag')->pluck('tag'),
            'filters' => ['q' => $q, 'status' => $status, 'tag' => $tag],
        ]);
    }

    public function create(): View
    {
        return view('buyer.suppliers.form', ['entry' => new BuyerSupplier]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        try {
            $entry = $this->list->create($this->current->get(), $request->user(), $data);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['company_name' => $e->getMessage()]);
        }

        return redirect()->route('buyer.suppliers.index')->with('status', $entry->supplier_org_id
            ? "{$entry->company_name} added. They're already on GetL1, so invites will reach them directly."
            : "{$entry->company_name} added. They'll get an invite link when you invite them to an RFQ.");
    }

    public function edit(int $supplier): View
    {
        return view('buyer.suppliers.form', ['entry' => $this->findOwn($supplier)]);
    }

    public function update(Request $request, int $supplier): RedirectResponse
    {
        $entry = $this->findOwn($supplier);
        $this->list->update($entry, $request->user(), $this->validated($request, $entry->id));

        return redirect()->route('buyer.suppliers.index')->with('status', "{$entry->company_name} updated.");
    }

    public function toggleBlock(Request $request, int $supplier): RedirectResponse
    {
        $entry = $this->findOwn($supplier);
        $new = $entry->status === 'blocked' ? 'active' : 'blocked';
        $this->list->setStatus($entry, $request->user(), $new);

        return back()->with('status', $new === 'blocked'
            ? "{$entry->company_name} blocked. They won't be invited to new RFQs."
            : "{$entry->company_name} unblocked.");
    }

    public function destroy(Request $request, int $supplier): RedirectResponse
    {
        $entry = $this->findOwn($supplier);
        $name = $entry->company_name;
        $this->list->remove($entry, $request->user());

        return redirect()->route('buyer.suppliers.index')->with('status', "{$name} removed from your list.");
    }

    public function importForm(): View
    {
        return view('buyer.suppliers.import');
    }

    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:1024', 'extensions:xlsx,csv,txt'],
        ], [
            'file.max' => 'File is too large (max 1 MB).',
            'file.extensions' => 'Upload an .xlsx or .csv file.',
        ]);

        $file = $request->file('file');

        try {
            $result = $this->list->import($this->current->get(), $request->user(), $file->getRealPath(), $file->getClientOriginalExtension());
        } catch (RuntimeException $e) {
            SecurityLog::info('upload_rejected', ['kind' => 'supplier_import', 'reason' => $e->getMessage(), 'size' => $file->getSize()]);

            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return redirect()->route('buyer.suppliers.index')
            ->with('status', "Import done: {$result['created']} added, {$result['skipped']} already in your list"
                .($result['errors'] ? ', '.count($result['errors']).' rows need fixing.' : '.'))
            ->with('import_errors', array_slice($result['errors'], 0, 50));
    }

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel opens it as UTF-8
            fputcsv($out, ['Company name', 'Contact name', 'Email', 'Mobile', 'Tag', 'Notes'], ',', '"', '\\');
            fputcsv($out, ['Sharma Cartons', 'Ravi Sharma', 'ravi@example.com', '9876543210', 'boxes', 'Delivers in 3 days'], ',', '"', '\\');
            fclose($out);
        }, 'getl1-suppliers-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $input = SupplierListService::normalize($request->only(array_keys(SupplierListService::rules())));

        $validator = Validator::make($input, SupplierListService::rules(), SupplierListService::messages());
        $validator->after(function ($v) use ($input, $ignoreId) {
            $dup = $this->list->findDuplicate($this->current->get(), $input['contact_email'], $input['contact_phone'], $ignoreId);
            if ($dup) {
                $v->errors()->add('contact_email', "Already in your list as \"{$dup->company_name}\".");
            }
        });

        return $validator->validate();
    }

    /** 404 for entries of other buyers, so IDs can't be probed. */
    private function findOwn(int $id): BuyerSupplier
    {
        return BuyerSupplier::where('buyer_org_id', $this->current->id())->findOrFail($id);
    }
}
