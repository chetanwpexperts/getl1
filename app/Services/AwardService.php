<?php

namespace App\Services;

use App\Enums\AuctionStatus;
use App\Enums\AwardStatus;
use App\Enums\OrgRole;
use App\Enums\RfqStatus;
use App\Jobs\IssuePurchaseOrder;
use App\Mail\AwardApprovalRequestMail;
use App\Mail\AwardDecisionMail;
use App\Models\Auction;
use App\Models\Award;
use App\Models\Organization;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\User;
use App\Services\Auction\Standings;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Award → approval → purchase order.
 *
 * - Candidates come from the closed auction (final prices) or, without an auction, from the
 *   sealed-quote comparison. L1 is pre-selected; choosing anyone else needs a written reason.
 * - Approval is needed when the company has an approver and the amount reaches its limit.
 *   Nobody approves their own award.
 * - The PO lines are frozen on the award at award time: what was agreed never changes later.
 * - Once approved, the purchase order is issued automatically (IssuePurchaseOrder).
 */
class AwardService
{
    public const MIN_REASON = 10;

    public function __construct(private AuditLogger $audit, private RfqService $rfqs) {}

    /** The award currently in force for an RFQ (pending, approved or PO sent). */
    public function current(Rfq $rfq): ?Award
    {
        return Award::withoutGlobalScopes()->where('rfq_id', $rfq->id)
            ->where('status', '!=', AwardStatus::Rejected->value)->latest('id')->first();
    }

    /** Why the RFQ can't be awarded right now, or null if it can. */
    public function blocker(Rfq $rfq): ?string
    {
        if (! $rfq->quotesAreUnsealed()) {
            return 'Quotes are still sealed. You can award after the quote deadline.';
        }
        if (! in_array($rfq->status, [RfqStatus::Published, RfqStatus::Auction, RfqStatus::Evaluating], true)) {
            return 'This RFQ can no longer be awarded.';
        }
        $auction = $this->latestAuction($rfq);
        if ($auction && in_array(Standings::effectiveStatus($auction), [AuctionStatus::Scheduled, AuctionStatus::Live], true)) {
            return 'Wait for the live auction to finish, or cancel it, before awarding.';
        }
        if ($this->current($rfq)) {
            return 'This RFQ already has an award.';
        }
        if ($this->candidates($rfq)->isEmpty()) {
            return 'No supplier quoted, so there is nothing to award.';
        }

        return null;
    }

    /**
     * Ranked suppliers the buyer can award to.
     *
     * @return Collection<int, array{supplier: Organization, rank: int, basic: float, landed: ?float, source: string, auction_id: ?int, quote: Quote}>
     */
    public function candidates(Rfq $rfq): Collection
    {
        $auction = $this->latestAuction($rfq);

        if ($auction && Standings::effectiveStatus($auction) === AuctionStatus::Closed) {
            $quotes = Quote::with(['items', 'supplier'])->where('rfq_id', $rfq->id)->whereNotNull('submitted_at')->get()->keyBy('supplier_org_id');

            return Standings::for($auction)
                ->filter(fn ($s) => $quotes->has($s['supplier_org_id']))
                ->map(fn ($s) => [
                    'supplier' => $quotes[$s['supplier_org_id']]->supplier,
                    'rank' => $s['rank'],
                    'basic' => (float) $s['amount'],
                    'landed' => null,
                    'source' => 'auction',
                    'auction_id' => $auction->id,
                    'quote' => $quotes[$s['supplier_org_id']],
                ])->values();
        }

        return $this->rfqs->comparison($rfq)->map(fn ($row) => [
            'supplier' => $row['quote']->supplier,
            'rank' => $row['rank'],
            'basic' => (float) $row['basic'],
            'landed' => (float) $row['landed'],
            'source' => 'quote',
            'auction_id' => null,
            'quote' => $row['quote'],
        ])->values();
    }

