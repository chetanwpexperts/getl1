<?php

namespace App\Mail;

use App\Enums\AwardStatus;
use App\Models\Award;
use App\Models\Organization;
use App\Models\Rfq;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** To the person who made the award: approved or rejected. */
class AwardDecisionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Award $award) {}

    public function envelope(): Envelope
    {
        $rfq = Rfq::withoutGlobalScopes()->find($this->award->rfq_id);
        $what = $this->award->status === AwardStatus::Rejected ? 'Award rejected' : 'Award approved';

        return new Envelope(subject: "{$what}: {$rfq?->title} ({$rfq?->ref_no})");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.award-decision', with: [
            'award' => $this->award,
            'rejected' => $this->award->status === AwardStatus::Rejected,
            'rfq' => Rfq::withoutGlobalScopes()->find($this->award->rfq_id),
            'supplier' => Organization::find($this->award->supplier_org_id),
            'by' => User::find($this->award->approved_by),
            'url' => route('buyer.rfqs.show', $this->award->rfq_id).'#award',
        ]);
    }
}
