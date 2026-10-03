<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One item line of an issued purchase order: what was paid, to whom, when. Buyer company only. */
class PricePoint extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'item_key', 'item_name', 'spec', 'unit', 'supplier_org_id', 'award_id', 'rate', 'qty', 'gst_rate', 'priced_on'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:4', 'qty' => 'decimal:3', 'gst_rate' => 'decimal:2', 'priced_on' => 'date'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'supplier_org_id');
    }

    public function award(): BelongsTo
    {
        return $this->belongsTo(Award::class)->withoutGlobalScopes();
    }
}