    public function award(Rfq $rfq, User $by, int $supplierOrgId, ?string $reason = null, ?string $remarks = null): Award
    {
        $award = DB::transaction(function () use ($rfq, $by, $supplierOrgId, $reason, $remarks) {
            // One award at a time per RFQ: lock the RFQ and re-check inside the lock.
            $rfq = Rfq::withoutGlobalScopes()->whereKey($rfq->id)->lockForUpdate()->firstOrFail();
            if ($why = $this->blocker($rfq)) {
                throw ValidationException::withMessages(['award' => $why]);
            }

            $chosen = $this->candidates($rfq)->firstWhere('supplier.id', $supplierOrgId);
            if (! $chosen) {
                throw ValidationException::withMessages(['award' => 'Choose one of the suppliers who quoted.']);
            }
            $reason = trim((string) $reason);
            if ($chosen['rank'] !== 1 && mb_strlen($reason) < self::MIN_REASON) {
                throw ValidationException::withMessages(['reason' => 'You are not awarding to L1. Please write the reason (at least '.self::MIN_REASON.' characters); it is kept in the audit record.']);
            }

            $po = $this->poLines($rfq, $chosen['quote'], $chosen['basic']);
            $buyer = Organization::findOrFail($rfq->organization_id);
            $needsApproval = $this->needsApproval($buyer, $chosen['basic']);

            $award = Award::create([
                'rfq_id' => $rfq->id,
                'organization_id' => $rfq->organization_id,
                'supplier_org_id' => $supplierOrgId,
                'source' => $chosen['source'],
                'auction_id' => $chosen['auction_id'],
                'rank' => $chosen['rank'],
                'total' => $chosen['basic'],
                'lines' => $po['lines'],
                'gst_total' => $po['gst'],
                'freight_total' => $po['freight'],
                'grand_total' => $po['grand'],
                'status' => $needsApproval ? AwardStatus::PendingApproval : AwardStatus::Approved,
                'awarded_by' => $by->id,
                'approved_at' => $needsApproval ? null : now(),
                'reason' => $reason !== '' ? $reason : null,
                'remarks' => $remarks ? trim($remarks) : null,
            ]);

            $rfq->update(['status' => RfqStatus::Evaluating]);

            $this->audit->log('awarded', $award, after: [
                'supplier' => $chosen['supplier']->name, 'rank' => $chosen['rank'], 'total' => $chosen['basic'],
                'source' => $chosen['source'], 'reason' => $award->reason, 'needs_approval' => $needsApproval,
            ], user: $by, organizationId: $rfq->organization_id);
            if (! $needsApproval) {
                $this->audit->log('award_approved', $award, after: ['by' => 'automatic (no approval required)'], user: $by, organizationId: $rfq->organization_id);
            }

            return $award;
        });

        if ($award->isPending()) {
            foreach ($this->approvers($award) as $user) {
                Mail::to($user->email)->queue(new AwardApprovalRequestMail($award));
            }
        } else {
            IssuePurchaseOrder::dispatch($award->id);
        }

        return $award;
    }

    public function approve(Award $award, User $by, ?string $note = null): Award
    {
        $award = $this->decide($award, $by, AwardStatus::Approved, $note);
        IssuePurchaseOrder::dispatch($award->id);

        return $award;
    }

    public function reject(Award $award, User $by, string $note): Award
    {
        if (mb_strlen(trim($note)) < 5) {
            throw ValidationException::withMessages(['decision_note' => 'Please say why, so the buyer can act on it.']);
        }

        return $this->decide($award, $by, AwardStatus::Rejected, $note);
    }

    /** May this user approve or reject this award? */
    public function canDecide(Award $award, User $user): bool
    {
        $org = Organization::find($award->organization_id);

        return $org && $award->isPending()
            && $award->awarded_by !== $user->id
            && $user->hasRoleIn($org, OrgRole::BuyerAdmin->value, OrgRole::Approver->value);
    }

