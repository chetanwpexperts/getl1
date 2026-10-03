<?php

namespace App\Services\Auction;

use App\Enums\AuctionStatus;
use App\Enums\RfqStatus;
use App\Mail\AuctionScheduledMail;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Live reverse auction lifecycle: schedule (after sealed quotes open) → live → closed.
 *
 * Participants are the suppliers who submitted a sealed quote; each starts the auction at
 * their sealed total (recorded as a 'sealed' bid at the time they quoted, so earlier quotes
 * win ties). The start price is the best sealed quote.
 */
class AuctionService
{
    public const MIN_PARTICIPANTS = 2;
    public const MIN_DURATION_MINUTES = 10;

    /** 10 in production; staging can allow shorter auctions (AUCTION_MIN_DURATION) for quick tests. */
    public static function minDuration(): int
    {
        $min = (int) config('app.auction_min_duration_minutes', self::MIN_DURATION_MINUTES);

        return app()->isProduction() ? max(self::MIN_DURATION_MINUTES, $min) : max(1, $min);
    }

    /** Duration choices shown on the schedule form. */
    public static function durationOptions(): array
    {
        $max = (int) app(\App\Services\PlatformSettings::class)->get('auction.max_duration_min');

        return array_values(array_filter([2, 5, 10, 15, 20, 30, 45, 60, 90, 120, 180, 240, 360, 480],
            fn ($m) => $m >= self::minDuration() && $m <= $max));
    }

    public function __construct(private AuditLogger $audit, private AuctionBroadcaster $broadcaster, private BidService $bids) {}

    public static function rules(): array
    {
        return [
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'duration_min' => ['required', 'integer', 'min:'.self::minDuration(), 'max:'.(int) app(\App\Services\PlatformSettings::class)->get('auction.max_duration_min')],
            'min_decrement_type' => ['required', 'in:percent,amount'],
            'min_decrement_value' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'max_decrement_pct' => ['required', 'numeric', 'min:1', 'max:50'],
            'extend_window_sec' => ['required', 'integer', 'in:0,60,120,180,300'],
            'extend_by_sec' => ['required', 'integer', 'in:60,120,180,300'],
            'max_extensions' => ['required', 'integer', 'min:0', 'max:'.(int) app(\App\Services\PlatformSettings::class)->get('auction.max_extensions_limit')],
            'visibility' => ['required', 'in:rank_only,rank_and_l1'],
        ];
    }

