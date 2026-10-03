<?php

namespace App\Services\Payables;

use App\Enums\AwardStatus;
use App\Mail\InvoiceDecisionMail;
use App\Mail\InvoiceSubmittedMail;
use App\Models\Award;
use App\Models\Organization;
use App\Models\Rfq;
use App\Models\RfqInvite;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Automations;
use App\Services\FileGuard;
use App\Services\RfqService;
use App\Support\IndianIds;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Supplier invoices against a PO: upload → automatic checks → buyer approves or disputes → paid.
 *
 * Checks (3-way match plus GST): invoiced value within the PO; within what was received and
 * accepted; GST rate as on the PO; GSTIN as on the supplier's profile; total = taxable + GST.
 * Approving with a failed check needs a note. Every step is audit-logged and emailed.
 */
class InvoiceService
{
    public const MAX_KB = 10240;

    public function __construct(private AuditLogger $audit, private FileGuard $guard) {}

    public static function rules(): array
    {
        return [
            'invoice_number' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9\/-]+$/'],
            'invoice_date' => ['required', 'date_format:Y-m-d'],
            'taxable_amount' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
            'gst_amount' => ['required', 'numeric', 'min:0', 'max:99999999999'],
            'total_amount' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
            'supplier_gstin' => ['nullable', 'string', 'size:15'],
            'file' => ['required', 'file', 'max:'.self::MAX_KB],
        ];
    }

    public static function messages(): array
    {
        return [
            // GST rule 46: up to 16 characters, letters, digits, '-' and '/'.
            'invoice_number.regex' => 'Invoice numbers can use letters, digits, “-” and “/” (as per GST rules).',
            'invoice_number.max' => 'GST invoice numbers are at most 16 characters.',
            'file.required' => 'Attach the invoice (PDF, JPG or PNG).',
        ];
    }

    public function submit(Award $award, User $by, array $data, UploadedFile $file): SupplierInvoice
    {
        if ($award->status !== AwardStatus::PoSent) {
            throw ValidationException::withMessages(['invoice_number' => 'Invoices can be uploaded only against an issued purchase order.']);
        }
        if (! $award->supplier_accepted_at) {
            throw ValidationException::withMessages(['invoice_number' => 'Accept the purchase order first, then upload your invoice.']);
        }

        $tz = config('app.display_timezone');
        $date = Carbon::createFromFormat('Y-m-d', $data['invoice_date'], $tz)->startOfDay();
        if ($date->gt(now()->setTimezone($tz)->startOfDay())) {
            throw ValidationException::withMessages(['invoice_date' => 'The invoice date can’t be in the future.']);
        }
        if ($award->po_sent_at && $date->lt($award->po_sent_at->copy()->setTimezone($tz)->startOfDay())) {
            throw ValidationException::withMessages(['invoice_date' => 'The invoice date can’t be before the PO date ('.$award->po_sent_at->ist()->format('d M Y').').']);
        }

        $taxable = round((float) $data['taxable_amount'], 2);
        $gst = round((float) $data['gst_amount'], 2);
        $total = round((float) $data['total_amount'], 2);
        if (abs($taxable + $gst - $total) > 1.0) {
            throw ValidationException::withMessages(['total_amount' => 'Total should be taxable value + GST ('.Money::inr($taxable + $gst).').']);
        }
        $gstin = filled($data['supplier_gstin'] ?? null) ? strtoupper(trim($data['supplier_gstin'])) : null;
        if ($gstin && ! IndianIds::isValidGstin($gstin)) {
            throw ValidationException::withMessages(['supplier_gstin' => 'This GSTIN isn’t valid. Check it against the invoice.']);
        }

        $ext = $this->guard->check($file, [FileGuard::PDF, FileGuard::JPG, FileGuard::PNG], self::MAX_KB, 'supplier_invoice');
        $path = $file->storeAs('invoices/'.$award->organization_id.'/'.$award->id, Str::uuid().'.'.$ext, 'local');

        try {
            $invoice = DB::transaction(function () use ($award, $by, $data, $date, $taxable, $gst, $total, $gstin, $file, $ext, $path) {
                $award = Award::withoutGlobalScopes()->whereKey($award->id)->lockForUpdate()->firstOrFail();
                $number = strtoupper(trim($data['invoice_number']));
                if (SupplierInvoice::withoutGlobalScopes()->where('supplier_org_id', $award->supplier_org_id)->where('invoice_number', $number)->exists()) {
                    throw ValidationException::withMessages(['invoice_number' => "Invoice {$number} has already been uploaded. Each invoice number can be used once."]);
                }
                $po = (float) $award->total + (float) $award->freight_total;
                $already = (float) SupplierInvoice::withoutGlobalScopes()->where('award_id', $award->id)->where('status', '!=', SupplierInvoice::DISPUTED)->sum('taxable_amount');
                if ($already + $taxable > $po * 1.02 + 1) {
                    throw ValidationException::withMessages(['taxable_amount' => 'This would take the invoiced value to '.Money::inr($already + $taxable).', above the PO value of '.Money::inr($po).'. Please check the amount, or ask the buyer for a revised PO.']);
                }

                $latest = ReceiptService::summary($award)['latest'];
                $due = MsmeDueDate::for($award, $date->copy(), $latest ? Carbon::parse($latest)->startOfDay() : null);

                $inv = SupplierInvoice::create([
                    'award_id' => $award->id,
                    'organization_id' => $award->organization_id,
                    'supplier_org_id' => $award->supplier_org_id,
                    'invoice_number' => $number,
                    'invoice_date' => $date->toDateString(),
                    'taxable_amount' => $taxable,
                    'gst_amount' => $gst,
                    'total_amount' => $total,
                    'supplier_gstin' => $gstin,
                    'file_path' => $path,
                    'original_name' => FileGuard::safeName($file->getClientOriginalName(), $ext),
                    'status' => SupplierInvoice::SUBMITTED,
                    'is_msme' => $due['is_msme'],
                    'due_date' => $due['due_date']->toDateString(),
                    'due_basis' => $due['basis'],
                    'submitted_by' => $by->id,
                ]);
                $this->audit->log('invoice_submitted', $inv, after: [
                    'invoice_number' => $number, 'po_number' => $award->po_number, 'total' => $total, 'due_date' => $inv->due_date->toDateString(), 'msme' => $due['is_msme'],
                ], user: $by, organizationId: $award->organization_id);

                return $inv;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path); // nothing recorded: don't keep the file
            if ($e instanceof QueryException && str_contains(strtolower($e->getMessage()), 'unique')) {
                throw ValidationException::withMessages(['invoice_number' => 'This invoice number has already been uploaded.']);
            }
            throw $e;
        }

        $rfq = Rfq::withoutGlobalScopes()->with('organization')->findOrFail($award->rfq_id);
        foreach (app(Automations::class)->buyerTeam($rfq) as $user) {
            Mail::to($user->email)->queue(new InvoiceSubmittedMail($invoice));
        }

        return $invoice;
    }

    /**
     * @return list<array{key: string, ok: bool, label: string, detail: string}>
     */
    public static function checks(SupplierInvoice $inv): array
    {
        $award = $inv->award;
        $supplier = Organization::find($inv->supplier_org_id);
        $summary = ReceiptService::summary($award);
        $po = (float) $award->total + (float) $award->freight_total;
        $cumulative = (float) SupplierInvoice::withoutGlobalScopes()->where('award_id', $award->id)->where('status', '!=', SupplierInvoice::DISPUTED)
            ->where('id', '<=', $inv->id)->sum('taxable_amount');
        $received = $summary['accepted_value'] + ($summary['complete'] ? (float) $award->freight_total : 0);
        $tol = fn ($v) => max(1.0, $v * 0.005);
        $rate = (float) $award->total > 0 ? (float) $award->gst_total / (float) $award->total : 0;
        $expectedGst = round((float) $inv->taxable_amount * $rate, 2);

        return [
            ['key' => 'po', 'ok' => $cumulative <= $po + $tol($po), 'label' => 'Within PO value',
                'detail' => 'Invoiced so far '.Money::inr($cumulative).' of '.Money::inr($po)],
            ['key' => 'received', 'ok' => $received > 0 && $cumulative <= $received + $tol($received), 'label' => 'Goods received',
                'detail' => $received > 0 ? 'Accepted goods worth '.Money::inr($received).' so far' : 'No goods receipt recorded yet'],
            ['key' => 'gst', 'ok' => abs((float) $inv->gst_amount - $expectedGst) <= max(1.0, $expectedGst * 0.01), 'label' => 'GST as per PO',
                'detail' => 'Expected about '.Money::inr($expectedGst).' at the PO rate; invoice shows '.Money::inr($inv->gst_amount)],
            ['key' => 'gstin', 'ok' => ! $supplier?->gstin || ! $inv->supplier_gstin || $supplier->gstin === $inv->supplier_gstin, 'label' => 'Supplier GSTIN',
                'detail' => $inv->supplier_gstin ? ($supplier?->gstin && $supplier->gstin !== $inv->supplier_gstin ? "Invoice shows {$inv->supplier_gstin}, profile has {$supplier->gstin}" : $inv->supplier_gstin) : 'Not entered'],
        ];
    }

    public static function matched(SupplierInvoice $inv): bool
    {
        return collect(self::checks($inv))->every(fn ($c) => $c['ok']);
    }

    public function approve(SupplierInvoice $inv, User $by, ?string $note = null): SupplierInvoice
    {
        $note = trim((string) $note);
        $inv = $this->decide($inv, $by, SupplierInvoice::APPROVED, function (SupplierInvoice $i) use ($note) {
            if (! self::matched($i) && mb_strlen($note) < 5) {
                throw ValidationException::withMessages(['review_note' => 'Some checks didn’t pass. Add a note saying why you’re approving it anyway (kept in the audit record).']);
            }
        }, $note);

        return $inv;
    }

    public function dispute(SupplierInvoice $inv, User $by, string $note): SupplierInvoice
    {
        if (mb_strlen(trim($note)) < 5) {
            throw ValidationException::withMessages(['review_note' => 'Tell the supplier what’s wrong so they can send a corrected invoice.']);
        }

        return $this->decide($inv, $by, SupplierInvoice::DISPUTED, null, trim($note));
    }

    public function markPaid(SupplierInvoice $inv, User $by, string $paidOn, string $amount, ?string $ref): SupplierInvoice
    {
        $tz = config('app.display_timezone');
        $on = Carbon::createFromFormat('Y-m-d', $paidOn, $tz)->startOfDay();
        $amt = round((float) $amount, 2);

        $inv = DB::transaction(function () use ($inv, $by, $on, $amt, $ref, $tz) {
            $i = SupplierInvoice::withoutGlobalScopes()->whereKey($inv->id)->lockForUpdate()->firstOrFail();
            if ($i->status !== SupplierInvoice::APPROVED) {
                throw ValidationException::withMessages(['paid_on' => 'Only an approved invoice can be marked paid.']);
            }
            if ($on->gt(now()->setTimezone($tz)->startOfDay()) || $on->lt($i->invoice_date)) {
                throw ValidationException::withMessages(['paid_on' => 'The payment date must be between the invoice date and today.']);
            }
            if ($amt <= 0 || $amt > (float) $i->total_amount + 1) {
                throw ValidationException::withMessages(['paid_amount' => 'Enter the amount paid (up to the invoice total; less if TDS was deducted).']);
            }
            $i->update(['status' => SupplierInvoice::PAID, 'paid_on' => $on->toDateString(), 'paid_amount' => $amt,
                'payment_ref' => $ref ? mb_substr(trim($ref), 0, 60) : null, 'paid_marked_by' => $by->id]);
            $late = $i->due_date && $on->gt($i->due_date);
            $this->audit->log('invoice_paid', $i, after: ['invoice_number' => $i->invoice_number, 'amount' => $amt, 'paid_on' => $on->toDateString(),
                'ref' => $i->payment_ref, 'late' => $late, 'msme' => $i->is_msme], user: $by, organizationId: $i->organization_id);

            return $i;
        });
        $this->tellSupplier($inv);

        return $inv;
    }

    private function decide(SupplierInvoice $inv, User $by, string $to, ?\Closure $guard, ?string $note): SupplierInvoice
    {
        $inv = DB::transaction(function () use ($inv, $by, $to, $guard, $note) {
            $i = SupplierInvoice::withoutGlobalScopes()->whereKey($inv->id)->lockForUpdate()->firstOrFail();
            if ($i->status !== SupplierInvoice::SUBMITTED) {
                throw ValidationException::withMessages(['review_note' => 'This invoice has already been reviewed.']);
            }
            if ($guard) {
                $guard($i);
            }
            $i->update(['status' => $to, 'review_note' => $note !== '' ? $note : null, 'reviewed_by' => $by->id, 'reviewed_at' => now()]);
            $this->audit->log($to === SupplierInvoice::APPROVED ? 'invoice_approved' : 'invoice_disputed', $i,
                after: ['invoice_number' => $i->invoice_number, 'note' => $i->review_note], user: $by, organizationId: $i->organization_id);

            return $i;
        });
        $this->tellSupplier($inv);

        return $inv;
    }

    private function tellSupplier(SupplierInvoice $inv): void
    {
        $award = $inv->award;
        $invite = RfqInvite::with(['listEntry', 'supplier', 'rfq.organization'])->where('rfq_id', $award->rfq_id)->where('supplier_org_id', $inv->supplier_org_id)->first();
        if ($invite && ($email = RfqService::recipientEmail($invite))) {
            Mail::to($email)->queue(new InvoiceDecisionMail($inv));
        }
    }
}
