<?php

namespace App\Mail;

use App\Models\RfqInvite;
use App\Services\RfqService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Supplier hasn't quoted yet and the deadline is close. */
class RfqReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public RfqInvite $invite) {}

    public function envelope(): Envelope
    {
        $rfq = $this->invite->rfq;

        return new Envelope(subject: "Reminder: quotes close {$rfq->quote_deadline->ist()->format('d M, h:i A')} IST: {$rfq->title}");
    }

    public function content(): Content
    {
        $rfq = $this->invite->rfq;

        return new Content(markdown: 'mail.rfq-reminder', with: [
            'rfq' => $rfq,
            'buyer' => $rfq->organization,
            'url' => RfqService::inviteUrl($this->invite),
            'deadline' => $rfq->quote_deadline->ist()->format('d M Y, h:i A').' IST',
            'left' => $rfq->quote_deadline->diffForHumans(now(), \Carbon\CarbonInterface::DIFF_ABSOLUTE, false, 2),
        ]);
    }
}
