<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A supplier's question on an RFQ and the buyer's answer, or a clarification the buyer posted
 * to everyone. Not tenant-scoped by a global scope: both sides read it, so every query filters
 * explicitly (buyer by organization_id, supplier with visibleTo()).
 */
class RfqQuestion extends Model
{
    public const ALL = 'all';
    public const PRIVATE = 'private';

    protected $fillable = [
        'rfq_id', 'organization_id', 'supplier_org_id', 'asked_by', 'question', 'answer', 'visibility', 'answered_by', 'answered_at',
    ];

    protected function casts(): array
    {
        return ['answered_at' => 'datetime'];
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class)->withoutGlobalScopes();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'supplier_org_id');
    }

    public function asker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asked_by');
    }

    public function answerer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by');
    }

    public function isAnnouncement(): bool
    {
        return $this->supplier_org_id === null;
    }

    public function isAnswered(): bool
    {
        return $this->answered_at !== null;
    }

    /** What one supplier may see on an RFQ: answers for everyone, plus its own questions. */
    public function scopeVisibleTo(Builder $q, int $rfqId, int $supplierOrgId): Builder
    {
        return $q->where('rfq_id', $rfqId)->where(fn ($w) => $w
            ->where('supplier_org_id', $supplierOrgId)
            ->orWhere(fn ($p) => $p->where('visibility', self::ALL)->whereNotNull('answered_at')));
    }
}
