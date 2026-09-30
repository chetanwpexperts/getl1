<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only. A bid is never updated or deleted once written.
 */
class Bid extends Model
{
    public const UPDATED_AT = null;

    public const KIND_SEALED = 'sealed';
    public const KIND_LIVE = 'live';

    protected $fillable = [
        'auction_id', 'supplier_org_id', 'user_id', 'rfq_item_id', 'kind', 'idempotency_key', 'amount', 'rank_at_submit', 'ip', 'user_agent',
    ];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Bids are append-only and cannot be updated.'));
        static::deleting(fn () => throw new LogicException('Bids are append-only and cannot be deleted.'));
    }

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class)->withoutGlobalScope('organization');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'supplier_org_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
