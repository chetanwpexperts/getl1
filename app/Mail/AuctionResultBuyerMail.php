<?php

namespace App\Mail;

use App\Models\Auction;
use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AuctionResultBuyerMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Auction $auction) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Auction closed: {$this->auction->rfq->title} ({$this->auction->rfq->ref_no})");
    }

    public function content(): Content
    {
        $a = $this->auction;

        return new Content(markdown: 'mail.auction-result-buyer', with: [
            'rfq' => $a->rfq,
            'a' => $a,
            'winner' => Organization::find($a->current_l1_supplier_org_id)?->name,
            'savings' => (float) $a->start_price - (float) $a->current_l1,
            'savingsPct' => $a->savingsPct(),
            'url' => route('buyer.auctions.show', $a->id),
        ]);
    }
}
