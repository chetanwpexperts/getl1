<?php

namespace App\Mail;

use App\Models\RfqInvite;
use App\Models\RfqQuestion;
use App\Services\RfqService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** To suppliers: the buyer answered a question or posted a clarification. Never names who asked. */
class RfqClarificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public RfqInvite $invite, public RfqQuestion $question, public bool $mine = false) {}

    public function envelope(): Envelope
    {
        $rfq = $this->invite->rfq;

        return new Envelope(subject: ($this->mine ? 'Your question was answered' : 'Clarification')." on {$rfq->ref_no}: {$rfq->title}");
    }

    public function content(): Content
    {
        $rfq = $this->invite->rfq;

        return new Content(markdown: 'mail.rfq-clarification', with: [
            'rfq' => $rfq,
            'buyer' => $rfq->organization,
            'question' => $this->question->question,
            'answer' => $this->question->answer,
            'mine' => $this->mine,
            'private' => $this->question->visibility === RfqQuestion::PRIVATE,
            'deadline' => $rfq->quote_deadline?->ist()->format('d M Y, h:i A').' IST',
            'url' => RfqService::inviteUrl($this->invite),
        ]);
    }
}
