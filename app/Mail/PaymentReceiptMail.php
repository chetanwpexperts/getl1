<?php

namespace App\Mail;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Payment $payment) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Payment received: {$this->payment->invoice_number}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.payment-receipt', with: ['p' => $this->payment, 'url' => route('buyer.billing.index')]);
    }

    public function attachments(): array
    {
        return $this->payment->invoice_pdf_path
            ? [Attachment::fromStorageDisk('local', $this->payment->invoice_pdf_path)->as(str_replace('/', '-', $this->payment->invoice_number).'.pdf')->withMime('application/pdf')]
            : [];
    }
}
