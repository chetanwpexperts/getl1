<?php

namespace App\Services\Payables;

use App\Enums\AwardStatus;
use App\Mail\GoodsReceivedMail;
use App\Models\Award;
use App\Models\GoodsReceipt;
use App\Models\RfqInvite;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\RfqService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Goods receipts (GRN) against a PO. Part deliveries are fine; accepted quantity can never go
 * above what was ordered; every rejection needs a reason. Recording a receipt re-checks the due
 * dates of unpaid invoices on that PO and tells the supplier what was accepted or rejected.
 */
class ReceiptService
{
    public function __construct(private AuditLogger $audit) {}

    /** Per PO line: ordered, received, accepted, rejected and still pending; plus the accepted value. */
    public static function summary(Award $award): array
    {
        $receipts = GoodsReceipt::withoutGlobalScopes()->where('award_id', $award->id)->get();
        $lines = [];
        $acceptedValue = 0.0;
        foreach ($award->lines['items'] ?? [] as $line) {
            $id = $line['rfq_item_id'];
            $got = $receipts->flatMap(fn ($r) => collect($r->lines)->where('rfq_item_id', $id));
            $accepted = round((float) $got->sum('accepted'), 3);
            $acceptedValue += $accepted * (float) $line['unit_price'];
            $lines[$id] = [
                'name' => $line['name'], 'unit' => $line['unit'], 'rate' => (float) $line['unit_price'],
                'ordered' => (float) $line['qty'],
                'received' => round((float) $got->sum('received'), 3),
                'accepted' => $accepted,
                'rejected' => round((float) $got->sum('rejected'), 3),
                'pending' => max(0, round((float) $line['qty'] - $accepted, 3)),
            ];
        }

        return [
            'lines' => $lines,
            'accepted_value' => round($acceptedValue, 2),
            'complete' => collect($lines)->every(fn ($l) => $l['pending'] <= 0),
            'latest' => $receipts->max('received_on'),
        ];
    }

    /**
     * @param  array{received_on: string, challan_no?: ?string, notes?: ?string, lines: array<int|string, array{received?: mixed, rejected?: mixed, reason?: ?string}>}  $data
     */
    public function record(Award $award, User $by, array $data): GoodsReceipt
    {
        $receivedOn = Carbon::createFromFormat('Y-m-d', $data['received_on'], config('app.display_timezone'))->startOfDay();
        $today = now()->setTimezone(config('app.display_timezone'))->startOfDay();
        if ($receivedOn->gt($today)) {
            throw ValidationException::withMessages(['received_on' => 'The receipt date can’t be in the future.']);
        }
        if ($award->po_sent_at && $receivedOn->lt($award->po_sent_at->copy()->setTimezone(config('app.display_timezone'))->startOfDay())) {
            throw ValidationException::withMessages(['received_on' => 'The receipt date can’t be before the PO date.']);
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                $grn = DB::transaction(fn () => $this->create($award, $by, $data, $receivedOn));
                break;
            } catch (QueryException $e) {
                if ($attempt >= 3 || ! str_contains(strtolower($e->getMessage()), 'unique')) {
                    throw $e;
                }
            }
        }

        $invite = RfqInvite::with(['listEntry', 'supplier', 'rfq.organization'])->where('rfq_id', $award->rfq_id)->where('supplier_org_id', $award->supplier_org_id)->first();
        if ($invite && ($email = RfqService::recipientEmail($invite))) {
            Mail::to($email)->queue(new GoodsReceivedMail($grn));
        }

        return $grn;
    }

    private function create(Award $award, User $by, array $data, Carbon $receivedOn): GoodsReceipt
    {
        $award = Award::withoutGlobalScopes()->whereKey($award->id)->lockForUpdate()->firstOrFail();
        if ($award->status !== AwardStatus::PoSent) {
            throw ValidationException::withMessages(['lines' => 'Goods can be received only against an issued purchase order.']);
        }
        $summary = self::summary($award);
        $lines = [];
        foreach ($summary['lines'] as $id => $s) {
            $in = $data['lines'][$id] ?? [];
            $received = round((float) ($in['received'] ?? 0), 3);
            $rejected = round((float) ($in['rejected'] ?? 0), 3);
            if ($received < 0 || $rejected < 0) {
                throw ValidationException::withMessages(["lines.{$id}.received" => 'Quantities can’t be negative.']);
            }
            if ($received == 0 && $rejected == 0) {
                continue;
            }
            if ($rejected > $received) {
                throw ValidationException::withMessages(["lines.{$id}.rejected" => "Rejected can’t be more than received for {$s['name']}."]);
            }
            $accepted = round($received - $rejected, 3);
            if ($accepted > $s['pending'] + 0.0005) {
                throw ValidationException::withMessages(["lines.{$id}.received" => "{$s['name']}: only {$s['pending']} {$s['unit']} is still pending on this PO. Record extra quantity as rejected, or raise a new PO."]);
            }
            $reason = trim((string) ($in['reason'] ?? ''));
            if ($rejected > 0 && mb_strlen($reason) < 3) {
                throw ValidationException::withMessages(["lines.{$id}.reason" => "Say why {$s['name']} was rejected; the supplier sees it."]);
            }
            $lines[] = ['rfq_item_id' => (int) $id, 'name' => $s['name'], 'unit' => $s['unit'], 'received' => $received,
                'accepted' => $accepted, 'rejected' => $rejected, 'reason' => $rejected > 0 ? mb_substr($reason, 0, 255) : null];
        }
        if (! $lines) {
            throw ValidationException::withMessages(['lines' => 'Enter the quantity received for at least one item.']);
        }

        $grn = GoodsReceipt::create([
            'award_id' => $award->id,
            'organization_id' => $award->organization_id,
            'supplier_org_id' => $award->supplier_org_id,
            'grn_number' => $this->nextNumber($award->organization_id),
            'received_on' => $receivedOn->toDateString(),
            'lines' => $lines,
            'challan_no' => filled($data['challan_no'] ?? null) ? mb_substr(trim($data['challan_no']), 0, 60) : null,
            'notes' => filled($data['notes'] ?? null) ? mb_substr(trim($data['notes']), 0, 1000) : null,
            'received_by' => $by->id,
        ]);
        $this->audit->log('grn_recorded', $grn, after: [
            'grn_number' => $grn->grn_number, 'po_number' => $award->po_number,
            'accepted' => collect($lines)->sum('accepted'), 'rejected' => collect($lines)->sum('rejected'),
        ], user: $by, organizationId: $award->organization_id);

        // Acceptance moves the legal due date of unpaid invoices on this PO.
        foreach (SupplierInvoice::withoutGlobalScopes()->where('award_id', $award->id)->whereIn('status', [SupplierInvoice::SUBMITTED, SupplierInvoice::APPROVED])->get() as $inv) {
            $due = MsmeDueDate::for($award, $inv->invoice_date->copy(), $receivedOn->copy());
            $inv->update(['due_date' => $due['due_date']->toDateString(), 'due_basis' => $due['basis'], 'is_msme' => $due['is_msme']]);
        }

        return $grn;
    }

    /** GRN-2026-0001, sequential per buyer company per year. */
    private function nextNumber(int $orgId): string
    {
        $prefix = 'GRN-'.now()->year.'-';
        $last = GoodsReceipt::withoutGlobalScopes()->where('organization_id', $orgId)->where('grn_number', 'like', $prefix.'%')->orderByDesc('grn_number')->value('grn_number');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 4, '0', STR_PAD_LEFT);
    }
}
