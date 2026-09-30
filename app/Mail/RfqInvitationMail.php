<?php

namespace App\Mail;

use App\Models\RfqInvite;
use App\Services\RfqService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RfqInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public RfqInvite $invite) {}

    public function envelope(): Envelope
    {
        $rfq = $this->invite->rfq;

        return new Envelope(subject: "Quote request from {$rfq->organization->name}: {$rfq->title}");
    }

    public function content(): Content
    {
        $rfq = $this->invite->rfq;

        return new Content(markdown: 'mail.rfq-invitation', with: [
            'rfq' => $rfq,
            'buyer' => $rfq->organization,
            'items' => $rfq->items()->get(['name', 'qty', 'unit']),
            'url' => RfqService::inviteUrl($this->invite),
            'deadline' => $rfq->quote_deadline?->ist()->format('d M Y, h:i A').' IST',
        ]);
    }
}
