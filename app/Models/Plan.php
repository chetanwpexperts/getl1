<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = [
        'code', 'name', 'price_monthly', 'price_yearly', 'max_auctions_month', 'max_users',
        'max_ai_calls_month', 'features', 'razorpay_plan_id_monthly', 'razorpay_plan_id_yearly',
        'is_active', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'price_monthly' => 'decimal:2',
            'price_yearly' => 'decimal:2',
            'features' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function isUnlimitedAuctions(): bool
    {
        return $this->max_auctions_month === null;
    }
}
