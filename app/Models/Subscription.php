<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $fillable = [
        'organization_id', 'plan_id', 'billing_cycle', 'razorpay_subscription_id', 'status',
        'trial_ends_at', 'current_period_end', 'cancelled_at', 'current_period_start', 'cancel_at_period_end', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'trial_ends_at' => 'datetime',
            'current_period_end' => 'datetime',
            'cancelled_at' => 'datetime',
            'current_period_start' => 'datetime',
            'cancel_at_period_end' => 'boolean',
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
}
