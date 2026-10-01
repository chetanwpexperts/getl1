<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A bid the server refused, and why. Insert-only. */
class BidRejection extends Model
{
    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['auction_id', 'supplier_org_id', 'user_id', 'amount_input', 'reason', 'message', 'ip'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime:Y-m-d H:i:s.u'];
    }
}
