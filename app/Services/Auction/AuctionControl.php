<?php

namespace App\Services\Auction;

use App\Enums\AuctionStatus;
use App\Enums\RfqStatus;
use App\Mail\AuctionNoticeMail;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\Organization;
use App\Models\Rfq;
use App\Models\RfqInvite;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Automations;
use App\Services\SecurityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Emergency controls for GetL1 staff when something goes wrong technically during an auction:
 * pause and resume (the clock stops; nobody loses time), add time, or cancel with a reason.
 *
 * Staff never change bids, prices or the winner. Every action is row-locked, audit-logged
 * with the reason, pushed to every open screen, and emailed to the buyer and the suppliers.
 */
class AuctionControl
{
    public const ADD_MINUTES = [2, 5, 10];
    public const MIN_LEFT_AFTER_RESUME_SEC = 120;

    public function __construct(private AuditLogger $audit, private AuctionBroadcaster $broadcaster, private BidService $bids) {}

    public function pause(Auction $auction, User $admin, string $reason): Auction
    {
        $a = $this->locked($auction, function (Auction $a) use ($admin, $reason) {
            if ($a->status !== AuctionStatus::Live || $a->paused_at !== null) {
                $this->refuse('Only a running auction can be paused.');
            }
            $a->update(['paused_at' => now(), 'pause_reason' => $reason]);
            $this->log('auction_paused_by_getl1', $a, $admin, ['reason' => $reason, 'time_left_sec' => intdiv($a->remainingMs(), 1000)]);
        });
        $this->notify($a, 'paused', $reason);

        return $a;
    }

    public function resume(Auction $auction, User $admin): Auction
    {
        $a = $this->locked($auction, function (Auction $a) use ($admin) {
            if (! $a->isPaused()) {
                $this->refuse('This auction is not paused.');
            }
            $pausedFor = (int) ceil($a->paused_at->diffInSeconds(now(), true));
            // Everyone gets back exactly the time that was left, and never less than two minutes.
            $endsAt = $a->ends_at->copy()->addSeconds($pausedFor);
            $floor = now()->addSeconds(self::MIN_LEFT_AFTER_RESUME_SEC);
            $a->update([
                'ends_at' => $endsAt->lt($floor) ? $floor : $endsAt,
                'paused_at' => null,
                'paused_seconds' => $a->paused_seconds + $pausedFor,
            ]);
            $this->log('auction_resumed_by_getl1', $a, $admin, ['paused_for_sec' => $pausedFor, 'ends_at' => $a->ends_at->toIso8601String()]);
        });
        $this->notify($a, 'resumed', null);

        return $a;
    }

    public function addTime(Auction $auction, User $admin, int $minutes, string $reason): Auction
    {
        if (! in_array($minutes, self::ADD_MINUTES, true)) {
            $this->refuse('Choose 2, 5 or 10 minutes.');
        }
        $a = $this->locked($auction, function (Auction $a) use ($admin, $minutes, $reason) {
            if ($a->status !== AuctionStatus::Live) {
                $this->refuse('Time can only be added to a running auction.');
            }
            if ($a->isJapanese()) {
                $this->refuse('A Japanese auction runs in fixed rounds. Pause it instead if suppliers need time.');
            }
            $before = $a->ends_at->copy();
            $a->update(['ends_at' => $a->ends_at->copy()->addMinutes($minutes)]);
            $this->log('auction_time_added_by_getl1', $a, $admin, ['minutes' => $minutes, 'reason' => $reason,
                'ends_at_before' => $before->toIso8601String(), 'ends_at' => $a->ends_at->toIso8601String()]);
        });
        $this->notify($a, 'extended', $reason, $minutes);

        return $a;
    }

    public function cancel(Auction $auction, User $admin, string $reason): Auction
    {
        $a = $this->locked($auction, function (Auction $a) use ($admin, $reason) {
            if (! in_array($a->status, [AuctionStatus::Scheduled, AuctionStatus::Live], true)) {
                $this->refuse('Only a scheduled or running auction can be cancelled.');
            }
            $wasLive = $a->status === AuctionStatus::Live;
            $a->update(['status' => AuctionStatus::Cancelled, 'cancel_reason' => 'Cancelled by GetL1: '.$reason,
                'paused_at' => null, 'closed_at' => $wasLive ? now() : null]);
            if ($a->paid_with_credit) {
                Organization::whereKey($a->organization_id)->increment('auction_credits'); // didn't complete: credit back
            }
            // The buyer can schedule a fresh auction from the same sealed quotes.
            Rfq::withoutGlobalScopes()->whereKey($a->rfq_id)->where('status', RfqStatus::Auction->value)
                ->update(['status' => RfqStatus::Published->value]);
            $this->log('auction_cancelled_by_getl1', $a, $admin, ['reason' => $reason, 'was_live' => $wasLive,
                'bids' => $a->bid_count, 'credit_refunded' => (bool) $a->paid_with_credit]);
            SecurityLog::warning('auction_cancelled_by_getl1', ['auction_id' => $a->id]);
        });
        $this->notify($a, 'cancelled', $reason);

        return $a;
    }

    /** Runs $change on a row-locked, clock-synced copy, then pushes the new state to every screen. */
    private function locked(Auction $auction, \Closure $change): Auction
    {
        $a = DB::transaction(function () use ($auction, $change) {
            $a = Auction::withoutGlobalScopes()->whereKey($auction->id)->lockForUpdate()->firstOrFail();
            $this->bids->syncStatus($a);
            $change($a);

            return $a->fresh();
        });
        $this->broadcaster->push($a);

        return $a;
    }

    private function log(string $action, Auction $a, User $admin, array $after): void
    {
        $this->audit->log($action, $a, after: $after, user: $admin, organizationId: $a->organization_id);
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['auction' => $message]);
    }

    /** Buyer team and every participating supplier hear what happened and why. */
    private function notify(Auction $a, string $kind, ?string $reason, ?int $minutes = null): void
    {
        try {
            $rfq = Rfq::withoutGlobalScopes()->with('organization')->findOrFail($a->rfq_id);
            foreach (app(Automations::class)->buyerTeam($rfq) as $u) {
                Mail::to($u->email)->queue(new AuctionNoticeMail($a, $kind, $reason, $minutes, null));
            }
            $orgIds = Bid::where('auction_id', $a->id)->distinct()->pluck('supplier_org_id');
            $invites = RfqInvite::with(['listEntry', 'supplier'])->where('rfq_id', $a->rfq_id)->whereIn('supplier_org_id', $orgIds)->get();
            foreach ($invites as $inv) {
                $email = $inv->listEntry?->contact_email ?: $inv->supplier?->email;
                if ($email) {
                    Mail::to($email)->queue(new AuctionNoticeMail($a, $kind, $reason, $minutes, (int) $inv->supplier_org_id));
                }
            }
        } catch (\Throwable $e) {
            Log::warning('auction_notice_failed', ['auction_id' => $a->id, 'kind' => $kind, 'error' => $e->getMessage()]);
        }
    }
}
