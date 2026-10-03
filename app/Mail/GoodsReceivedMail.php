<?php

namespace App\Mail;

use App\Models\GoodsReceipt;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** To the supplier: the buyer recorded a delivery (what was accepted, what was rejected and why). */
class GoodsReceivedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public GoodsReceipt $grn) {}

    public function envelope(): Envelope
    {
        $award = $this->grn->award;
        $rejected = $this->grn->rejectedQty() > 0;

        return new Envelope(subject: ($rejected ? 'Delivery received with rejections' : 'Delivery received').": {$award->po_number}");
    }

    public function content(): Content
    {
        $award = $this->grn->award;

        return new Content(markdown: 'mail.goods-received', with: [
            'grn' => $this->grn,
            'award' => $award,
            'buyer' => \App\Models\Organization::find($award->organization_id),
            'url' => route('supplier.orders.show', $award->id),
        ]);
    }
}
