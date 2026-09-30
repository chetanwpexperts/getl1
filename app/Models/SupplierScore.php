<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierScore extends Model
{
    protected $fillable = [
        'buyer_org_id', 'supplier_org_id', 'on_time_pct', 'quality_rating', 'response_rate',
        'auctions_joined', 'auctions_won', 'last_computed_at',
    ];

    protected function casts(): array
    {
        return [
            'on_time_pct' => 'decimal:2',
            'quality_rating' => 'decimal:2',
            'response_rate' => 'decimal:2',
            'last_computed_at' => 'datetime',
        ];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'buyer_org_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'supplier_org_id');
    }
}
