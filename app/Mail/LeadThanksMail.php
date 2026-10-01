<?php

namespace App\Mail;

use App\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** To the person who filled the form: confirmation and what happens next. */
class LeadThanksMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Lead $lead) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Thanks for your interest in GetL1');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.lead-thanks', with: ['lead' => $this->lead]);
    }
}
