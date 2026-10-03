<?php

namespace App\Mail;

use App\Models\PurchaseRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** 'submitted' → to approvers: a request needs a decision. 'decided' → to the requester: approved or rejected. */
class PurchaseRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public PurchaseRequest $pr, public string $kind) {}

    public function envelope(): Envelope
    {
        $subject = match (true) {
            $this->kind === 'submitted' => "Purchase request to approve: {$this->pr->pr_number}",
            $this->pr->status === PurchaseRequest::APPROVED => "Your request was approved: {$this->pr->pr_number}",
            default => "Your request was rejected: {$this->pr->pr_number}",
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $pr = $this->pr->loadMissing(['requester', 'decider']);

        return new Content(markdown: 'mail.purchase-request', with: [
            'pr' => $pr,
            'kind' => $this->kind,
            'url' => route('buyer.requests.show', $pr->id),
        ]);
    }
}
