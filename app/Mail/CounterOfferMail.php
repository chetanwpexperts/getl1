<?php

namespace App\Mail;

use App\Models\CounterOffer;
use App\Models\RfqInvite;
use App\Services\RfqService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** To a supplier: the buyer made a counter-offer. */
class CounterOfferMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public CounterOffer $offer, public RfqInvite $invite) {}

    public function envelope(): Envelope
    {
        $rfq = $this->invite->rfq;

        return new Envelope(subject: "Counter-offer from {$rfq->organization->name}: {$rfq->title}");
    }

    public function content(): Content
    {
        $rfq = $this->invite->rfq;

        return new Content(markdown: 'mail.counter-offer', with: [
            'rfq' => $rfq,
            'buyer' => $rfq->organization,
            'offer' => $this->offer,
            'expires' => $this->offer->expires_at->ist()->format('d M Y, h:i A').' IST',
            'url' => RfqService::inviteUrl($this->invite).'#counter-offer',
        ]);
    }
}
