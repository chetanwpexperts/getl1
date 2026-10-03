<?php

namespace App\Mail;

use App\Models\Auction;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AuctionScheduledMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Auction $auction, public int $supplierOrgId) {}

    public function envelope(): Envelope
    {
        $rfq = $this->auction->rfq;

        return new Envelope(subject: "Live auction on {$this->auction->starts_at->ist()->format('d M, h:i A')} IST: {$rfq->title}");
    }

    public function content(): Content
    {
        $rfq = $this->auction->rfq;

        return new Content(markdown: 'mail.auction-scheduled', with: [
            'rfq' => $rfq,
            'buyer' => $rfq->organization,
            'auction' => $this->auction,
            'url' => route('supplier.auctions.show', $this->auction->id),
            'starts' => $this->auction->starts_at->ist()->format('d M Y, h:i A').' IST',
            'duration' => $this->auction->starts_at->diffInMinutes($this->auction->ends_at),
            'practiceUrl' => route('supplier.auctions.practice'),
        ]);
    }
}
