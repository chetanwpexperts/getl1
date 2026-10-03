<?php

namespace App\Mail;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/** Daily, to buyer admins: MSME invoices due within 7 days or overdue (45-day rule). */
class PaymentsDueMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param Collection<int, \App\Models\SupplierInvoice> $invoices */
    public function __construct(public Organization $buyer, public Collection $invoices) {}

    public function envelope(): Envelope
    {
        $overdue = $this->invoices->filter->isOverdue()->count();

        return new Envelope(subject: $overdue
            ? "{$overdue} MSME ".str('invoice')->plural($overdue).' overdue: pay now to keep your tax deduction'
            : 'MSME payments due this week');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.payments-due', with: [
            'buyer' => $this->buyer,
            'invoices' => $this->invoices,
            'url' => route('buyer.payments.index'),
        ]);
    }
}
