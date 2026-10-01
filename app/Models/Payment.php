<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One charge (subscription payment or auction credits). Rows are never deleted. */
class Payment extends Model
{
    public const KIND_SUBSCRIPTION = 'subscription';
    public const KIND_CREDITS = 'auction_credits';

    protected $fillable = [
        'organization_id', 'subscription_id', 'plan_id', 'created_by', 'kind', 'billing_cycle', 'quantity',
        'amount', 'gst_rate', 'gst_amount', 'total', 'currency', 'status', 'razorpay_order_id', 'razorpay_payment_id',
        'razorpay_subscription_id', 'invoice_number', 'invoice_pdf_path', 'billed_to', 'period_start', 'period_end',
        'paid_at', 'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'gst_rate' => 'decimal:2',
            'gst_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'billed_to' => 'array',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function description(): string
    {
        return $this->kind === self::KIND_CREDITS
            ? $this->quantity.' live auction '.($this->quantity === 1 ? 'credit' : 'credits')
            : 'GetL1 '.($this->plan?->name ?? '').' plan, '.($this->billing_cycle === 'yearly' ? 'yearly' : 'monthly').' subscription';
    }
}
