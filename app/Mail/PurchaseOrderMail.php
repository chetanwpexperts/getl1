<?php

namespace App\Mail;

use App\Models\Award;
use App\Models\Organization;
use App\Models\Rfq;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** To the winning supplier, with the PO PDF attached. */
class PurchaseOrderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Award $award) {}

    public function envelope(): Envelope
    {
        $buyer = Organization::find($this->award->organization_id);

        return new Envelope(subject: "Purchase order {$this->award->po_number} from {$buyer?->name}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.purchase-order', with: [
            'award' => $this->award,
            'buyer' => Organization::find($this->award->organization_id),
            'rfq' => Rfq::withoutGlobalScopes()->find($this->award->rfq_id),
            'url' => route('supplier.orders.show', $this->award->id),
        ]);
    }

    public function attachments(): array
    {
        return [
            Attachment::fromStorageDisk('local', $this->award->po_pdf_path)
                ->as($this->award->po_number.'.pdf')->withMime('application/pdf'),
        ];
    }
}
