<?php

namespace App\Mail;

use App\Models\RfqQuestion;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** To the buyer team: a supplier asked a question on an open RFQ. */
class RfqQuestionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public RfqQuestion $question) {}

    public function envelope(): Envelope
    {
        $rfq = $this->question->rfq;

        return new Envelope(subject: "Supplier question on {$rfq->ref_no}: {$rfq->title}");
    }

    public function content(): Content
    {
        $q = $this->question;

        return new Content(markdown: 'mail.rfq-question', with: [
            'rfq' => $q->rfq,
            'supplier' => $q->supplier?->name ?? 'A supplier',
            'question' => $q->question,
            'deadline' => $q->rfq->quote_deadline?->ist()->format('d M Y, h:i A').' IST',
            'url' => route('buyer.rfqs.show', $q->rfq_id).'#questions',
        ]);
    }
}
