<?php

namespace App\Services;

use App\Enums\InviteStatus;
use App\Mail\RfqClarificationMail;
use App\Mail\RfqQuestionMail;
use App\Models\Rfq;
use App\Models\RfqInvite;
use App\Models\RfqQuestion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Clarifications while quotes are open.
 *
 * - Suppliers who haven't declined can ask until the quote deadline.
 * - The buyer answers to everyone (the asker's name is never shown to other suppliers) or only
 *   to the asker, and can post a clarification to everyone. Answers are final once posted.
 * - Everything is audit-logged on the buyer's RFQ timeline; the right people are emailed.
 */
class RfqQuestionService
{
    public const MAX_PER_SUPPLIER = 20;
    public const MAX_LENGTH = 2000;

    public function __construct(private AuditLogger $audit) {}

    public function ask(RfqInvite $invite, User $by, string $text): RfqQuestion
    {
        $text = $this->clean($text, 'question');
        $rfq = $invite->rfq;
        if ($invite->status === InviteStatus::Declined) {
            throw ValidationException::withMessages(['question' => 'You declined this RFQ.']);
        }
        if (! $rfq->isOpenForQuotes()) {
            throw ValidationException::withMessages(['question' => 'Questions can be asked only while quotes are open.']);
        }

        $question = DB::transaction(function () use ($invite, $rfq, $by, $text) {
            // Lock the invite so the limit can't be raced from two tabs.
            RfqInvite::whereKey($invite->id)->lockForUpdate()->first();
            $count = RfqQuestion::where('rfq_id', $rfq->id)->where('supplier_org_id', $invite->supplier_org_id)->count();
            if ($count >= self::MAX_PER_SUPPLIER) {
                throw ValidationException::withMessages(['question' => 'You can ask up to '.self::MAX_PER_SUPPLIER.' questions on one RFQ. Please wait for the answers, or contact the buyer directly.']);
            }

            $q = RfqQuestion::create([
                'rfq_id' => $rfq->id,
                'organization_id' => $rfq->organization_id,
                'supplier_org_id' => $invite->supplier_org_id,
                'asked_by' => $by->id,
                'question' => $text,
                'visibility' => RfqQuestion::ALL,
            ]);
            $this->audit->log('rfq_question_asked', $q, after: ['question' => Str::limit($text, 300)], user: $by, organizationId: $rfq->organization_id);

            return $q;
        });

        $team = app(Automations::class)->buyerTeam($rfq);
        foreach ($team as $user) {
            Mail::to($user->email)->queue(new RfqQuestionMail($question));
        }
        Notifier::toUsers($team, $rfq->organization_id, 'sourcing', "New question on {$rfq->ref_no}",
            Str::limit($question->question, 160).' Answer it so every supplier quotes on the same basis.', route('buyer.rfqs.show', $rfq->id).'#questions');

        return $question;
    }

    public function answer(RfqQuestion $question, User $by, string $text, string $visibility): RfqQuestion
    {
        $text = $this->clean($text, 'answer');
        $visibility = $visibility === RfqQuestion::PRIVATE ? RfqQuestion::PRIVATE : RfqQuestion::ALL;

        $question = DB::transaction(function () use ($question, $by, $text, $visibility) {
            $q = RfqQuestion::whereKey($question->id)->lockForUpdate()->firstOrFail();
            if ($q->isAnnouncement() || $q->isAnswered()) {
                throw ValidationException::withMessages(['answer' => 'This question has already been answered.']);
            }
            $this->assertOpen($q->rfq);

            $q->update(['answer' => $text, 'visibility' => $visibility, 'answered_by' => $by->id, 'answered_at' => now()]);
            $this->audit->log('rfq_question_answered', $q, after: ['answer' => Str::limit($text, 300), 'visibility' => $visibility],
                user: $by, organizationId: $q->organization_id);

            return $q;
        });

        $this->notify($question);

        return $question;
    }

    public function announce(Rfq $rfq, User $by, string $text): RfqQuestion
    {
        $text = $this->clean($text, 'clarification');
        $this->assertOpen($rfq);

        $entry = DB::transaction(function () use ($rfq, $by, $text) {
            $q = RfqQuestion::create([
                'rfq_id' => $rfq->id,
                'organization_id' => $rfq->organization_id,
                'answer' => $text,
                'visibility' => RfqQuestion::ALL,
                'answered_by' => $by->id,
                'answered_at' => now(),
            ]);
            $this->audit->log('rfq_clarification_posted', $q, after: ['text' => Str::limit($text, 300)], user: $by, organizationId: $rfq->organization_id);

            return $q;
        });

        $this->notify($entry);

        return $entry;
    }

    /** Answered to everyone: every supplier still in the RFQ. Private: only the one who asked. */
    private function notify(RfqQuestion $q): void
    {
        $invites = RfqInvite::with(['listEntry', 'supplier', 'rfq.organization'])->where('rfq_id', $q->rfq_id)
            ->where('status', '!=', InviteStatus::Declined->value)
            ->when($q->visibility === RfqQuestion::PRIVATE, fn ($w) => $w->where('supplier_org_id', $q->supplier_org_id))
            ->get();

        foreach ($invites as $invite) {
            if ($email = RfqService::recipientEmail($invite)) {
                Mail::to($email)->queue(new RfqClarificationMail($invite, $q, $invite->supplier_org_id !== null && $invite->supplier_org_id === $q->supplier_org_id));
            }
            $mine = $invite->supplier_org_id !== null && $invite->supplier_org_id === $q->supplier_org_id;
            Notifier::toOrg($invite->supplier_org_id, 'sourcing', ($mine ? 'Your question was answered: ' : 'New clarification: ').$invite->rfq->ref_no,
                Str::limit((string) $q->answer, 180), route('supplier.rfqs.show', $invite->id).'#questions');
        }
    }

    private function assertOpen(Rfq $rfq): void
    {
        if (! $rfq->isOpenForQuotes()) {
            throw ValidationException::withMessages(['answer' => 'Quotes are closed, so suppliers can no longer act on an answer. Extend the deadline first if you need to clarify something.']);
        }
    }

    private function clean(string $text, string $field): string
    {
        $text = trim(preg_replace("/\r\n?/", "\n", $text));
        if (mb_strlen($text) < 5) {
            throw ValidationException::withMessages([$field => 'Please write a little more (at least 5 characters).']);
        }
        if (mb_strlen($text) > self::MAX_LENGTH) {
            throw ValidationException::withMessages([$field => 'Please keep it under '.self::MAX_LENGTH.' characters.']);
        }

        return $text;
    }
}
