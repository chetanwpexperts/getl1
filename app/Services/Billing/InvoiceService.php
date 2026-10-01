<?php

namespace App\Services\Billing;

use App\Models\Payment;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;

/**
 * GetL1's invoices to its customers. Numbered per Indian financial year (GL1/2026-27/0001).
 * "Tax invoice" with GST when GST is enabled; otherwise a payment receipt.
 */
class InvoiceService
{
    public function nextNumber(): string
    {
        $ist = now()->setTimezone(config('app.display_timezone'));
        $fyStart = $ist->month >= 4 ? $ist->year : $ist->year - 1;
        $prefix = config('billing.invoice_prefix').'/'.$fyStart.'-'.substr((string) ($fyStart + 1), 2).'/';

        $last = Payment::where('invoice_number', 'like', $prefix.'%')->orderByDesc('invoice_number')->value('invoice_number');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 4, '0', STR_PAD_LEFT);
    }

    public function store(Payment $payment): void
    {
        $path = "invoices/{$payment->organization_id}/".str_replace('/', '-', $payment->invoice_number).'.pdf';
        Storage::disk('local')->put($path, $this->render($payment));
        $payment->forceFill(['invoice_pdf_path' => $path])->save();
    }

    public function render(Payment $payment): string
    {
        $billed = $payment->billed_to ?? [];
        $buyerState = ! empty($billed['gstin']) ? substr($billed['gstin'], 0, 2) : null;

        $html = view('pdf.invoice', [
            'p' => $payment,
            'billed' => $billed,
            'seller' => config('billing.seller'),
            'taxInvoice' => (float) $payment->gst_amount > 0,
            // Same state as GetL1 → CGST + SGST; otherwise (or unknown) IGST.
            'intraState' => $buyerState !== null && $buyerState === config('billing.seller.state_code'),
        ])->render();

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot([resource_path()]);
        $options->setTempDir(sys_get_temp_dir());
        $options->setFontCache(sys_get_temp_dir());

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return $pdf->output();
    }
}
