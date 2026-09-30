<?php

namespace App\Models;

use App\Enums\AuctionStatus;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Auction extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'rfq_id', 'organization_id', 'created_by', 'cancel_reason', 'format', 'start_price', 'min_decrement_type', 'min_decrement_value',
        'max_decrement_pct', 'starts_at', 'ends_at', 'original_ends_at', 'extend_window_sec', 'extend_by_sec',
        'max_extensions', 'extensions_used', 'visibility', 'status', 'current_l1', 'current_l1_supplier_org_id',
        'bid_count', 'opened_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => AuctionStatus::class,
            'start_price' => 'decimal:2',
            'min_decrement_value' => 'decimal:2',
            'max_decrement_pct' => 'decimal:2',
            'current_l1' => 'decimal:2',
            'start_reminded_at' => 'datetime',
            'results_notified_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'original_ends_at' => 'datetime',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class)->withoutGlobalScope('organization');
    }

    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class);
    }

    public function l1Supplier(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'current_l1_supplier_org_id');
    }

    public function isLive(): bool
    {
        return $this->status === AuctionStatus::Live;
    }

    /** Minimum amount a supplier must go below its own last bid (or the start price). */
    public function minDecrementFrom(float $reference): float
    {
        return $this->min_decrement_type === 'percent'
            ? round($reference * (float) $this->min_decrement_value / 100, 2)
            : (float) $this->min_decrement_value;
    }

    /** Savings of current L1 vs start price, in percent. */
    public function savingsPct(): ?float
    {
        if ($this->current_l1 === null || (float) $this->start_price <= 0) {
            return null;
        }

        return round((1 - (float) $this->current_l1 / (float) $this->start_price) * 100, 2);
    }
}
