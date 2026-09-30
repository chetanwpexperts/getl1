<?php

namespace App\Services;

use App\Enums\AuctionStatus;
use App\Enums\InviteStatus;
use App\Enums\OrgRole;
use App\Enums\RfqStatus;
use App\Mail\AuctionReminderMail;
use App\Mail\AuctionResultBuyerMail;
use App\Mail\AuctionResultSupplierMail;
use App\Mail\QuotesOpenedMail;
use App\Mail\RfqReminderMail;
use App\Models\Auction;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\RfqInvite;
use App\Models\User;
use App\Services\Auction\Standings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * The next step of every flow happens on its own. Runs every minute from the scheduler.
 *
 * - Suppliers who haven't quoted get one reminder before the deadline.
 * - When the deadline passes, the buyer team gets the result: how many quoted, L1, savings.
 * - Auction participants get a reminder shortly before the start.
 * - When an auction closes, the buyer gets the outcome and each supplier their final rank.
 *
 * Each message is "claimed" with a conditional UPDATE on its marker column before it is
 * queued, so it goes out exactly once even if two runs overlap.
 */
class Automations
{
    public const AUCTION_REMINDER_MINUTES = 15;

    /** @return array{reminders:int, quotes_opened:int, auction_reminders:int, auction_results:int} */
    public function run(): array
    {
        return [
            'reminders' => $this->remindSuppliers(),
            'quotes_opened' => $this->announceQuotesOpened(),
            'auction_reminders' => $this->remindAuctionParticipants(),
            'auction_results' => $this->announceAuctionResults(),
        ];
    }

    /**
     * One reminder per supplier that hasn't quoted. Sent when a quarter of the quote window
     * is left, but no earlier than 2 hours and no later than 10 minutes before the deadline.
     */
    public function remindSuppliers(): int
    {
        $sent = 0;
        $rfqs = Rfq::withoutGlobalScopes()
            ->where('status', RfqStatus::Published->value)
            ->where('quote_deadline', '>', now())
            ->where('quote_deadline', '<=', now()->addHours(2))
            ->get();

        foreach ($rfqs as $rfq) {
            $window = max(1, (int) ($rfq->published_at ?? $rfq->created_at)->diffInMinutes($rfq->quote_deadline, true));
            $lead = min(120, max(10, intdiv($window, 4)));
            if (now()->diffInMinutes($rfq->quote_deadline, true) > $lead) {
                continue;
            }

            $quoted = Quote::where('rfq_id', $rfq->id)->whereNotNull('submitted_at')->pluck('supplier_org_id')->all();
            $invites = RfqInvite::with(['listEntry', 'supplier', 'rfq.organization'])
                ->where('rfq_id', $rfq->id)
                ->where('status', '!=', InviteStatus::Declined->value)
                ->whereNull('reminded_at')
                ->get()
                ->reject(fn ($i) => $i->supplier_org_id && in_array($i->supplier_org_id, $quoted));

            foreach ($invites as $invite) {
                $email = RfqService::recipientEmail($invite);
                if (! $email || ! $this->claim(RfqInvite::query(), $invite->id, 'reminded_at')) {
                    continue;
                }
                Mail::to($email)->queue(new RfqReminderMail($invite));
                $sent++;
            }
        }

        return $sent;
    }

    /** Deadline passed: tell the buyer team what came in, with the next step. */
    public function announceQuotesOpened(): int
    {
        $sent = 0;
        $rfqs = Rfq::withoutGlobalScopes()
            ->whereIn('status', [RfqStatus::Published->value, RfqStatus::Auction->value, RfqStatus::Evaluating->value])
            ->where('quote_deadline', '<=', now())
            ->where('quote_deadline', '>=', now()->subDay()) // never mail about old RFQs
            ->whereNull('quotes_opened_notified_at')
            ->get();

        foreach ($rfqs as $rfq) {
            if (! $this->claim(Rfq::withoutGlobalScopes(), $rfq->id, 'quotes_opened_notified_at')) {
                continue;
            }
            $summary = $this->quoteSummary($rfq);
            foreach ($this->buyerTeam($rfq) as $user) {
                Mail::to($user->email)->queue(new QuotesOpenedMail($rfq, $summary));
                $sent++;
            }
        }

        return $sent;
    }

