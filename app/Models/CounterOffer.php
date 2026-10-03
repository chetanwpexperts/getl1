<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A buyer's counter-offer to one supplier. Read by both sides, so not tenant-scoped by a global
 * scope: queries filter by organization_id (buyer) or supplier_org_id (supplier) explicitly.
 */
class CounterOffer extends Model
{
    public const PENDING = 'pending';
    public const ACCEPTED = 'accepted';
    public const DECLINED = 'declined';
    public const WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'rfq_id', 'organization_id', 'supplier_org_id', 'auction_id', 'current_amount', 'offered_amount', 'message',
        'status', 'expires_at', 'offered_by', 'responded_by', 'responded_at', 'response_note',
    ];

    protected function casts(): array
    {
        return [
            'current_amount' => 'decimal:2',
            'offered_amount' => 'decimal:2',
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class)->withoutGlobalScopes();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'supplier_org_id');
    }

    public function offeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'offered_by');
    }

    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }

    /** Waiting for the supplier and not yet expired (expiry is by the clock; no job needed). */
    public function isOpen(): bool
    {
        return $this->status === self::PENDING && $this->expires_at->isFuture();
    }

    /** pending | expired | accepted | declined | withdrawn */
    public function displayStatus(): string
    {
        return $this->status === self::PENDING && ! $this->expires_at->isFuture() ? 'expired' : $this->status;
    }

    public function savingPct(): float
    {
        return (float) $this->current_amount > 0 ? round((1 - (float) $this->offered_amount / (float) $this->current_amount) * 100, 2) : 0.0;
    }
}
