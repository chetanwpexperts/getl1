<?php

namespace App\Services;

use App\Enums\OrgRole;
use App\Mail\PurchaseRequestMail;
use App\Models\Organization;
use App\Models\PurchaseRequest;
use App\Models\Rfq;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Purchase requests (indents): raise → approve or reject → a buyer turns approved requests into
 * one draft RFQ. Nobody approves their own request; a request raised by an admin needs no approval.
 * If the RFQ is cancelled, its requests go back to the purchase team as approved.
 */
class PurchaseRequestService
{
    public const DECIDERS = [OrgRole::BuyerAdmin, OrgRole::Approver];

    public function __construct(private AuditLogger $audit, private RfqService $rfqs) {}

    public static function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'department' => ['nullable', 'string', 'max:100'],
            'needed_by' => ['nullable', 'date', 'after_or_equal:'.now(config('app.display_timezone'))->toDateString()],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:'.PurchaseRequest::MAX_ITEMS],
            'items.*.name' => ['required', 'string', 'max:150'],
            'items.*.spec' => ['nullable', 'string', 'max:1000'],
            'items.*.qty' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'items.*.unit' => ['required', 'in:'.implode(',', RfqService::UNITS)],
            'items.*.est_rate' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ];
    }

    public static function messages(): array
    {
        return [
            'items.required' => 'Add at least one item.',
            'items.*.name.required' => 'Every item needs a name.',
            'items.*.qty.gt' => 'Quantity must be more than 0.',
            'needed_by.after_or_equal' => 'The date can’t be in the past.',
        ];
    }

    public function create(Organization $org, User $by, array $data): PurchaseRequest
    {
        $items = [];
        $estimate = 0.0;
        $priced = false;
        foreach (array_values($data['items']) as $i) {
            $rate = isset($i['est_rate']) && $i['est_rate'] !== '' ? round((float) $i['est_rate'], 2) : null;
            $items[] = [
                'name' => Str::squish($i['name']),
                'spec' => isset($i['spec']) && trim((string) $i['spec']) !== '' ? Str::squish($i['spec']) : null,
                'qty' => round((float) $i['qty'], 3),
                'unit' => $i['unit'],
                'est_rate' => $rate,
            ];
            if ($rate !== null) {
                $priced = true;
                $estimate += $rate * (float) $i['qty'];
            }
        }
        $isAdmin = $by->roleIn($org) === OrgRole::BuyerAdmin;

        for ($attempt = 1; ; $attempt++) {
            try {
                $pr = DB::transaction(function () use ($org, $by, $data, $items, $estimate, $priced, $isAdmin) {
                    $pr = PurchaseRequest::create([
                        'organization_id' => $org->id,
                        'pr_number' => $this->nextNumber($org->id),
                        'title' => Str::squish($data['title']),
                        'department' => isset($data['department']) && trim((string) $data['department']) !== '' ? Str::squish($data['department']) : null,
                        'needed_by' => $data['needed_by'] ?? null,
                        'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim($data['notes']) : null,
                        'items' => $items,
                        'estimated_total' => $priced ? round($estimate, 2) : null,
                        'requested_by' => $by->id,
                    ] + ($isAdmin
                        ? ['status' => PurchaseRequest::APPROVED, 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => 'Raised by an admin: no approval needed.']
                        : ['status' => PurchaseRequest::PENDING]));
                    $this->audit->log('purchase_request_raised', $pr, after: [
                        'pr_number' => $pr->pr_number, 'items' => count($items), 'estimate' => $pr->estimated_total, 'auto_approved' => $isAdmin,
                    ], user: $by, organizationId: $org->id);

                    return $pr;
                });
                break;
            } catch (QueryException $e) {
                if ($attempt >= 3 || ! str_contains(strtolower($e->getMessage()), 'unique')) {
                    throw $e;
                }
            }
        }

        if ($pr->status === PurchaseRequest::PENDING) {
            $deciders = $this->deciders($pr);
            foreach ($deciders->filter(fn ($u) => $u->email) as $u) {
                Mail::to($u->email)->queue(new PurchaseRequestMail($pr, 'submitted'));
            }
            Notifier::toUsers($deciders, $org->id, 'sourcing', "Purchase request to approve: {$pr->pr_number}",
                "{$by->name}".($pr->department ? " ({$pr->department})" : '').": {$pr->title}, ".count($items).' '.Str::plural('item', count($items))
                .($pr->estimated_total ? ', about '.Money::inr($pr->estimated_total) : '').'.',
                route('buyer.requests.show', $pr->id));
        } else {
            $this->tellBuyers($pr, $by);
        }

        return $pr;
    }

    public function approve(PurchaseRequest $pr, User $by, ?string $note = null): PurchaseRequest
    {
        return $this->decide($pr, $by, PurchaseRequest::APPROVED, $note);
    }

    public function reject(PurchaseRequest $pr, User $by, string $note): PurchaseRequest
    {
        if (mb_strlen(trim($note)) < 5) {
            throw ValidationException::withMessages(['decision_note' => 'Say why it’s rejected, so the requester knows what to change.']);
        }

        return $this->decide($pr, $by, PurchaseRequest::REJECTED, $note);
    }

    private function decide(PurchaseRequest $pr, User $by, string $to, ?string $note): PurchaseRequest
    {
        $pr = DB::transaction(function () use ($pr, $by, $to, $note) {
            $p = PurchaseRequest::whereKey($pr->id)->lockForUpdate()->firstOrFail();
            if ($p->status !== PurchaseRequest::PENDING) {
                throw ValidationException::withMessages(['decision_note' => 'This request has already been decided.']);
            }
            if ($p->requested_by === $by->id) {
                throw ValidationException::withMessages(['decision_note' => 'You can’t approve or reject your own request.']);
            }
            if (! in_array($by->roleIn($p->organization_id), self::DECIDERS, true)) {
                abort(403);
            }
            $p->update(['status' => $to, 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $note ? trim(mb_substr($note, 0, 1000)) : null]);
            $this->audit->log($to === PurchaseRequest::APPROVED ? 'purchase_request_approved' : 'purchase_request_rejected', $p,
                after: ['pr_number' => $p->pr_number, 'note' => $p->decision_note], user: $by, organizationId: $p->organization_id);

            return $p;
        });

        $pr->load(['requester', 'decider']);
        $requester = $pr->requester;
        if ($requester?->email) {
            Mail::to($requester->email)->queue(new PurchaseRequestMail($pr, $to === PurchaseRequest::APPROVED ? 'approved' : 'rejected'));
        }
        Notifier::toUsers([$requester], $pr->organization_id, 'sourcing',
            $to === PurchaseRequest::APPROVED ? "Request approved: {$pr->pr_number}" : "Request rejected: {$pr->pr_number}",
            $to === PurchaseRequest::APPROVED
                ? "{$by->name} approved \"{$pr->title}\". The purchase team will now get quotes."
                : "{$by->name} rejected \"{$pr->title}\": {$pr->decision_note}",
            route('buyer.requests.show', $pr->id));
        if ($to === PurchaseRequest::APPROVED) {
            $this->tellBuyers($pr, $by);
        }

        return $pr;
    }

    public function cancel(PurchaseRequest $pr, User $by, ?string $reason = null): void
    {
        DB::transaction(function () use ($pr, $by, $reason) {
            $p = PurchaseRequest::whereKey($pr->id)->lockForUpdate()->firstOrFail();
            if (! $p->isOpen()) {
                throw ValidationException::withMessages(['request' => 'Only a request that isn’t with the purchase team yet can be cancelled.']);
            }
            $p->update(['status' => PurchaseRequest::CANCELLED, 'decision_note' => $reason ? trim(mb_substr($reason, 0, 1000)) : $p->decision_note]);
            $this->audit->log('purchase_request_cancelled', $p, after: ['pr_number' => $p->pr_number, 'reason' => $reason], user: $by, organizationId: $p->organization_id);
        });
    }

    /**
     * One draft RFQ from approved requests. Same item (name, spec and unit) across requests is
     * combined into one line with the total quantity; the earliest "needed by" date is used.
     *
     * @param  list<int>  $ids
     */
    public function convert(Organization $org, User $by, array $ids): Rfq
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (! $ids) {
            throw ValidationException::withMessages(['ids' => 'Choose at least one approved request.']);
        }

        [$rfq, $prs] = DB::transaction(function () use ($org, $by, $ids) {
            $prs = PurchaseRequest::with('requester')->where('organization_id', $org->id)->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            if ($prs->count() !== count($ids) || $prs->contains(fn ($p) => $p->status !== PurchaseRequest::APPROVED)) {
                throw ValidationException::withMessages(['ids' => 'Only approved requests that aren’t in an RFQ yet can be used. Refresh the page and try again.']);
            }

            $lines = [];
            foreach ($prs as $p) {
                foreach ($p->items as $item) {
                    $key = mb_strtolower($item['name'].'|'.($item['spec'] ?? '').'|'.$item['unit']);
                    $lines[$key] ??= ['name' => $item['name'], 'spec' => $item['spec'] ?? null, 'qty' => 0, 'unit' => $item['unit']];
                    $lines[$key]['qty'] = round($lines[$key]['qty'] + (float) $item['qty'], 3);
                }
            }
            if (count($lines) > RfqService::MAX_ITEMS) {
                throw ValidationException::withMessages(['ids' => 'That’s more than '.RfqService::MAX_ITEMS.' different items for one RFQ. Choose fewer requests.']);
            }
            $earliest = $prs->pluck('needed_by')->filter()->min();
            $today = now(config('app.display_timezone'))->toDateString();
            $date = $earliest && $earliest->toDateString() >= $today ? $earliest->toDateString() : null;
            foreach ($lines as &$l) {
                $l['delivery_date'] = $date;
            }
            unset($l);

            // Suppliers will see the title, so it carries no internal notes, departments or PR numbers.
            // The link back to the requests is shown to the buyer team on the RFQ page.
            $title = Str::limit($prs->pluck('title')->unique()->implode(', '), 147);

            $rfq = $this->rfqs->saveDraft($org, $by, [
                'title' => $title,
                'items' => array_values($lines),
            ]);
            // Which RFQ lines belong to which request (lines are created in the same order as $lines).
            $itemIdByKey = array_combine(array_keys($lines), $rfq->items()->orderBy('line_no')->pluck('id')->all());
            foreach ($prs as $p) {
                $mine = [];
                foreach ($p->items as $item) {
                    $mine[] = $itemIdByKey[mb_strtolower($item['name'].'|'.($item['spec'] ?? '').'|'.$item['unit'])];
                }
                $p->update([
                    'status' => PurchaseRequest::CONVERTED, 'rfq_id' => $rfq->id, 'rfq_item_ids' => array_values(array_unique($mine)),
                    'converted_by' => $by->id, 'converted_at' => now(),
                ]);
            }
            $this->audit->log('purchase_requests_converted', $rfq, after: ['requests' => $prs->pluck('pr_number')->all(), 'lines' => count($lines)],
                user: $by, organizationId: $org->id);

            return [$rfq, $prs];
        });

        foreach ($prs as $p) {
            Notifier::toUsers([$p->requester], $org->id, 'sourcing', "Request in progress: {$p->pr_number}",
                'The purchase team has started getting quotes for "'.$p->title.'".', route('buyer.requests.show', $p->id));
        }

        return $rfq;
    }

    /**
     * Take one request back out of its RFQ (the RFQ was dropped or went another way), as long as no
     * award or PO includes its lines yet. It goes back to "Ready to buy".
     */
    public function takeBack(PurchaseRequest $pr, User $by): void
    {
        DB::transaction(function () use ($pr, $by) {
            $p = PurchaseRequest::whereKey($pr->id)->lockForUpdate()->firstOrFail();
            if ($p->status !== PurchaseRequest::CONVERTED || ! $p->rfq_id) {
                throw ValidationException::withMessages(['request' => 'This request isn’t in an RFQ.']);
            }
            Rfq::withoutGlobalScopes()->whereKey($p->rfq_id)->lockForUpdate()->first(); // awarding takes the same lock
            $awarded = \App\Models\Award::withoutGlobalScopes()->where('rfq_id', $p->rfq_id)
                ->whereIn('status', [\App\Enums\AwardStatus::PendingApproval->value, \App\Enums\AwardStatus::Approved->value, \App\Enums\AwardStatus::PoSent->value])
                ->get()->contains(fn ($a) => $p->covers($a));
            if ($awarded) {
                throw ValidationException::withMessages(['request' => 'Its items have already been awarded, so it stays with this RFQ.']);
            }
            $ref = Rfq::withoutGlobalScopes()->whereKey($p->rfq_id)->value('ref_no');
            $p->update(['status' => PurchaseRequest::APPROVED, 'rfq_id' => null, 'rfq_item_ids' => null, 'converted_by' => null, 'converted_at' => null]);
            $this->audit->log('purchase_request_taken_back', $p, after: ['pr_number' => $p->pr_number, 'from_rfq' => $ref], user: $by, organizationId: $p->organization_id);
        });
        $pr->refresh()->load('requester');
        Notifier::toUsers([$pr->requester], $pr->organization_id, 'sourcing', "Request back with purchase: {$pr->pr_number}",
            "The purchase team will source \"{$pr->title}\" again.", route('buyer.requests.show', $pr->id));
    }

    /** The RFQ was cancelled: its requests go back to the purchase team as approved. */
    public function release(Rfq $rfq, User $by): void
    {
        $prs = PurchaseRequest::withoutGlobalScopes()->with('requester')->where('rfq_id', $rfq->id)->where('status', PurchaseRequest::CONVERTED)->get();
        if ($prs->isEmpty()) {
            return;
        }
        PurchaseRequest::withoutGlobalScopes()->whereKey($prs->pluck('id'))->update(['status' => PurchaseRequest::APPROVED, 'rfq_id' => null, 'rfq_item_ids' => null, 'converted_by' => null, 'converted_at' => null]);
        $this->audit->log('purchase_requests_released', $rfq, after: ['requests' => $prs->pluck('pr_number')->all()], user: $by, organizationId: $rfq->organization_id);
        foreach ($prs as $p) {
            Notifier::toUsers([$p->requester], $p->organization_id, 'sourcing', "Request back with purchase: {$p->pr_number}",
                "The RFQ for \"{$p->title}\" was cancelled. The purchase team will source it again.", route('buyer.requests.show', $p->id));
            $this->tellBuyers($p->refresh(), $by); // back on "Ready to buy"
        }
    }

    /** Ordered / delivered: tell the people whose request this PO covers (no prices, no supplier names). */
    public static function tellRequesters(\App\Models\Award $award, string $event): void
    {
        rescue(function () use ($award, $event) {
            $po = (string) $award->po_number;
            foreach (PurchaseRequest::withoutGlobalScopes()->with('requester')->where('rfq_id', $award->rfq_id)->get() as $p) {
                if (! $p->covers($award)) {
                    continue;
                }
                [$title, $body] = $event === 'ordered'
                    ? ["Ordered: {$p->pr_number}", "\"{$p->title}\" has been ordered ({$po})."]
                    : ["Delivery received: {$p->pr_number}", "Material for \"{$p->title}\" has arrived ({$po}). The store has recorded it."];
                Notifier::toUsers([$p->requester], $p->organization_id, 'orders', $title, $body, route('buyer.requests.show', $p->id));
            }
        }, null, false);
    }

    /** Approvers and admins who may decide, never the requester. */
    public function deciders(PurchaseRequest $pr): Collection
    {
        return Organization::findOrFail($pr->organization_id)->users()
            ->wherePivotIn('role', array_map(fn ($r) => $r->value, self::DECIDERS))
            ->where('users.id', '!=', $pr->requested_by)->get();
    }

    /** Approved and ready: the people who create RFQs. */
    private function tellBuyers(PurchaseRequest $pr, User $by): void
    {
        $buyers = Organization::findOrFail($pr->organization_id)->users()
            ->wherePivotIn('role', [OrgRole::BuyerAdmin->value, OrgRole::BuyerUser->value])
            ->where('users.id', '!=', $by->id)->get();
        Notifier::toUsers($buyers, $pr->organization_id, 'sourcing', "Approved request to buy: {$pr->pr_number}",
            "{$pr->title}, ".count($pr->items).' '.Str::plural('item', count($pr->items)).($pr->needed_by ? ', needed by '.$pr->needed_by->format('d M Y') : '').'. Create an RFQ when ready.',
            route('buyer.requests.show', $pr->id));
    }

    private function nextNumber(int $orgId): string
    {
        $prefix = 'PR-'.now()->setTimezone(config('app.display_timezone'))->year.'-';
        $last = PurchaseRequest::withoutGlobalScopes()->where('organization_id', $orgId)->where('pr_number', 'like', $prefix.'%')->orderByDesc('id')->value('pr_number');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 4, '0', STR_PAD_LEFT);
    }
}
