<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Sealed quote submitted before the live auction. */
class Quote extends Model
{
    protected $fillable = [
        'rfq_id', 'supplier_org_id', 'submitted_by', 'total', 'valid_till', 'notes', 'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'valid_till' => 'date',
            'submitted_at' => 'datetime',
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

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class);
    }
}
