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
 * - Item-wise RFQs: each line is awarded on its own (awardItems). One decision creates one award
 *   (and one PO) per supplier chosen; they share a group key and are approved or rejected together.
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

    /** Every award currently in force for an RFQ (item-wise awards can be several, one per supplier). */
    public function currentAll(Rfq $rfq): Collection
    {
        return Award::withoutGlobalScopes()->where('rfq_id', $rfq->id)
            ->where('status', '!=', AwardStatus::Rejected->value)->orderBy('id')->get();
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
        $nothing = $rfq->isPerItem()
            ? $this->itemCandidates($rfq)->every(fn ($c) => $c['options']->isEmpty())
            : $this->candidates($rfq)->isEmpty();
        if ($nothing) {
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

    /**
     * Item-wise: for every RFQ line, the suppliers ranked by unit rate before GST — the final auction
     * rate after an item-wise auction, else the sealed quote rate (earlier quote wins a tie).
     *
     * @return Collection<int, array{item: \App\Models\RfqItem, source: string, auction_id: ?int, options: Collection<int, array{supplier: Organization, rank: int, rate: float, quote: Quote}>}>
     */
    public function itemCandidates(Rfq $rfq): Collection
    {
        $auction = $this->latestAuction($rfq);
        $quotes = Quote::with(['items', 'supplier'])->where('rfq_id', $rfq->id)->whereNotNull('submitted_at')->get()->keyBy('supplier_org_id');
        $items = $rfq->items()->get();

        if ($auction && $auction->isPerItem() && Standings::effectiveStatus($auction) === AuctionStatus::Closed) {
            $byItem = Standings::byItem($auction);

            return $items->map(fn ($item) => [
                'item' => $item,
                'source' => 'auction',
                'auction_id' => $auction->id,
                'options' => ($byItem[$item->id] ?? collect())
                    ->filter(fn ($r) => $quotes->has($r['supplier_org_id']))
                    ->map(fn ($r) => ['supplier' => $quotes[$r['supplier_org_id']]->supplier, 'rank' => $r['rank'], 'rate' => (float) $r['amount'], 'quote' => $quotes[$r['supplier_org_id']]])
                    ->values(),
            ]);
        }

        return $items->map(function ($item) use ($quotes) {
            $options = $quotes
                ->map(fn (Quote $q) => ['quote' => $q, 'line' => $q->items->firstWhere('rfq_item_id', $item->id)])
                ->filter(fn ($x) => $x['line'] !== null)
                ->sort(fn ($a, $b) => [(float) $a['line']->unit_price, $a['quote']->submitted_at->format('U.u')] <=> [(float) $b['line']->unit_price, $b['quote']->submitted_at->format('U.u')])
                ->values()
                ->map(fn ($x, $i) => ['supplier' => $x['quote']->supplier, 'rank' => $i + 1, 'rate' => (float) $x['line']->unit_price, 'quote' => $x['quote']]);

            return ['item' => $item, 'source' => 'quote', 'auction_id' => null, 'options' => $options];
        });
    }

    /**
     * Item-wise award. $choice: rfq_item_id => supplier_org_id for every line. Creates one award per
     * supplier chosen (its lines at the chosen rates); approval is decided on the combined amount.
     *
     * @param  array<int|string, int|string>  $choice
     * @return Collection<int, Award>
     */
    public function awardItems(Rfq $rfq, User $by, array $choice, ?string $reason = null, ?string $remarks = null): Collection
    {
        $awards = DB::transaction(function () use ($rfq, $by, $choice, $reason, $remarks) {
            $rfq = Rfq::withoutGlobalScopes()->whereKey($rfq->id)->lockForUpdate()->firstOrFail();
            if (! $rfq->isPerItem()) {
                throw ValidationException::withMessages(['award' => 'This RFQ is awarded as one lot.']);
            }
            if ($why = $this->blocker($rfq)) {
                throw ValidationException::withMessages(['award' => $why]);
            }

            $picked = [];
            $offL1 = false;
            foreach ($this->itemCandidates($rfq) as $c) {
                $option = $c['options']->firstWhere('supplier.id', (int) ($choice[$c['item']->id] ?? 0));
                if (! $option) {
                    throw ValidationException::withMessages(['award' => 'Choose a supplier for “'.$c['item']->name.'”.']);
                }
                $offL1 = $offL1 || $option['rank'] !== 1;
                $picked[$option['supplier']->id][] = ['item' => $c['item'], 'option' => $option, 'source' => $c['source'], 'auction_id' => $c['auction_id']];
            }

            $reason = trim((string) $reason);
            if ($offL1 && mb_strlen($reason) < self::MIN_REASON) {
                throw ValidationException::withMessages(['reason' => 'Some items are not going to their L1. Please write the reason (at least '.self::MIN_REASON.' characters); it is kept in the audit record.']);
            }

            $orders = collect($picked)->map(fn ($rows) => ['rows' => $rows, 'po' => $this->itemPoLines($rows)]);
            $buyer = Organization::findOrFail($rfq->organization_id);
            $combined = (float) $orders->sum(fn ($o) => $o['po']['basic']);
            $needsApproval = $this->needsApproval($buyer, $combined);
            $groupKey = (string) \Illuminate\Support\Str::uuid();

            $created = collect();
            foreach ($orders as $supplierId => $order) {
                $first = $order['rows'][0];
                $award = Award::create([
                    'rfq_id' => $rfq->id,
                    'organization_id' => $rfq->organization_id,
                    'supplier_org_id' => $supplierId,
                    'source' => $first['source'],
                    'auction_id' => $first['auction_id'],
                    'group_key' => $groupKey,
                    'rank' => max(array_map(fn ($r) => $r['option']['rank'], $order['rows'])),
                    'total' => $order['po']['basic'],
                    'lines' => $order['po']['lines'],
                    'gst_total' => $order['po']['gst'],
                    'freight_total' => $order['po']['freight'],
                    'grand_total' => $order['po']['grand'],
                    'status' => $needsApproval ? AwardStatus::PendingApproval : AwardStatus::Approved,
                    'awarded_by' => $by->id,
                    'approved_at' => $needsApproval ? null : now(),
                    'reason' => $reason !== '' ? $reason : null,
                    'remarks' => $remarks ? trim($remarks) : null,
                ]);

                $this->audit->log('awarded', $award, after: [
                    'supplier' => $first['option']['supplier']->name, 'total' => $order['po']['basic'], 'source' => $first['source'],
                    'items' => array_map(fn ($r) => ['item' => $r['item']->name, 'rank' => $r['option']['rank'], 'rate' => $r['option']['rate']], $order['rows']),
                    'split_into' => $orders->count(), 'combined_total' => $combined, 'reason' => $award->reason, 'needs_approval' => $needsApproval,
                ], user: $by, organizationId: $rfq->organization_id);
                if (! $needsApproval) {
                    $this->audit->log('award_approved', $award, after: ['by' => 'automatic (no approval required)'], user: $by, organizationId: $rfq->organization_id);
                }
                $created->push($award);
            }

            $rfq->update(['status' => RfqStatus::Evaluating]);

            return $created;
        });

        foreach ($awards as $award) {
            if ($award->isPending()) {
                foreach ($this->approvers($award) as $user) {
                    Mail::to($user->email)->queue(new AwardApprovalRequestMail($award));
                }
            } else {
                IssuePurchaseOrder::dispatch($award->id);
            }
        }

        return $awards;
    }

    /**
     * Item-wise PO lines at the chosen rates (no scaling); GST and freight from the supplier's quote.
     *
     * @param  list<array{item: \App\Models\RfqItem, option: array{rate: float, quote: Quote}}>  $rows
     * @return array{lines: array, basic: float, gst: float, freight: float, grand: float}
     */
    private function itemPoLines(array $rows): array
    {
        usort($rows, fn ($a, $b) => [$a['item']->line_no, $a['item']->id] <=> [$b['item']->line_no, $b['item']->id]);
        $items = [];
        $sum = $gst = $freight = 0.0;
        foreach ($rows as $row) {
            $item = $row['item'];
            $ql = $row['option']['quote']->items->firstWhere('rfq_item_id', $item->id);
            $amount = Money::round($row['option']['rate'] * (float) $item->qty);
            $lineGst = Money::round($amount * (float) $ql->gst_rate / 100);
            $items[] = [
                'rfq_item_id' => $item->id,
                'name' => $item->name,
                'spec' => $item->spec,
                'qty' => (float) $item->qty,
                'unit' => $item->unit,
                'needed_by' => $item->delivery_date?->toDateString(),
                'unit_price' => $row['option']['rate'],
                'amount' => $amount,
                'gst_rate' => (float) $ql->gst_rate,
                'gst' => $lineGst,
                'freight' => (float) $ql->freight,
                'rank' => $row['option']['rank'],
            ];
            $sum += $amount;
            $gst += $lineGst;
            $freight += (float) $ql->freight;
        }

        return [
            'lines' => ['items' => $items, 'round_off' => 0.0],
            'basic' => Money::round($sum),
            'gst' => Money::round($gst),
            'freight' => Money::round($freight),
            'grand' => Money::round($sum + $gst + $freight),
        ];
    }

    public function award(Rfq $rfq, User $by, int $supplierOrgId, ?string $reason = null, ?string $remarks = null): Award
    {
        if ($rfq->isPerItem()) {
            throw ValidationException::withMessages(['award' => 'This RFQ is awarded item by item. Choose a supplier for each item.']);
        }

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
        foreach ($this->group($award) as $member) {
            if ($member->status === AwardStatus::Approved) {
                IssuePurchaseOrder::dispatch($member->id);
            }
        }

        return $award;
    }

    /** The award and, for an item-wise decision, the other awards made with it. */
    public function group(Award $award): Collection
    {
        return $award->group_key
            ? Award::withoutGlobalScopes()->where('group_key', $award->group_key)->orderBy('id')->get()
            : collect([$award]);
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

            // An item-wise decision is approved or rejected as a whole.
            $members = $award->group_key
                ? Award::withoutGlobalScopes()->where('group_key', $award->group_key)->where('status', AwardStatus::PendingApproval->value)->lockForUpdate()->get()
                : collect([$award]);
            foreach ($members as $member) {
                $member->update([
                    'status' => $to,
                    'approved_by' => $by->id,
                    'approved_at' => now(),
                    'decision_note' => $note ? trim($note) : null,
                ]);
                $this->audit->log($to === AwardStatus::Approved ? 'award_approved' : 'award_rejected', $member,
                    after: ['note' => $member->decision_note] + ($members->count() > 1 ? ['decided_together' => $members->count()] : []),
                    user: $by, organizationId: $member->organization_id);
            }
            $award->refresh();

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
