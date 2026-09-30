<?php

namespace App\Mail;

use App\Models\Auction;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Each participant's own final position. Never other suppliers' names; L1 only if the buyer allowed it. */
class AuctionResultSupplierMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Auction $auction, public int $supplierOrgId, public int $rank, public float $amount, public int $participants) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Auction result: you finished L{$this->rank}: {$this->auction->rfq->title}");
    }

    public function content(): Content
    {
        $a = $this->auction;

        return new Content(markdown: 'mail.auction-result-supplier', with: [
            'rfq' => $a->rfq,
            'buyer' => $a->rfq->organization,
            'rank' => $this->rank,
            'amount' => $this->amount,
            'participants' => $this->participants,
            'l1' => $a->visibility === 'rank_and_l1' ? (float) $a->current_l1 : null,
            'url' => route('supplier.auctions.show', $a->id),
        ]);
    }
}
