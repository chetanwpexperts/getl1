<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What arrived against a PO. Scoped to the buyer company; suppliers read it through their own PO. */
class GoodsReceipt extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['award_id', 'organization_id', 'supplier_org_id', 'grn_number', 'received_on', 'lines', 'challan_no', 'notes', 'received_by'];

    protected function casts(): array
    {
        return ['received_on' => 'date', 'lines' => 'array'];
    }

    public function award(): BelongsTo
    {
        return $this->belongsTo(Award::class)->withoutGlobalScopes();
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function rejectedQty(): float
    {
        return (float) collect($this->lines)->sum('rejected');
    }
}
