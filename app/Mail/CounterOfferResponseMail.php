<?php

namespace App\Mail;

use App\Models\CounterOffer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** To the buyer team: the supplier accepted or declined the counter-offer. */
class CounterOfferResponseMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public CounterOffer $offer) {}

    public function envelope(): Envelope
    {
        $what = $this->offer->status === CounterOffer::ACCEPTED ? 'accepted' : 'declined';

        return new Envelope(subject: "Counter-offer {$what} by {$this->offer->supplier->name}: {$this->offer->rfq->ref_no}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.counter-offer-response', with: [
            'offer' => $this->offer,
            'rfq' => $this->offer->rfq,
            'supplier' => $this->offer->supplier->name,
            'accepted' => $this->offer->status === CounterOffer::ACCEPTED,
            'url' => route('buyer.rfqs.show', $this->offer->rfq_id).'#award',
        ]);
    }
}
