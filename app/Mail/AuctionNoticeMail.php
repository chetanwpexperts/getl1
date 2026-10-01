<?php

namespace App\Mail;

use App\Models\Auction;
use App\Models\Rfq;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Paused / resumed / extended / cancelled by GetL1: to the buyer team or one supplier. */
class AuctionNoticeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Auction $auction, public string $kind, public ?string $reason, public ?int $minutes, public ?int $supplierOrgId) {}

    public function envelope(): Envelope
    {
        $rfq = Rfq::withoutGlobalScopes()->find($this->auction->rfq_id);
        $what = ['paused' => 'paused', 'resumed' => 'resumed', 'extended' => 'extended', 'cancelled' => 'cancelled'][$this->kind] ?? 'updated';

        return new Envelope(subject: "Live auction {$what}: {$rfq?->title}");
    }

    public function content(): Content
    {
        $rfq = Rfq::withoutGlobalScopes()->find($this->auction->rfq_id);

        return new Content(markdown: 'mail.auction-notice', with: [
            'auction' => $this->auction, 'rfq' => $rfq, 'kind' => $this->kind, 'reason' => $this->reason, 'minutes' => $this->minutes,
            'forSupplier' => $this->supplierOrgId !== null,
            'url' => $this->supplierOrgId !== null ? route('supplier.auctions.show', $this->auction->id) : route('buyer.auctions.show', $this->auction->id),
        ]);
    }
}
