<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One approval level of a buyer company. */
class ApprovalRule extends Model
{
    use BelongsToOrganization;

    public const MAX = 10;

    protected $fillable = ['organization_id', 'position', 'name', 'min_amount', 'when_not_l1', 'when_single_quote', 'when_new_supplier', 'approver_user_id'];

    protected function casts(): array
    {
        return ['min_amount' => 'decimal:2', 'when_not_l1' => 'boolean', 'when_single_quote' => 'boolean', 'when_new_supplier' => 'boolean'];
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    /** "From ₹5,00,000 · or when not L1" */
    public function describe(): string
    {
        $parts = [];
        if ($this->min_amount !== null) {
            $parts[] = (float) $this->min_amount > 0 ? 'Awards from '.\App\Support\Money::inr($this->min_amount, 0).' (before GST)' : 'Every award';
        }
        $conds = array_filter([
            $this->when_not_l1 ? 'not given to L1' : null,
            $this->when_single_quote ? 'only one quote received' : null,
            $this->when_new_supplier ? 'first order with the supplier' : null,
        ]);
        if ($conds) {
            $parts[] = ($parts ? 'or when ' : 'When ').implode(', or ', $conds);
        }

        return implode(', ', $parts);
    }
}
