<?php

namespace App\Mail;

use App\Models\Award;
use App\Models\Organization;
use App\Models\Rfq;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AwardApprovalRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Award $award) {}

    public function envelope(): Envelope
    {
        $rfq = Rfq::withoutGlobalScopes()->find($this->award->rfq_id);

        return new Envelope(subject: "Approval needed: award for {$rfq?->title} ({$rfq?->ref_no})");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.award-approval-request', with: [
            'award' => $this->award,
            'rfq' => Rfq::withoutGlobalScopes()->find($this->award->rfq_id),
            'supplier' => Organization::find($this->award->supplier_org_id),
            'by' => User::find($this->award->awarded_by),
            'url' => route('buyer.rfqs.show', $this->award->rfq_id).'#award',
        ]);
    }
}
