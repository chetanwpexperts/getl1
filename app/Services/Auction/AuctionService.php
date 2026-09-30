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

    public function __construct(private AuditLogger $audit, private AuctionBroadcaster $broadcaster, private BidService $bids) {}

    public static function rules(): array
    {
        return [
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'duration_min' => ['required', 'integer', 'min:10', 'max:240'],
            'min_decrement_type' => ['required', 'in:percent,amount'],
            'min_decrement_value' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'max_decrement_pct' => ['required', 'numeric', 'min:1', 'max:50'],
            'extend_window_sec' => ['required', 'integer', 'in:0,60,120,180,300'],
            'extend_by_sec' => ['required', 'integer', 'in:60,120,180,300'],
            'max_extensions' => ['required', 'integer', 'min:0', 'max:30'],
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

        $startPrice = (float) $quotes->min('total');
        if ($data['min_decrement_type'] === 'percent' && (float) $data['min_decrement_value'] > 10) {
            throw ValidationException::withMessages(['min_decrement_value' => 'A minimum decrement above 10% is not practical.']);
        }
        if ($data['min_decrement_type'] === 'amount' && (float) $data['min_decrement_value'] > $startPrice * 0.1) {
            throw ValidationException::withMessages(['min_decrement_value' => 'The minimum decrement can be at most 10% of the start price.']);
        }

        $auction = DB::transaction(function () use ($rfq, $by, $data, $quotes, $startsAt, $startPrice) {
            // Re-check inside the lock that nobody scheduled one in parallel.
            $locked = Rfq::withoutGlobalScopes()->whereKey($rfq->id)->lockForUpdate()->first();
            if ($locked->status !== RfqStatus::Published) {
                throw ValidationException::withMessages(['starts_at' => 'An auction already exists for this RFQ.']);
            }

            $endsAt = $startsAt->copy()->addMinutes((int) $data['duration_min']);
            $best = $quotes->sortBy([['total', 'asc'], ['submitted_at', 'asc']])->first();

            $auction = Auction::create([
                'rfq_id' => $rfq->id,
                'organization_id' => $rfq->organization_id,
                'created_by' => $by->id,
                'format' => 'english_reverse',
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
                'current_l1_supplier_org_id' => $best->supplier_org_id,
                'bid_count' => 0,
            ]);

            // Each participant's sealed quote is their opening position.
            foreach ($quotes as $q) {
                $bid = new Bid([
                    'auction_id' => $auction->id,
                    'supplier_org_id' => $q->supplier_org_id,
                    'user_id' => $q->submitted_by ?? $by->id,
                    'kind' => Bid::KIND_SEALED,
                    'amount' => $q->total,
                ]);
                $bid->created_at = $q->submitted_at;
                $bid->save();
            }

            $locked->update(['status' => RfqStatus::Auction]);

            $this->audit->log('auction_scheduled', $auction, after: [
                'starts_at' => $startsAt->toIso8601String(), 'ends_at' => $endsAt->toIso8601String(),
                'start_price' => $startPrice, 'participants' => $quotes->count(),
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
            ->orWhere(fn ($q) => $q->where('status', AuctionStatus::Live->value)->where('ends_at', '<=', now()))
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
}
