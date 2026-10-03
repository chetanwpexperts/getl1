<?php

namespace App\Mail;

use App\Models\SupplierInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** To the buyer team: a supplier uploaded an invoice to review. */
class InvoiceSubmittedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public SupplierInvoice $invoice) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Invoice {$this->invoice->invoice_number} from {$this->invoice->supplier->name} to review");
    }

    public function content(): Content
    {
        $inv = $this->invoice;

        return new Content(markdown: 'mail.invoice-submitted', with: [
            'inv' => $inv,
            'award' => $inv->award,
            'checks' => \App\Services\Payables\InvoiceService::checks($inv),
            'url' => route('buyer.orders.show', $inv->award_id).'#invoices',
        ]);
    }
}
