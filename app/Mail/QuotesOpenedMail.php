<?php

namespace App\Mail;

use App\Models\Rfq;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** To the buyer team when the quote deadline passes. */
class QuotesOpenedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Rfq $rfq, public array $summary) {}

    public function envelope(): Envelope
    {
        $n = $this->summary['quoted'];

        return new Envelope(subject: ($n ? "{$n} ".str('quote')->plural($n).' received' : 'No quotes received').": {$this->rfq->title} ({$this->rfq->ref_no})");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.quotes-opened', with: [
            'rfq' => $this->rfq,
            's' => $this->summary,
            'url' => route('buyer.rfqs.show', $this->rfq->id),
            'auctionUrl' => route('buyer.auctions.create', $this->rfq->id),
        ]);
    }
}
