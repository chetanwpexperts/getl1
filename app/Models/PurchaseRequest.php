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
        'requested_by', 'decided_by', 'decided_at', 'decision_note', 'rfq_id', 'converted_by', 'converted_at',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array', 'needed_by' => 'date', 'decided_at' => 'datetime', 'converted_at' => 'datetime',
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
        $awards = $rfq ? Award::withoutGlobalScopes()->where('rfq_id', $rfq->id)->where('status', AwardStatus::PoSent->value)->get() : collect();
        if ($awards->isNotEmpty()) {
            $pos = $awards->pluck('po_number')->filter()->values()->all();
            $summaries = $awards->map(fn ($a) => ReceiptService::summary($a));
            if ($summaries->every(fn ($s) => $s['complete'])) {
                return ['key' => 'received', 'label' => 'Received', 'tone' => 'emerald', 'pos' => $pos];
            }
            if ($summaries->contains(fn ($s) => $s['latest'] !== null)) {
                return ['key' => 'part_received', 'label' => 'Partly received', 'tone' => 'emerald', 'pos' => $pos];
            }

            return ['key' => 'ordered', 'label' => 'Ordered', 'tone' => 'emerald', 'pos' => $pos];
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
