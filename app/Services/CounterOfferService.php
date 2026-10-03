<?php

namespace App\Services;

use App\Mail\CounterOfferMail;
use App\Mail\CounterOfferResponseMail;
use App\Models\CounterOffer;
use App\Models\Rfq;
use App\Models\RfqInvite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Counter-offers before award (lot RFQs).
 *
 * - The buyer offers one supplier a lower price, valid for a few hours. One open offer per RFQ.
 * - The supplier accepts or declines; an accepted offer becomes that supplier's price in the
 *   award ranking and on the PO. Nothing changes if it's declined or expires.
 * - Awarding withdraws any open offer. Every step is audit-logged on the RFQ timeline.
 */
class CounterOfferService
{
    public const HOURS = [2, 24, 48];

    public function __construct(private AuditLogger $audit, private AwardService $awards) {}

    public function offer(Rfq $rfq, User $by, int $supplierOrgId, string $amountInput, int $hours, ?string $message = null): CounterOffer
    {
        $amount = round((float) str_replace([',', ' ', '₹'], '', $amountInput), 2);
        if (! in_array($hours, self::HOURS, true)) {
            throw ValidationException::withMessages(['hours' => 'Choose how long the offer stays open.']);
        }

        $offer = DB::transaction(function () use ($rfq, $by, $supplierOrgId, $amount, $hours, $message) {
            $rfq = Rfq::withoutGlobalScopes()->whereKey($rfq->id)->lockForUpdate()->firstOrFail();
            if ($rfq->isPerItem()) {
                throw ValidationException::withMessages(['offered_amount' => 'Counter-offers are for RFQs awarded as one lot.']);
            }
            if ($why = $this->awards->blocker($rfq)) {
                throw ValidationException::withMessages(['offered_amount' => $why]);
            }
            if (CounterOffer::where('rfq_id', $rfq->id)->where('status', CounterOffer::PENDING)->where('expires_at', '>', now())->exists()) {
                throw ValidationException::withMessages(['offered_amount' => 'There is already an open counter-offer on this RFQ. Withdraw it or wait for the answer.']);
            }

            $candidate = $this->awards->candidates($rfq)->firstWhere('supplier.id', $supplierOrgId);
            if (! $candidate) {
                throw ValidationException::withMessages(['supplier_org_id' => 'Choose one of the suppliers who quoted.']);
            }
            $current = (float) $candidate['basic'];
            if ($amount >= $current - 0.009) {
                throw ValidationException::withMessages(['offered_amount' => 'The counter-offer must be lower than the supplier\'s current price ('.\App\Support\Money::inr($current).').']);
            }
            if ($amount < $current * 0.5) {
                throw ValidationException::withMessages(['offered_amount' => 'That is more than 50% below the supplier\'s price. Check for a typo.']);
            }

            $offer = CounterOffer::create([
                'rfq_id' => $rfq->id,
                'organization_id' => $rfq->organization_id,
                'supplier_org_id' => $supplierOrgId,
                'auction_id' => $candidate['auction_id'],
                'current_amount' => $current,
                'offered_amount' => $amount,
                'message' => $message ? trim(mb_substr($message, 0, 1000)) : null,
                'status' => CounterOffer::PENDING,
                'expires_at' => now()->addHours($hours),
                'offered_by' => $by->id,
            ]);
            $this->audit->log('counter_offer_sent', $offer, after: [
                'supplier' => $candidate['supplier']->name, 'from' => $current, 'to' => $amount, 'hours' => $hours,
            ], user: $by, organizationId: $rfq->organization_id);

            return $offer;
        });

        $invite = RfqInvite::with(['listEntry', 'supplier', 'rfq.organization'])->where('rfq_id', $rfq->id)->where('supplier_org_id', $supplierOrgId)->first();
        if ($invite && ($email = RfqService::recipientEmail($invite))) {
            Mail::to($email)->queue(new CounterOfferMail($offer, $invite));
        }

        return $offer;
    }

    public function withdraw(CounterOffer $offer, User $by): void
    {
        DB::transaction(function () use ($offer, $by) {
            $o = CounterOffer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
            if (! $o->isOpen()) {
                throw ValidationException::withMessages(['offer' => 'This counter-offer is no longer open.']);
            }
            $o->update(['status' => CounterOffer::WITHDRAWN, 'responded_at' => now()]);
            $this->audit->log('counter_offer_withdrawn', $o, user: $by, organizationId: $o->organization_id);
        });
    }

    public function respond(CounterOffer $offer, User $by, bool $accept, ?string $note = null): CounterOffer
    {
        $offer = DB::transaction(function () use ($offer, $by, $accept, $note) {
            $o = CounterOffer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
            if (! $o->isOpen()) {
                throw ValidationException::withMessages(['offer' => $o->displayStatus() === 'expired'
                    ? 'This counter-offer has expired.' : 'This counter-offer is no longer open.']);
            }
            if ($why = $this->awards->blocker($o->rfq)) {
                throw ValidationException::withMessages(['offer' => 'The buyer can no longer use this offer: '.$why]);
            }
            $o->update([
                'status' => $accept ? CounterOffer::ACCEPTED : CounterOffer::DECLINED,
                'responded_by' => $by->id,
                'responded_at' => now(),
                'response_note' => $note ? trim(mb_substr($note, 0, 1000)) : null,
            ]);
            $this->audit->log($accept ? 'counter_offer_accepted' : 'counter_offer_declined', $o,
                after: ['amount' => (float) $o->offered_amount, 'note' => $o->response_note], user: $by, organizationId: $o->organization_id);

            return $o;
        });

        foreach (app(Automations::class)->buyerTeam($offer->rfq) as $user) {
            Mail::to($user->email)->queue(new CounterOfferResponseMail($offer));
        }

        return $offer;
    }
}
