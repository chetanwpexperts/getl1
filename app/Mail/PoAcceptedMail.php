<?php

namespace App\Mail;

use App\Models\Award;
use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PoAcceptedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Award $award) {}

    public function envelope(): Envelope
    {
        $s = Organization::find($this->award->supplier_org_id);

        return new Envelope(subject: "{$s?->name} accepted purchase order {$this->award->po_number}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.po-accepted', with: [
            'award' => $this->award,
            'supplier' => Organization::find($this->award->supplier_org_id),
            'url' => route('buyer.rfqs.show', $this->award->rfq_id).'#award',
        ]);
    }
}