    public function remindAuctionParticipants(): int
    {
        $sent = 0;
        $auctions = Auction::withoutGlobalScopes()
            ->where('status', AuctionStatus::Scheduled->value)
            ->where('starts_at', '>', now())
            ->where('starts_at', '<=', now()->addMinutes(self::AUCTION_REMINDER_MINUTES))
            ->whereNull('start_reminded_at')
            // Scheduled only minutes ago: the "scheduled" email is still fresh, skip the reminder.
            ->where('created_at', '<=', now()->subMinutes(self::AUCTION_REMINDER_MINUTES))
            ->get();

        foreach ($auctions as $auction) {
            if (! $this->claim(Auction::withoutGlobalScopes(), $auction->id, 'start_reminded_at')) {
                continue;
            }
            foreach ($this->participantInvites($auction) as $invite) {
                if ($email = RfqService::recipientEmail($invite)) {
                    Mail::to($email)->queue(new AuctionReminderMail($auction, $invite->supplier_org_id));
                    $sent++;
                }
            }
        }

        return $sent;
    }

    public function announceAuctionResults(): int
    {
        $sent = 0;
        $auctions = Auction::withoutGlobalScopes()
            ->where('status', AuctionStatus::Closed->value)
            ->where('ends_at', '>=', now()->subDay())
            ->whereNull('results_notified_at')
            ->get();

        foreach ($auctions as $auction) {
            if (! $this->claim(Auction::withoutGlobalScopes(), $auction->id, 'results_notified_at')) {
                continue;
            }
            $rfq = Rfq::withoutGlobalScopes()->with('organization')->findOrFail($auction->rfq_id);
            $standings = Standings::for($auction);

            foreach ($this->buyerTeam($rfq) as $user) {
                Mail::to($user->email)->queue(new AuctionResultBuyerMail($auction));
                $sent++;
            }
            foreach ($this->participantInvites($auction) as $invite) {
                $row = $standings->firstWhere('supplier_org_id', $invite->supplier_org_id);
                if ($row && ($email = RfqService::recipientEmail($invite))) {
                    Mail::to($email)->queue(new AuctionResultSupplierMail($auction, $invite->supplier_org_id, $row['rank'], $row['amount'], $standings->count()));
                    $sent++;
                }
            }
        }

        return $sent;
    }

    /** @return array{invited:int, quoted:int, declined:int, l1: ?array{supplier:string, basic:float, landed:float}, last_total: ?float, can_auction: bool} */
    public function quoteSummary(Rfq $rfq): array
    {
        $comparison = app(RfqService::class)->comparison($rfq);
        $l1 = $comparison->first();
        $items = $rfq->items()->get();
        $lastTotal = $items->isNotEmpty() && $items->every(fn ($i) => $i->last_purchase_price !== null)
            ? (float) $items->sum(fn ($i) => (float) $i->last_purchase_price * (float) $i->qty) : null;

        return [
            'invited' => RfqInvite::where('rfq_id', $rfq->id)->count(),
            'quoted' => $comparison->count(),
            'declined' => RfqInvite::where('rfq_id', $rfq->id)->where('status', InviteStatus::Declined->value)->count(),
            'l1' => $l1 ? ['supplier' => $l1['quote']->supplier->name, 'basic' => $l1['basic'], 'landed' => $l1['landed']] : null,
            'last_total' => $lastTotal,
            'can_auction' => $comparison->count() >= \App\Services\Auction\AuctionService::MIN_PARTICIPANTS,
        ];
    }

    /** People who run purchasing for this RFQ: its creator plus the company's admins and buyers. */
    public function buyerTeam(Rfq $rfq): Collection
    {
        $org = $rfq->organization ?? \App\Models\Organization::findOrFail($rfq->organization_id);

        return $org->users()
            ->wherePivotIn('role', [OrgRole::BuyerAdmin->value, OrgRole::BuyerUser->value])
            ->get()
            ->when($rfq->created_by, fn ($c) => $c->push(User::find($rfq->created_by)))
            ->filter(fn ($u) => $u && $u->email)
            ->unique('id')
            ->values();
    }

    private function participantInvites(Auction $auction): Collection
    {
        $orgIds = \App\Models\Bid::where('auction_id', $auction->id)->distinct()->pluck('supplier_org_id');

        return RfqInvite::with(['listEntry', 'supplier'])
            ->where('rfq_id', $auction->rfq_id)->whereIn('supplier_org_id', $orgIds)->get();
    }

    /** Atomically set a "sent" marker; false if another run already did. */
    private function claim($query, int $id, string $column): bool
    {
        return $query->whereKey($id)->whereNull($column)->update([$column => now()]) === 1;
    }
}
