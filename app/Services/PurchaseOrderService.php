<?php

namespace App\Services;

use App\Enums\AwardStatus;
use App\Enums\RfqStatus;
use App\Mail\NotSelectedMail;
use App\Mail\PurchaseOrderMail;
use App\Models\Award;
use App\Models\Organization;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\RfqInvite;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Issues the purchase order for an approved award: number, PDF, emails. Idempotent: running
 * it twice for the same award never creates a second PO or sends the emails again.
 */
class PurchaseOrderService
{
    public function __construct(private AuditLogger $audit) {}

    public function issue(int $awardId): ?Award
    {
        $award = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $award = DB::transaction(function () use ($awardId) {
                    $award = Award::withoutGlobalScopes()->whereKey($awardId)->lockForUpdate()->first();
                    if (! $award || $award->status !== AwardStatus::Approved || $award->po_number) {
                        return null; // not approved, or already issued
                    }

                    $award->po_number = $this->nextNumber($award->organization_id);
                    $path = "purchase-orders/{$award->organization_id}/{$award->po_number}.pdf";
                    $award->po_pdf_path = $path;
                    $award->status = AwardStatus::PoSent;
                    $award->po_sent_at = now();
                    Storage::disk('local')->put($path, $this->render($award));
                    $award->save();

                    Rfq::withoutGlobalScopes()->whereKey($award->rfq_id)->update(['status' => RfqStatus::Awarded->value]);
                    $this->audit->log('po_issued', $award, after: ['po_number' => $award->po_number, 'grand_total' => (float) $award->grand_total],
                        user: null, organizationId: $award->organization_id);

                    return $award;
                });
                break;
            } catch (QueryException $e) {
                // Two POs numbered at the same moment: the unique index caught it; take the next number.
                if ($attempt === 3 || ! str_contains(strtolower($e->getMessage()), 'unique')) {
                    throw $e;
                }
            }
        }

        if ($award) {
            $this->notify($award);
        }

        return $award;
    }

    /** PO-2026-0001, sequential per buyer company per year. */
    public function nextNumber(int $organizationId): string
    {
        $year = now()->year;
        $prefix = "PO-{$year}-";
        $last = Award::withoutGlobalScopes()->where('organization_id', $organizationId)
            ->where('po_number', 'like', $prefix.'%')->orderByDesc('po_number')->value('po_number');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 4, '0', STR_PAD_LEFT);
    }

    public function render(Award $award): string
    {
        $rfq = Rfq::withoutGlobalScopes()->findOrFail($award->rfq_id);
        $buyer = Organization::findOrFail($award->organization_id);
        $supplier = Organization::findOrFail($award->supplier_org_id);

        $html = view('pdf.purchase-order', [
            'award' => $award,
            'rfq' => $rfq,
            'buyer' => $buyer,
            'supplier' => $supplier,
            'intraState' => self::intraState($buyer, $supplier),
            'terms' => $this->terms($buyer, $rfq),
            'approver' => $award->approved_by ? \App\Models\User::find($award->approved_by)?->name : null,
        ])->render();

        $options = new Options;
        $options->setIsRemoteEnabled(false);          // never fetch anything from the network
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setDefaultFont('DejaVu Sans');       // has the ₹ sign
        $options->setChroot([resource_path()]);
        $options->setTempDir(sys_get_temp_dir());
        $options->setFontCache(sys_get_temp_dir()); // writable for web and CLI users alike

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->addInfo('Title', "Purchase Order {$award->po_number}");
        $pdf->addInfo('Author', $buyer->name);
        $pdf->addInfo('Creator', 'GetL1');
        $pdf->render();

        return $pdf->output();
    }

    /** Same state: CGST + SGST. Different states: IGST. Uses GSTIN state codes when both exist. */
    public static function intraState(Organization $buyer, Organization $supplier): bool
    {
        if ($buyer->gstin && $supplier->gstin) {
            return substr($buyer->gstin, 0, 2) === substr($supplier->gstin, 0, 2);
        }

        return $buyer->state && $supplier->state
            && mb_strtolower(trim($buyer->state)) === mb_strtolower(trim($supplier->state));
    }

    private function terms(Organization $buyer, Rfq $rfq): array
    {
        $lines = [];
        $t = $rfq->terms ?? [];
        if (! empty($t['payment'])) {
            $lines[] = 'Payment: '.(RfqService::PAYMENT_TERMS[$t['payment']] ?? $t['payment']).'.';
        }
        if (! empty($t['freight'])) {
            $lines[] = 'Freight: '.(RfqService::FREIGHT_TERMS[$t['freight']] ?? $t['freight']).'.';
        }
        if (! empty($t['delivery'])) {
            $lines[] = 'Delivery: '.$t['delivery'];
        }
        if (! empty($t['other'])) {
            $lines[] = $t['other'];
        }
        foreach (preg_split('/\R/', (string) $buyer->po_terms) as $line) {
            if (trim($line) !== '') {
                $lines[] = trim($line);
            }
        }
        if (! $buyer->po_terms) {
            $lines[] = 'Please quote this PO number on your invoice, delivery challan and all correspondence.';
            $lines[] = 'Goods must match the specification in the RFQ; the buyer may reject material that does not.';
            $lines[] = 'Invoice must show your GSTIN, HSN/SAC codes and the tax split as per GST rules.';
        }

        return $lines;
    }

    private function notify(Award $award): void
    {
        $rfq = Rfq::withoutGlobalScopes()->findOrFail($award->rfq_id);

        // Winner: the PO itself.
        $winner = RfqInvite::with(['listEntry', 'supplier'])->where('rfq_id', $rfq->id)->where('supplier_org_id', $award->supplier_org_id)->first();
        $to = $winner ? RfqService::recipientEmail($winner) : Organization::find($award->supplier_org_id)?->email;
        if ($to) {
            Mail::to($to)->queue(new PurchaseOrderMail($award));
        }

        // Everyone else who quoted: a courteous "not selected this time".
        $others = Quote::where('rfq_id', $rfq->id)->whereNotNull('submitted_at')
            ->where('supplier_org_id', '!=', $award->supplier_org_id)->pluck('supplier_org_id');
        RfqInvite::with(['listEntry', 'supplier'])->where('rfq_id', $rfq->id)->whereIn('supplier_org_id', $others)->get()
            ->each(function (RfqInvite $invite) use ($rfq) {
                if ($email = RfqService::recipientEmail($invite)) {
                    Mail::to($email)->queue(new NotSelectedMail($rfq, $invite->supplier_org_id));
                }
            });
    }
}
