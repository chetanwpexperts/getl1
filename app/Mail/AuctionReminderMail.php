<?php

namespace App\Mail;

use App\Models\Auction;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AuctionReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Auction $auction, public int $supplierOrgId) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Starting soon: live auction at {$this->auction->starts_at->ist()->format('h:i A')} IST: {$this->auction->rfq->title}");
    }

    public function content(): Content
    {
        $rfq = $this->auction->rfq;

        return new Content(markdown: 'mail.auction-reminder', with: [
            'rfq' => $rfq,
            'buyer' => $rfq->organization,
            'url' => route('supplier.auctions.show', $this->auction->id),
            'starts' => $this->auction->starts_at->ist()->format('d M Y, h:i A').' IST',
        ]);
    }
}