    public function schedule(Rfq $rfq, User $by, array $data): Auction
    {
        if (! $rfq->quotesAreUnsealed() || $rfq->status !== RfqStatus::Published) {
            throw ValidationException::withMessages(['starts_at' => 'An auction can be scheduled once the sealed-quote deadline has passed.']);
        }

        $quotes = Quote::where('rfq_id', $rfq->id)->whereNotNull('submitted_at')->orderBy('submitted_at')->get();
        if ($quotes->count() < self::MIN_PARTICIPANTS) {
            throw ValidationException::withMessages(['starts_at' => 'At least '.self::MIN_PARTICIPANTS.' suppliers must have quoted to run an auction.']);
        }

        $startsAt = Carbon::createFromFormat('Y-m-d\TH:i', $data['starts_at'], config('app.display_timezone'))->utc();
        if ($startsAt->lt(now()->addMinutes(5)) || $startsAt->gt(now()->addDays(14))) {
            throw ValidationException::withMessages(['starts_at' => 'Start between 5 minutes and 14 days from now, so suppliers can get ready.']);
        }

        $perItem = $rfq->isPerItem();
        if ($perItem) {
            // Item-wise: the start is the best rate on every line combined; drops are a percentage of each rate.
            if ($data['min_decrement_type'] !== 'percent') {
                throw ValidationException::withMessages(['min_decrement_value' => 'For an item-wise auction, set the minimum drop in percent (it applies to every item’s rate).']);
            }
            $quotes->load('items');
            $lines = $quotes->flatMap->items;
            $startPrice = 0.0;
            foreach ($rfq->items()->pluck('qty', 'id') as $itemId => $q) {
                $startPrice += (float) $lines->where('rfq_item_id', $itemId)->min('unit_price') * (float) $q;
            }
            $startPrice = round($startPrice, 2);
        } else {
            $startPrice = (float) $quotes->min('total');
        }
        if ($data['min_decrement_type'] === 'percent' && (float) $data['min_decrement_value'] > 10) {
            throw ValidationException::withMessages(['min_decrement_value' => 'A minimum decrement above 10% is not practical.']);
        }
        if ($data['min_decrement_type'] === 'amount' && (float) $data['min_decrement_value'] > $startPrice * 0.1) {
            throw ValidationException::withMessages(['min_decrement_value' => 'The minimum decrement can be at most 10% of the start price.']);
        }

        $auction = DB::transaction(function () use ($rfq, $by, $data, $quotes, $startsAt, $startPrice, $perItem) {
            // Re-check inside the lock that nobody scheduled one in parallel.
            $locked = Rfq::withoutGlobalScopes()->whereKey($rfq->id)->lockForUpdate()->first();
            if ($locked->status !== RfqStatus::Published) {
                throw ValidationException::withMessages(['starts_at' => 'An auction already exists for this RFQ.']);
            }

            // Plan limit: live auctions per month. Beyond it, a prepaid auction credit is used.
            $useCredit = $this->consumeAllowance($rfq->organization_id);

            $endsAt = $startsAt->copy()->addMinutes((int) $data['duration_min']);
            $best = $quotes->sortBy([['total', 'asc'], ['submitted_at', 'asc']])->first();

            $auction = Auction::create([
                'rfq_id' => $rfq->id,
                'organization_id' => $rfq->organization_id,
                'created_by' => $by->id,
                'paid_with_credit' => $useCredit,
                'format' => 'english_reverse',
                'bid_basis' => $perItem ? Rfq::BASIS_PER_ITEM : Rfq::BASIS_LOT,
                'start_price' => $startPrice,
                'min_decrement_type' => $data['min_decrement_type'],
                'min_decrement_value' => $data['min_decrement_value'],
                'max_decrement_pct' => $data['max_decrement_pct'],
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'original_ends_at' => $endsAt,
                'extend_window_sec' => $data['extend_window_sec'],
                'extend_by_sec' => $data['extend_by_sec'],
                'max_extensions' => $data['max_extensions'],
                'visibility' => $data['visibility'],
                'status' => AuctionStatus::Scheduled,
                'current_l1' => $startPrice,
                'current_l1_supplier_org_id' => $perItem ? null : $best->supplier_org_id,
                'bid_count' => 0,
            ]);

            // Each participant's sealed quote is their opening position (item-wise: one per line, at the quoted rate).
            foreach ($quotes as $q) {
                $openings = $perItem
                    ? $q->items->map(fn ($qi) => ['rfq_item_id' => $qi->rfq_item_id, 'amount' => $qi->unit_price])
                    : [['rfq_item_id' => null, 'amount' => $q->total]];
                foreach ($openings as $opening) {
                    $bid = new Bid([
                        'auction_id' => $auction->id,
                        'supplier_org_id' => $q->supplier_org_id,
                        'user_id' => $q->submitted_by ?? $by->id,
                        'kind' => Bid::KIND_SEALED,
                    ] + $opening);
                    $bid->created_at = $q->submitted_at;
                    $bid->save();
                }
            }

            $locked->update(['status' => RfqStatus::Auction]);

            $this->audit->log('auction_scheduled', $auction, after: [
                'starts_at' => $startsAt->toIso8601String(), 'ends_at' => $endsAt->toIso8601String(),
                'start_price' => $startPrice, 'participants' => $quotes->count(), 'bid_basis' => $perItem ? 'per_item' : 'lot_total',
                'rules' => collect($data)->except('starts_at')->all(),
            ], user: $by, organizationId: $rfq->organization_id);

            return $auction;
        });

        $this->notifyParticipants($auction);

        return $auction;
    }

