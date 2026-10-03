<?php

namespace App\Http\Controllers\Buyer;

use App\Enums\AwardStatus;
use App\Http\Controllers\Controller;
use App\Models\Award;
use App\Models\Organization;
use App\Services\AuditLogger;
use App\Services\Exports\TallyExporter;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The buyer's purchase-order register: every issued PO across RFQs, with filters, and exports
 * for Tally (masters + vouchers) and Excel. Award is scoped to the buyer's company.
 */
class OrderController extends Controller
{
    public const MAX_EXPORT = 2000;

    public function __construct(private CurrentOrganization $current, private AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $query = $this->query($filters);

        return view('buyer.orders.index', [
            'orders' => (clone $query)->with(['supplier:id,name,gstin', 'rfq:id,ref_no,title'])->orderByDesc('po_sent_at')->orderByDesc('id')->paginate(25)->withQueryString(),
            'totals' => (clone $query)->toBase()->selectRaw('count(*) as n, coalesce(sum(total),0) as basic, coalesce(sum(grand_total),0) as grand')->first(),
            'filters' => $filters,
            'suppliers' => Organization::whereIn('id', Award::where('status', AwardStatus::PoSent)->select('supplier_org_id'))->orderBy('name')->get(['id', 'name']),
            'tally' => TallyExporter::settings($this->current->get()),
            'canEdit' => $request->user()->hasRoleIn($this->current->get(), 'buyer_admin', 'buyer_user'),
        ]);
    }

    public function tallyMasters(Request $request, TallyExporter $tally): Response
    {
        $awards = $this->forExport($request);

        return $this->xml($tally->masters($awards, $this->current->get()), 'masters', $awards->count());
    }

    public function tallyVouchers(Request $request, TallyExporter $tally): Response
    {
        $awards = $this->forExport($request);

        return $this->xml($tally->vouchers($awards, $this->current->get()), 'purchase-orders', $awards->count());
    }

    /** Purchase register: one row per PO line, opens in Excel (UTF-8 with BOM, formula-safe cells). */
    public function csv(Request $request): StreamedResponse
    {
        $awards = $this->forExport($request);
        $this->audit->log('po_exported', $this->current->get(), after: ['format' => 'excel', 'orders' => $awards->count()]);
        $safe = fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v;
        $n = fn ($v) => number_format((float) $v, 2, '.', '');

        return response()->streamDownload(function () use ($awards, $safe, $n) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, ['PO number', 'PO date', 'RFQ', 'RFQ title', 'Supplier', 'Supplier GSTIN', 'Supplier state', 'Item', 'Qty', 'Unit',
                'Rate (₹)', 'Amount before GST (₹)', 'GST %', 'GST (₹)', 'Freight (₹)', 'PO total incl. GST (₹)', 'Source', 'Accepted by supplier']);
            foreach ($awards as $a) {
                foreach ($a->lines['items'] ?? [] as $i => $line) {
                    fputcsv($out, array_map($safe, [
                        $a->po_number, $a->po_sent_at?->ist()->format('Y-m-d'), $a->rfq?->ref_no, $a->rfq?->title,
                        $a->supplier?->name, $a->supplier?->gstin, $a->supplier?->state,
                        $line['name'], rtrim(rtrim(number_format((float) $line['qty'], 3, '.', ''), '0'), '.'), $line['unit'],
                        rtrim(rtrim(number_format((float) $line['unit_price'], 4, '.', ''), '0'), '.'), $n($line['amount']),
                        $line['gst_rate'] ?? '', $n($line['gst'] ?? 0), $n($line['freight'] ?? 0),
                        $i === 0 ? $n($a->grand_total) : '', $a->source === 'auction' ? 'Live auction' : 'Sealed quote',
                        $a->supplier_accepted_at?->ist()->format('Y-m-d H:i') ?? 'No',
                    ]));
                }
            }
            fclose($out);
        }, 'purchase-register-'.now()->ist()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function saveTally(Request $request): RedirectResponse
    {
        $rules = [];
        foreach (array_keys(TallyExporter::DEFAULTS) as $key) {
            $rules[$key] = ['nullable', 'string', 'max:90', 'regex:/^[^<>&"\x00-\x1F]*$/u'];
        }
        $data = $request->validate($rules, ['*.regex' => 'Ledger names can\'t contain < > & or quotes.']);
        $org = $this->current->get();
        $before = TallyExporter::settings($org);
        $org->update(['tally_settings' => array_map(fn ($v) => $v === null ? null : trim($v), $data)]);
        $this->audit->log('tally_settings_changed', $org, before: $before, after: TallyExporter::settings($org->fresh()));

        return redirect()->to(route('buyer.orders.index', $request->query()).'#tally')->with('status', 'Tally ledger names saved.');
    }

    /** @return array{from: ?string, to: ?string, supplier: ?int, accepted: ?string} */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'supplier' => ['nullable', 'integer'],
            'accepted' => ['nullable', 'in:yes,no'],
        ]);

        return ['from' => $data['from'] ?? null, 'to' => $data['to'] ?? null, 'supplier' => isset($data['supplier']) ? (int) $data['supplier'] : null, 'accepted' => $data['accepted'] ?? null];
    }

    private function query(array $f): Builder
    {
        $tz = config('app.display_timezone');

        return Award::query()->where('status', AwardStatus::PoSent)->whereNotNull('po_number')
            ->when($f['from'], fn ($q, $d) => $q->where('po_sent_at', '>=', Carbon::createFromFormat('Y-m-d', $d, $tz)->startOfDay()->utc()))
            ->when($f['to'], fn ($q, $d) => $q->where('po_sent_at', '<=', Carbon::createFromFormat('Y-m-d', $d, $tz)->endOfDay()->utc()))
            ->when($f['supplier'], fn ($q, $id) => $q->where('supplier_org_id', $id))
            ->when($f['accepted'] === 'yes', fn ($q) => $q->whereNotNull('supplier_accepted_at'))
            ->when($f['accepted'] === 'no', fn ($q) => $q->whereNull('supplier_accepted_at'));
    }

    private function forExport(Request $request)
    {
        $query = $this->query($this->filters($request));
        abort_if((clone $query)->count() > self::MAX_EXPORT, 422, 'Too many purchase orders in one export. Narrow the dates and try again.');

        return $query->with(['supplier', 'rfq:id,ref_no,title'])->orderBy('po_sent_at')->orderBy('id')->get();
    }

    private function xml(string $xml, string $kind, int $count): Response
    {
        $this->audit->log('po_exported', $this->current->get(), after: ['format' => 'tally_'.$kind, 'orders' => $count]);

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="tally-'.$kind.'-'.now()->ist()->format('Y-m-d').'.xml"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
