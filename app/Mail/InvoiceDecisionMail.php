<?php

namespace App\Mail;

use App\Models\SupplierInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** To the supplier: invoice approved (with due date), disputed (with reason), or paid. */
class InvoiceDecisionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public SupplierInvoice $invoice) {}

    public function envelope(): Envelope
    {
        $what = ['approved' => 'approved', 'disputed' => 'needs correction', 'paid' => 'paid'][$this->invoice->status] ?? $this->invoice->status;

        return new Envelope(subject: "Invoice {$this->invoice->invoice_number} {$what}: {$this->invoice->buyer->name}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.invoice-decision', with: [
            'inv' => $this->invoice,
            'award' => $this->invoice->award,
            'buyer' => $this->invoice->buyer,
            'url' => route('supplier.orders.show', $this->invoice->award_id).'#invoices',
        ]);
    }
}