    public function cancel(Auction $auction, User $by, string $reason): void
    {
        DB::transaction(function () use ($auction, $by, $reason) {
            $a = Auction::withoutGlobalScopes()->whereKey($auction->id)->lockForUpdate()->firstOrFail();
            $this->bids->syncStatus($a);
            if ($a->status !== AuctionStatus::Scheduled) {
                throw ValidationException::withMessages(['reason' => 'Only an auction that hasn’t started can be cancelled.']);
            }

            $a->update(['status' => AuctionStatus::Cancelled, 'cancel_reason' => $reason]);
            if ($a->paid_with_credit) {
                // Cancelled before it ran: the prepaid credit goes back.
                \App\Models\Organization::whereKey($a->organization_id)->increment('auction_credits');
            }
            Rfq::withoutGlobalScopes()->whereKey($a->rfq_id)->update(['status' => RfqStatus::Published->value]);

            $this->audit->log('auction_cancelled', $a, after: ['reason' => $reason], user: $by, organizationId: $a->organization_id);
        });
    }

    /**
     * Opens auctions whose start time has passed and closes those whose end has passed.
     * Runs from the scheduler every few seconds; bids also check the clock themselves,
     * so correctness never depends on this running on time.
     *
     * @return array{opened:int, closed:int}
     */
    public function tick(): array
    {
        $opened = $closed = 0;

        $due = Auction::withoutGlobalScopes()
            ->where(fn ($q) => $q->where('status', AuctionStatus::Scheduled->value)->where('starts_at', '<=', now()))
            ->orWhere(fn ($q) => $q->where('status', AuctionStatus::Live->value)->whereNull('paused_at')->where('ends_at', '<=', now()))
            ->pluck('id');

        foreach ($due as $id) {
            $a = DB::transaction(function () use ($id, &$opened, &$closed) {
                $a = Auction::withoutGlobalScopes()->whereKey($id)->lockForUpdate()->first();
                $before = $a->status;
                $this->bids->syncStatus($a);
                if ($before === $a->status) {
                    return null;
                }

                if ($a->status === AuctionStatus::Live) {
                    $opened++;
                    $this->audit->log('auction_opened', $a, organizationId: $a->organization_id);
                } elseif ($a->status === AuctionStatus::Closed) {
                    $closed++;
                    $this->audit->log('auction_closed', $a, after: [
                        'final_l1' => (float) $a->current_l1, 'l1_supplier_org_id' => $a->current_l1_supplier_org_id,
                        'bids' => $a->bid_count, 'extensions' => $a->extensions_used,
                    ], organizationId: $a->organization_id);
                }

                return $a;
            });

            if ($a) {
                $this->broadcaster->push($a);
            }
        }

        return ['opened' => $opened, 'closed' => $closed];
    }

    private function notifyParticipants(Auction $auction): void
    {
        $orgIds = Bid::where('auction_id', $auction->id)->distinct()->pluck('supplier_org_id');
        $invites = \App\Models\RfqInvite::with(['listEntry', 'supplier'])
            ->where('rfq_id', $auction->rfq_id)->whereIn('supplier_org_id', $orgIds)->get();

        foreach ($invites as $invite) {
            $email = $invite->listEntry?->contact_email ?: $invite->supplier?->email;
            if ($email) {
                Mail::to($email)->queue(new AuctionScheduledMail($auction, $invite->supplier_org_id));
            }
        }
    }

    /**
     * Inside the scheduling transaction: within the monthly plan limit → free; beyond it →
     * one prepaid credit is taken (row lock, so two schedules can't share one credit);
     * neither → a clear message with the way forward.
     */
    private function consumeAllowance(int $orgId): bool
    {
        $org = \App\Models\Organization::whereKey($orgId)->lockForUpdate()->firstOrFail();
        $allowance = app(\App\Services\Billing\PlanService::class)->auctionAllowance($org);

        if ($allowance['left'] === null || $allowance['left'] > 0) {
            return false;
        }
        if ($org->auction_credits > 0) {
            $org->decrement('auction_credits');

            return true;
        }

        $plan = $allowance['plan']?->name ?? 'current';
        throw ValidationException::withMessages(['starts_at' => "You've used all {$allowance['limit']} live "
            .\Illuminate\Support\Str::plural('auction', (int) $allowance['limit'])." in your {$plan} plan this month. "
            .'Upgrade your plan or buy a single auction from Billing to run this one.']);
    }
}
