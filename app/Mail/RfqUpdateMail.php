<?php

namespace App\Mail;

use App\Models\RfqInvite;
use App\Services\RfqService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Deadline extended or RFQ cancelled. */
class RfqUpdateMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public RfqInvite $invite, public string $kind, public ?string $reason = null) {}

    public function envelope(): Envelope
    {
        $rfq = $this->invite->rfq;
        $what = $this->kind === 'cancelled' ? 'Cancelled' : 'Deadline extended';

        return new Envelope(subject: "{$what}: {$rfq->title} ({$rfq->organization->name})");
    }

    public function content(): Content
    {
        $rfq = $this->invite->rfq;

        return new Content(markdown: 'mail.rfq-update', with: [
            'rfq' => $rfq,
            'buyer' => $rfq->organization,
            'kind' => $this->kind,
            'reason' => $this->reason,
            'url' => RfqService::inviteUrl($this->invite),
            'deadline' => $rfq->quote_deadline?->ist()->format('d M Y, h:i A').' IST',
        ]);
    }
}
