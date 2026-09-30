<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A buyer's private supplier roster (table: buyer_supplier_lists).
 * supplier_org_id is null until the supplier registers; contact fields hold the invite target.
 */
class BuyerSupplier extends Model
{
    protected $table = 'buyer_supplier_lists';

    protected $fillable = [
        'buyer_org_id', 'supplier_org_id', 'contact_name', 'contact_email', 'contact_phone',
        'company_name', 'tag', 'notes', 'status',
    ];

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'buyer_org_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'supplier_org_id');
    }

    public function displayName(): string
    {
        return $this->supplier?->name ?? $this->company_name ?? $this->contact_name ?? 'Supplier';
    }
}
