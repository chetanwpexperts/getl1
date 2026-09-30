<?php

namespace App\Mail;

use App\Models\Rfq;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Courtesy note to suppliers who quoted but weren't selected. No prices or names. */
class NotSelectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Rfq $rfq, public int $supplierOrgId) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Update on {$this->rfq->ref_no}: {$this->rfq->title}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.not-selected', with: ['rfq' => $this->rfq, 'buyer' => $this->rfq->organization]);
    }
}
