<?php

namespace App\Models;

use App\Enums\AwardStatus;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Award extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'rfq_id', 'organization_id', 'supplier_org_id', 'rfq_item_id', 'qty', 'unit_price', 'total', 'status',
        'awarded_by', 'approved_by', 'approved_at', 'po_number', 'po_pdf_path', 'po_sent_at', 'remarks',
        'source', 'auction_id', 'group_key', 'rank', 'reason', 'lines', 'gst_total', 'freight_total', 'grand_total',
        'decision_note', 'supplier_accepted_at', 'supplier_accepted_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => AwardStatus::class,
            'qty' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'total' => 'decimal:2',
            'approved_at' => 'datetime',
            'po_sent_at' => 'datetime',
            'lines' => 'array',
            'gst_total' => 'decimal:2',
            'freight_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'supplier_accepted_at' => 'datetime',
        ];
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class)->withoutGlobalScope('organization');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'supplier_org_id');
    }

    public function rfqItem(): BelongsTo
    {
        return $this->belongsTo(RfqItem::class);
    }

    public function awarder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'awarded_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class)->withoutGlobalScope('organization');
    }

    public function isPending(): bool
    {
        return $this->status === AwardStatus::PendingApproval;
    }

    public function isRejected(): bool
    {
        return $this->status === AwardStatus::Rejected;
    }

    /** Approved and the PO has been (or is being) issued. */
    public function isFinal(): bool
    {
        return in_array($this->status, [AwardStatus::Approved, AwardStatus::PoSent], true);
    }
}
