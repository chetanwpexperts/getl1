<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteItem extends Model
{
    protected $fillable = ['quote_id', 'rfq_item_id', 'unit_price', 'gst_rate', 'freight'];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'gst_rate' => 'decimal:2',
            'freight' => 'decimal:2',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function rfqItem(): BelongsTo
    {
        return $this->belongsTo(RfqItem::class);
    }

    /** Line total incl. GST and freight (landed cost). */
    public function landedTotal(): float
    {
        $qty = (float) $this->rfqItem->qty;
        $base = (float) $this->unit_price * $qty;

        return round($base * (1 + (float) $this->gst_rate / 100) + (float) $this->freight, 2);
    }
}
