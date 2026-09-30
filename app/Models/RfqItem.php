<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RfqItem extends Model
{
    protected $fillable = [
        'rfq_id', 'line_no', 'name', 'spec', 'qty', 'unit', 'delivery_date', 'last_purchase_price',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'delivery_date' => 'date',
            'last_purchase_price' => 'decimal:2',
        ];
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class)->withoutGlobalScope('organization');
    }
}
