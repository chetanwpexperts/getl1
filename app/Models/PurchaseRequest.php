<?php

namespace App\Models;

use App\Enums\AwardStatus;
use App\Enums\RfqStatus;
use App\Services\Payables\ReceiptService;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A staff member's request for material (indent). Buyer company only; suppliers never see it. */
class PurchaseRequest extends Model
{
    use BelongsToOrganization;

    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const CONVERTED = 'converted';
    public const CANCELLED = 'cancelled';

    public const MAX_ITEMS = 50;

    protected $fillable = [
        'organization_id', 'pr_number', 'title', 'department', 'needed_by', 'notes', 'items', 'estimated_total', 'status',
        'requested_by', 'decided_by', 'decided_at', 'decision_note', 'rfq_id', 'rfq_item_ids', 'converted_by', 'converted_at',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array', 'rfq_item_ids' => 'array', 'needed_by' => 'date', 'decided_at' => 'datetime', 'converted_at' => 'datetime',
            'estimated_total' => 'decimal:2',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class)->withoutGlobalScopes();
    }

    /**
     * Issued POs per RFQ, looked up once per request for all the RFQs on a page.
     *
     * @param  list<int>  $rfqIds
     * @return \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, Award>>
     */
    public static function issuedPos(array $rfqIds): \Illuminate\Support\Collection
    {
        // Web requests only: a queue worker or command keeps one request object for its whole life.
        $attrs = app()->runningInConsole() && ! app()->runningUnitTests() ? new \Symfony\Component\HttpFoundation\ParameterBag : request()->attributes;
        $memo = $attrs->get('getl1.pr_pos', collect());
        $missing = array_values(array_diff(array_unique($rfqIds), $memo->keys()->all()));
        if ($missing) {
            $found = Award::withoutGlobalScopes()->whereIn('rfq_id', $missing)->where('status', AwardStatus::PoSent->value)->get()->groupBy('rfq_id');
            foreach ($missing as $id) {
                $memo->put($id, $found->get($id, collect()));
            }
            $attrs->set('getl1.pr_pos', $memo);
        }

        return $memo;
    }

    /** Does this PO include any of this request's lines? */
    public function covers(Award $award): bool
    {
        if ($this->rfq_item_ids === null) {
            return (int) $award->rfq_id === (int) $this->rfq_id;
        }

        return collect($award->lines['items'] ?? [])->contains(fn ($l) => in_array((int) ($l['rfq_item_id'] ?? 0), $this->rfq_item_ids, true));
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::PENDING, self::APPROVED], true);
    }

    /**
     * Where the request stands, in words the requester understands, with the PO numbers once ordered.
     *
     * @return array{key: string, label: string, tone: string, pos: list<string>}
     */
    public function progress(): array
    {
        $simple = [
            self::PENDING => ['pending', 'Waiting for approval', 'amber'],
            self::APPROVED => ['approved', 'Approved, waiting for purchase', 'sky'],
            self::REJECTED => ['rejected', 'Rejected', 'red'],
            self::CANCELLED => ['cancelled', 'Cancelled', 'slate'],
        ];
        if (isset($simple[$this->status])) {
            [$k, $l, $t] = $simple[$this->status];

            return ['key' => $k, 'label' => $l, 'tone' => $t, 'pos' => []];
        }

        $rfq = $this->loadMissing('rfq')->rfq;
        $mine = $this->rfq_item_ids; // null for requests converted before lines were tracked: the whole RFQ
        $awards = $rfq ? self::issuedPos([$rfq->id])->get($rfq->id, collect())->filter(fn ($a) => $this->covers($a)) : collect();
        if ($awards->isNotEmpty()) {
            $pos = $awards->pluck('po_number')->filter()->values()->all();
            // Only this request's lines count, never the other requests sharing the RFQ.
            $byItem = [];
            foreach ($awards as $a) {
                foreach (ReceiptService::summary($a)['lines'] as $itemId => $l) {
                    if ($mine === null || in_array((int) $itemId, $mine, true)) {
                        $byItem[(int) $itemId] = $l;
                    }
                }
            }
            $lines = collect($byItem);
            $orderedAll = $mine === null || collect($mine)->every(fn ($id) => $lines->has($id));
            if ($orderedAll && $lines->isNotEmpty() && $lines->every(fn ($l) => $l['pending'] <= 0)) {
                return ['key' => 'received', 'label' => 'Received', 'tone' => 'emerald', 'pos' => $pos];
            }
            if ($lines->contains(fn ($l) => $l['received'] > 0)) {
                return ['key' => 'part_received', 'label' => 'Partly received', 'tone' => 'emerald', 'pos' => $pos];
            }

            return ['key' => 'ordered', 'label' => $orderedAll ? 'Ordered' : 'Partly ordered', 'tone' => 'emerald', 'pos' => $pos];
        }

        $label = match ($rfq?->status) {
            RfqStatus::Draft, RfqStatus::PendingApproval => 'Purchase team preparing the RFQ',
            RfqStatus::Published, RfqStatus::Quoting => 'Collecting quotes from suppliers',
            RfqStatus::Auction => 'Suppliers competing in a live auction',
            RfqStatus::Evaluating, RfqStatus::Awarded => 'Choosing the supplier',
            default => 'With the purchase team',
        };

        return ['key' => 'sourcing', 'label' => $label, 'tone' => 'violet', 'pos' => []];
    }
}
