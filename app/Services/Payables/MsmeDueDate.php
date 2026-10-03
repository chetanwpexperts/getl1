<?php

namespace App\Services\Payables;

use App\Models\Award;
use App\Models\BuyerSupplier;
use App\Models\Organization;
use App\Models\Rfq;
use Illuminate\Support\Carbon;

/**
 * When an invoice must be paid.
 *
 * MSME suppliers (MSMED Act, section 15): within the agreed credit period but never more than
 * 45 days from the day the goods were accepted; with no agreed credit period, within 15 days.
 * Paying later also costs the buyer the expense deduction for that year (Income Tax Act 43B(h)).
 *
 * Acceptance day: the goods receipt date. We take the earlier of the invoice date and the latest
 * goods receipt, so the date we show is never later than the legal one. Without a receipt, the
 * invoice date is used.
 *
 * Other suppliers: the agreed credit period from the invoice date (information only).
 */
class MsmeDueDate
{
    public const MSME_MAX_DAYS = 45;
    public const MSME_NO_AGREEMENT_DAYS = 15;

    /** Agreed credit days from the RFQ payment terms; null when none was agreed. */
    public static function agreedDays(Rfq $rfq): ?int
    {
        $term = $rfq->terms['payment'] ?? null;

        return match (true) {
            $term === 'advance', $term === 'on_delivery' => 0,
            is_string($term) && preg_match('/^credit_(\d+)$/', $term, $m) === 1 => (int) $m[1],
            default => null,
        };
    }

    /** MSME if the supplier gave its Udyam number, or the buyer marked it MSME in its supplier list. */
    public static function isMsme(Organization $supplier, int $buyerOrgId): bool
    {
        if (filled($supplier->udyam_no)) {
            return true;
        }

        return BuyerSupplier::where('buyer_org_id', $buyerOrgId)->where('supplier_org_id', $supplier->id)->where('is_msme', true)->exists();
    }

    /** @return array{due_date: Carbon, is_msme: bool, basis: string} */
    public static function for(Award $award, Carbon $invoiceDate, ?Carbon $latestReceipt): array
    {
        $rfq = Rfq::withoutGlobalScopes()->findOrFail($award->rfq_id);
        $supplier = Organization::findOrFail($award->supplier_org_id);
        $msme = self::isMsme($supplier, $award->organization_id);
        $agreed = self::agreedDays($rfq);

        $from = $latestReceipt && $latestReceipt->lt($invoiceDate) ? $latestReceipt->copy() : $invoiceDate->copy();
        $fromLabel = $latestReceipt && $latestReceipt->lt($invoiceDate) ? 'goods receipt' : 'invoice date';

        if ($msme) {
            if ($agreed === null) {
                $days = self::MSME_NO_AGREEMENT_DAYS;
                $basis = "MSME supplier, no credit period agreed: {$days} days from {$fromLabel}";
            } elseif ($agreed > self::MSME_MAX_DAYS) {
                $days = self::MSME_MAX_DAYS;
                $basis = "MSME supplier: agreed {$agreed} days is capped at 45 by law, from {$fromLabel}";
            } else {
                $days = $agreed;
                $basis = "MSME supplier: agreed {$agreed} days from {$fromLabel}";
            }
        } else {
            $days = $agreed ?? 30;
            $basis = $agreed === null ? 'No credit period agreed: 30 days from invoice date (not MSME)' : "Agreed {$agreed} days from invoice date (not MSME)";
            $from = $invoiceDate->copy();
        }

        return ['due_date' => $from->startOfDay()->addDays($days), 'is_msme' => $msme, 'basis' => $basis];
    }

    /**
     * MSME status changed (Udyam number added or removed, or the buyer ticked/unticked MSME):
     * re-work the due date of every unpaid invoice from that supplier (optionally for one buyer).
     */
    public static function refreshSupplier(int $supplierOrgId, ?int $buyerOrgId = null): int
    {
        $n = 0;
        $invoices = \App\Models\SupplierInvoice::withoutGlobalScopes()->where('supplier_org_id', $supplierOrgId)
            ->when($buyerOrgId, fn ($q) => $q->where('organization_id', $buyerOrgId))
            ->whereIn('status', [\App\Models\SupplierInvoice::SUBMITTED, \App\Models\SupplierInvoice::APPROVED])->get();
        foreach ($invoices as $inv) {
            $award = $inv->award;
            $latest = ReceiptService::summary($award)['latest'];
            $due = self::for($award, Carbon::parse($inv->invoice_date->toDateString()), $latest ? Carbon::parse($latest)->startOfDay() : null);
            $inv->update(['due_date' => $due['due_date']->toDateString(), 'due_basis' => $due['basis'], 'is_msme' => $due['is_msme']]);
            $n++;
        }

        return $n;
    }
}
