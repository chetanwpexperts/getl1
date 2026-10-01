<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = [
        'code', 'name', 'price_monthly', 'price_yearly', 'max_auctions_month', 'max_users',
        'max_ai_calls_month', 'features', 'razorpay_plan_id_monthly', 'razorpay_plan_id_yearly',
        'is_active', 'sort', 'contact_sales', 'tagline', 'razorpay_plan_amount_monthly', 'razorpay_plan_amount_yearly',
    ];

    protected function casts(): array
    {
        return [
            'price_monthly' => 'decimal:2',
            'price_yearly' => 'decimal:2',
            'features' => 'array',
            'is_active' => 'boolean',
            'contact_sales' => 'boolean',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** What the plan includes, in customer words. Shared by the website and the Billing page. */
    public function featureList(): array
    {
        $f = $this->features ?? [];
        $list = [
            $this->max_auctions_month === null ? 'Unlimited live auctions' : $this->max_auctions_month.' live '.\Illuminate\Support\Str::plural('auction', $this->max_auctions_month).' / month',
            'Up to '.$this->max_users.' team members',
            'Unlimited RFQs, quotes & suppliers',
            'Purchase orders & approvals',
        ];
        if (in_array('savings_report', $f, true)) {
            $list[] = 'Savings report & export';
        }
        if (in_array('ai_rfq', $f, true)) {
            $list[] = 'AI requirement reading'.($this->max_ai_calls_month ? ' ('.number_format($this->max_ai_calls_month).' / month)' : '');
        }
        if (in_array('priority_support', $f, true)) {
            $list[] = 'Priority support & custom terms';
        }

        return $list;
    }

    public function isUnlimitedAuctions(): bool
    {
        return $this->max_auctions_month === null;
    }
}