    /** People who can approve this award (never the person who made it). */
    public function approvers(Award $award): Collection
    {
        return Organization::findOrFail($award->organization_id)->users()
            ->wherePivotIn('role', [OrgRole::Approver->value, OrgRole::BuyerAdmin->value])
            ->where('users.id', '!=', $award->awarded_by)
            ->get();
    }

    public function needsApproval(Organization $buyer, float $amount): bool
    {
        $hasApprover = $buyer->users()->wherePivot('role', OrgRole::Approver->value)->exists();
        $limit = (float) ($buyer->award_approval_limit ?? 0);

        return $hasApprover && $amount >= $limit;
    }

    /**
     * Frozen PO lines. Unit prices come from the supplier's quote; after an auction they are
     * scaled so the lines add up to the final auction price (unit rates kept to 4 decimals,
     * the last paise shown as round-off). GST and freight follow the supplier's quote.
     *
     * @return array{lines: array, gst: float, freight: float, grand: float}
     */
    public function poLines(Rfq $rfq, Quote $quote, float $basic): array
    {
        $quoteLines = $quote->items->keyBy('rfq_item_id');
        $factor = (float) $quote->total > 0 ? $basic / (float) $quote->total : 1.0;

        $items = [];
        $sum = $gst = $freight = 0.0;
        foreach ($rfq->items()->orderBy('line_no')->orderBy('id')->get() as $item) {
            $ql = $quoteLines[$item->id] ?? null;
            if (! $ql) {
                continue;
            }
            $unit = round((float) $ql->unit_price * $factor, 4);
            $amount = Money::round($unit * (float) $item->qty);
            $lineGst = Money::round($amount * (float) $ql->gst_rate / 100);
            $items[] = [
                'rfq_item_id' => $item->id,
                'name' => $item->name,
                'spec' => $item->spec,
                'qty' => (float) $item->qty,
                'unit' => $item->unit,
                'needed_by' => $item->delivery_date?->toDateString(),
                'unit_price' => $unit,
                'amount' => $amount,
                'gst_rate' => (float) $ql->gst_rate,
                'gst' => $lineGst,
                'freight' => (float) $ql->freight,
            ];
            $sum += $amount;
            $gst += $lineGst;
            $freight += (float) $ql->freight;
        }

        $roundOff = Money::round($basic - $sum);

        return [
            'lines' => ['items' => $items, 'round_off' => $roundOff],
            'gst' => Money::round($gst),
            'freight' => Money::round($freight),
            'grand' => Money::round($basic + $gst + $freight),
        ];
    }

    private function decide(Award $award, User $by, AwardStatus $to, ?string $note): Award
    {
        return DB::transaction(function () use ($award, $by, $to, $note) {
            $award = Award::withoutGlobalScopes()->whereKey($award->id)->lockForUpdate()->firstOrFail();
            if (! $award->isPending()) {
                throw ValidationException::withMessages(['decision_note' => 'This award has already been decided.']);
            }
            if (! $this->canDecide($award, $by)) {
                abort(403, 'You can’t approve an award you made, or you don’t have approval rights.');
            }

            $award->update([
                'status' => $to,
                'approved_by' => $by->id,
                'approved_at' => now(),
                'decision_note' => $note ? trim($note) : null,
            ]);

            $this->audit->log($to === AwardStatus::Approved ? 'award_approved' : 'award_rejected', $award,
                after: ['note' => $award->decision_note], user: $by, organizationId: $award->organization_id);

            if ($to === AwardStatus::Rejected) {
                Rfq::withoutGlobalScopes()->whereKey($award->rfq_id)->update(['status' => RfqStatus::Evaluating->value]);
            }

            $awarder = User::find($award->awarded_by);
            if ($awarder) {
                DB::afterCommit(fn () => Mail::to($awarder->email)->queue(new AwardDecisionMail($award)));
            }

            return $award;
        });
    }

    private function latestAuction(Rfq $rfq): ?Auction
    {
        return Auction::withoutGlobalScopes()->where('rfq_id', $rfq->id)
            ->where('status', '!=', AuctionStatus::Cancelled->value)->latest('id')->first();
    }
}
