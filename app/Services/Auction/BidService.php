<?php

namespace App\Services\Auction;

use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\Organization;
use App\Models\User;
use App\Services\SecurityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Places live bids. Correctness rules:
 *
 * 1. One bid at a time per auction: the auction row is locked (SELECT … FOR UPDATE) for the
 *    whole check-and-write, so two bids in the same millisecond are processed in order.
 * 2. The bid is committed to MySQL before anyone is told it was accepted. Broadcasting
 *    happens only after commit, and a broadcast failure never undoes a bid.
 * 3. The server clock decides everything: start, end, auto-extend.
 * 4. Idempotency key per attempt: a retry or double-tap returns the original bid.
 * 5. Rules: must beat own price by the minimum decrement; can't drop below the fat-finger
 *    floor (current L1 − max %); one bid per supplier per second.
 * 6. Item-wise auctions: a bid is a unit price for one RFQ line. The same rules apply per line
 *    (own price on that line, that line's L1); auction.current_l1 becomes the combined best total.
 */
class BidService
{
    public const MIN_SECONDS_BETWEEN_BIDS = 1;

    /** Why the last attempt was refused (recorded in bid_rejections). */
    private ?string $reason = null;

    public function __construct(private AuctionBroadcaster $broadcaster) {}

    /**
     * @return array{bid: Bid, duplicate: bool, extended: bool}
     */
    public function place(Auction $auction, Organization $supplier, User $user, string $amountInput, string $idempotencyKey, ?string $ip, ?string $userAgent, ?int $itemId = null): array
    {
        $this->reason = null;
        try {
            return $this->attempt($auction, $supplier, $user, $amountInput, $idempotencyKey, $ip, $userAgent, $itemId);
        } catch (ValidationException $e) {
            // Record the refusal for GetL1's live monitor; never let that break the response.
            rescue(fn () => \App\Models\BidRejection::create([
                'auction_id' => $auction->id, 'supplier_org_id' => $supplier->id, 'user_id' => $user->id,
                'amount_input' => mb_substr($amountInput, 0, 40), 'reason' => $this->reason ?? 'invalid',
                'message' => mb_substr((string) collect($e->errors())->flatten()->first(), 0, 255), 'ip' => $ip,
            ]), null, false);
            throw $e;
        }
    }

    private function fail(string $reason, string $message): never
    {
        $this->reason = $reason;
        throw ValidationException::withMessages(['amount' => $message]);
    }

    private function attempt(Auction $auction, Organization $supplier, User $user, string $amountInput, string $idempotencyKey, ?string $ip, ?string $userAgent, ?int $itemId): array
    {
        $amount = $this->parseAmount($amountInput);
        if ($auction->isPerItem() && ! $itemId) {
            $this->fail('invalid', 'Choose the item you are bidding on.');
        }

        if (! preg_match('/^[A-Za-z0-9-]{8,64}$/', $idempotencyKey)) {
            $this->fail('invalid', 'Please refresh the page and try again.');
        }

        $result = DB::transaction(function () use ($auction, $supplier, $user, $amount, $idempotencyKey, $ip, $userAgent, $itemId) {
            /** @var Auction $a */
            $a = Auction::withoutGlobalScopes()->whereKey($auction->id)->lockForUpdate()->firstOrFail();

            // Same attempt again (double-tap / network retry): return what we recorded.
            $existing = Bid::where('auction_id', $a->id)->where('supplier_org_id', $supplier->id)
                ->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return ['bid' => $existing, 'duplicate' => true, 'extended' => false];
            }

            $this->syncStatus($a);
            if ($a->status !== AuctionStatus::Live) {
                $this->fail('not_live', match ($a->status) {
                    AuctionStatus::Scheduled => 'The auction hasn’t started yet.',
                    AuctionStatus::Cancelled => 'This auction was cancelled.',
                    default => 'The auction has ended.',
                });
            }
            if ($a->paused_at !== null) {
                $this->fail('paused', 'Bidding is paused for a technical check. Your bid was not placed; please try again when the auction resumes.');
            }

            if ($a->isPerItem()) {
                return $this->placeOnItem($a, $supplier, $user, $amount, $idempotencyKey, $ip, $userAgent, $itemId);
            }
            if ($a->isJapanese()) {
                return $this->acceptRound($a, $supplier, $user, $amount, $idempotencyKey, $ip, $userAgent, $itemId);
            }

            $mine = Bid::where('auction_id', $a->id)->where('supplier_org_id', $supplier->id)
                ->orderByDesc('created_at')->orderByDesc('id')->first();
            if (! $mine) {
                SecurityLog::warning('auction_bid_not_participant', ['auction_id' => $a->id, 'supplier_org_id' => $supplier->id]);
                abort(404);
            }

            if ($mine->kind === Bid::KIND_LIVE && $mine->created_at->diffInSeconds(now(), true) < self::MIN_SECONDS_BETWEEN_BIDS) {
                $this->fail('too_fast', 'Please wait a moment between bids.');
            }

            $own = (float) $mine->amount;
            $maxAllowed = Standings::maxNextBid($a, $own);
            if ($amount > $maxAllowed) {
                $this->fail('too_high', 'Your bid must be at most '.
                    \App\Support\Money::inr($maxAllowed).' (at least '.\App\Support\Money::inr(Standings::minDecrement($a, $own)).' below your current price).');
            }

            $floor = Standings::floor($a);
            if ($amount < $floor) {
                $this->fail('below_floor', 'That’s more than '.rtrim(rtrim((string) $a->max_decrement_pct, '0'), '.').
                    '% below the current lowest price. Check for a typo'.($a->visibility === 'rank_and_l1'
                        ? ': the lowest accepted bid right now is '.\App\Support\Money::inr($floor).'.' : '.'));
            }

            // Standings including this bid, computed before the insert so the rank is written with it
            // (bids are insert-only: no UPDATE afterwards).
            $now = now();
            $standings = Standings::for($a)
                ->map(fn ($row) => $row['supplier_org_id'] === $supplier->id ? ['amount' => $amount, 'at' => $now] + $row : $row);
            $standings = Standings::rank($standings);
            $l1 = $standings->first();
            $rank = $standings->firstWhere('supplier_org_id', $supplier->id)['rank'];

            $bid = new Bid([
                'auction_id' => $a->id,
                'supplier_org_id' => $supplier->id,
                'user_id' => $user->id,
                'kind' => Bid::KIND_LIVE,
                'idempotency_key' => $idempotencyKey,
                'amount' => $amount,
                'rank_at_submit' => $rank,
                'ip' => $ip,
                'user_agent' => $userAgent ? substr($userAgent, 0, 255) : null,
            ]);
            $bid->created_at = $now;
            $bid->save();

            $extended = $this->maybeExtend($a);

            $a->current_l1 = $l1['amount'];
            $a->current_l1_supplier_org_id = $l1['supplier_org_id'];
            $a->bid_count++;
            $a->save();

            return ['bid' => $bid, 'duplicate' => false, 'extended' => $extended, 'auction' => $a];
        });

        if (! $result['duplicate']) {
            $this->broadcaster->push($result['auction']);
        }

        return ['bid' => $result['bid'], 'duplicate' => $result['duplicate'], 'extended' => $result['extended']];
    }

    /** Item-wise bid, inside the locked transaction: the same rules, applied to one RFQ line. */
    private function placeOnItem(Auction $a, Organization $supplier, User $user, float $amount, string $idempotencyKey, ?string $ip, ?string $userAgent, int $itemId): array
    {
        $participates = Bid::where('auction_id', $a->id)->where('supplier_org_id', $supplier->id)->exists();
        if (! $participates) {
            SecurityLog::warning('auction_bid_not_participant', ['auction_id' => $a->id, 'supplier_org_id' => $supplier->id]);
            abort(404);
        }

        $mine = Bid::where('auction_id', $a->id)->where('supplier_org_id', $supplier->id)->where('rfq_item_id', $itemId)
            ->orderByDesc('created_at')->orderByDesc('id')->first();
        if (! $mine) {
            $this->fail('invalid', 'That item is not part of this auction. Refresh the page.');
        }

        $lastLive = Bid::where('auction_id', $a->id)->where('supplier_org_id', $supplier->id)->where('kind', Bid::KIND_LIVE)
            ->orderByDesc('created_at')->orderByDesc('id')->first();
        if ($lastLive && $lastLive->created_at->diffInSeconds(now(), true) < self::MIN_SECONDS_BETWEEN_BIDS) {
            $this->fail('too_fast', 'Please wait a moment between bids.');
        }

        $own = (float) $mine->amount;
        $maxAllowed = Standings::maxNextBid($a, $own);
        if ($amount > $maxAllowed) {
            $this->fail('too_high', 'Your rate for this item must be at most '.\App\Support\Money::inr($maxAllowed).' per unit (at least '
                .\App\Support\Money::inr(Standings::minDecrement($a, $own)).' below your current rate).');
        }

        $byItem = Standings::byItem($a);
        $rows = $byItem[$itemId];
        $floor = Standings::itemFloor($a, (float) $rows->first()['amount']);
        if ($amount < $floor) {
            $this->fail('below_floor', 'That’s more than '.rtrim(rtrim((string) $a->max_decrement_pct, '0'), '.').
                '% below the current lowest rate for this item. Check for a typo'.($a->visibility === 'rank_and_l1'
                    ? ': the lowest accepted rate right now is '.\App\Support\Money::inr($floor).'.' : '.'));
        }

        $now = now();
        $rows = Standings::rank($rows->map(fn ($row) => $row['supplier_org_id'] === $supplier->id ? ['amount' => $amount, 'at' => $now] + $row : $row));
        $byItem[$itemId] = $rows;
        $rank = $rows->firstWhere('supplier_org_id', $supplier->id)['rank'];

        $bid = new Bid([
            'auction_id' => $a->id,
            'supplier_org_id' => $supplier->id,
            'user_id' => $user->id,
            'rfq_item_id' => $itemId,
            'kind' => Bid::KIND_LIVE,
            'idempotency_key' => $idempotencyKey,
            'amount' => $amount,
            'rank_at_submit' => $rank,
            'ip' => $ip,
            'user_agent' => $userAgent ? substr($userAgent, 0, 255) : null,
        ]);
        $bid->created_at = $now;
        $bid->save();

        $extended = $this->maybeExtend($a);
        $a->current_l1 = Standings::combinedL1($byItem, Standings::quantities($a));
        $a->current_l1_supplier_org_id = null; // several winners possible
        $a->bid_count++;
        $a->save();

        return ['bid' => $bid, 'duplicate' => false, 'extended' => $extended, 'auction' => $a];
    }

    /**
     * Japanese: accept the current round's price. $round is the round the supplier saw; if the round
     * has moved on, nothing is recorded and the supplier is told the new price.
     */
    private function acceptRound(Auction $a, Organization $supplier, User $user, float $amount, string $idempotencyKey, ?string $ip, ?string $userAgent, ?int $round): array
    {
        $participates = Bid::where('auction_id', $a->id)->where('supplier_org_id', $supplier->id)->where('kind', Bid::KIND_SEALED)->exists();
        if (! $participates) {
            SecurityLog::warning('auction_bid_not_participant', ['auction_id' => $a->id, 'supplier_org_id' => $supplier->id]);
            abort(404);
        }

        $st = Japanese::state($a);
        if ($round !== $st['round'] || abs($amount - $st['price']) > 0.004) {
            $this->fail('round_moved', 'The round has moved on. The price is now '.\App\Support\Money::inr($st['price']).'. Check and accept again if you want to stay in.');
        }
        if (! $st['in']->contains($supplier->id)) {
            $this->fail('dropped_out', 'You dropped out in an earlier round, so you can’t accept this one.');
        }
        if ($st['accepted']->contains($supplier->id)) {
            $this->fail('already_accepted', 'You have already accepted this round. Wait for the next round.');
        }

        $bid = new Bid([
            'auction_id' => $a->id,
            'supplier_org_id' => $supplier->id,
            'user_id' => $user->id,
            'kind' => Bid::KIND_LIVE,
            'round' => $st['round'],
            'idempotency_key' => $idempotencyKey,
            'amount' => $st['price'],
            'rank_at_submit' => $st['accepted']->count() + 1, // order of acceptance in this round
            'ip' => $ip,
            'user_agent' => $userAgent ? substr($userAgent, 0, 255) : null,
        ]);
        $bid->created_at = now();
        $bid->save();

        if ($st['accepted']->isEmpty()) {
            // First to accept this round leads at this price.
            $a->current_l1 = $st['price'];
            $a->current_l1_supplier_org_id = $supplier->id;
        }
        $a->bid_count++;
        $a->save();

        return ['bid' => $bid, 'duplicate' => false, 'extended' => false, 'auction' => $a];
    }

    /** Auto-extend: a bid in the last window pushes the end out (up to the limit). */
    private function maybeExtend(Auction $a): bool
    {
        if ($a->extend_window_sec > 0 && $a->extensions_used < $a->max_extensions
            && $a->ends_at->diffInSeconds(now(), true) <= $a->extend_window_sec) {
            $a->ends_at = $a->ends_at->copy()->addSeconds($a->extend_by_sec);
            $a->extensions_used++;

            return true;
        }

        return false;
    }

    /** Bring a locked auction's stored status in line with the server clock. */
    public function syncStatus(Auction $a): void
    {
        $effective = Standings::effectiveStatus($a);
        if ($effective === $a->status) {
            return;
        }

        if ($effective === AuctionStatus::Live) {
            $a->status = AuctionStatus::Live;
            $a->opened_at ??= $a->starts_at;
        } elseif ($effective === AuctionStatus::Closed) {
            $a->status = AuctionStatus::Closed;
            $a->opened_at ??= $a->starts_at;
            if ($a->isJapanese()) {
                // Ended when its last round ended; the winner is the top of the round ranking.
                $st = Japanese::state($a);
                $winner = $st['standings']->first();
                $a->ends_at = $st['finished_at'];
                $a->current_l1 = $winner['amount'] ?? $a->current_l1;
                $a->current_l1_supplier_org_id = $winner['supplier_org_id'] ?? $a->current_l1_supplier_org_id;
            }
            $a->closed_at ??= $a->ends_at;
            // The RFQ moves on to evaluation/award.
            \App\Models\Rfq::withoutGlobalScopes()->whereKey($a->rfq_id)
                ->where('status', \App\Enums\RfqStatus::Auction->value)
                ->update(['status' => \App\Enums\RfqStatus::Evaluating->value]);
        }
        $a->save();
    }

    /** "1,23,456.50" / "123456.5" → 123456.50; rejects anything else. */
    private function parseAmount(string $input): float
    {
        $clean = str_replace([',', ' ', '₹'], '', trim($input));
        if (! preg_match('/^\d{1,11}(\.\d{1,2})?$/', $clean) || (float) $clean <= 0) {
            $this->fail('invalid', 'Enter a valid amount in rupees, up to 2 decimals.');
        }

        return round((float) $clean, 2);
    }
}
