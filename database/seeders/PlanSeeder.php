<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * GetL1 plans. Prices are before GST, in rupees. Safe to re-run: updates by code.
 * Every plan includes unlimited RFQs, sealed quotes, suppliers and purchase orders;
 * plans differ in live auctions per month, users and AI usage. Suppliers never pay.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['code' => 'free', 'name' => 'Free', 'tagline' => 'Start buying smarter today', 'price_monthly' => 0, 'price_yearly' => 0,
                'max_auctions_month' => 1, 'max_users' => 2, 'max_ai_calls_month' => 10, 'sort' => 0, 'contact_sales' => false,
                'features' => ['purchase_orders', 'approvals']],
            ['code' => 'starter', 'name' => 'Starter', 'tagline' => 'For small purchase teams', 'price_monthly' => 1499, 'price_yearly' => 14990,
                'max_auctions_month' => 5, 'max_users' => 3, 'max_ai_calls_month' => 50, 'sort' => 1, 'contact_sales' => false,
                'features' => ['purchase_orders', 'approvals', 'savings_report']],
            ['code' => 'growth', 'name' => 'Growth', 'tagline' => 'Most popular', 'price_monthly' => 3999, 'price_yearly' => 39990,
                'max_auctions_month' => 25, 'max_users' => 10, 'max_ai_calls_month' => 300, 'sort' => 2, 'contact_sales' => false,
                'features' => ['purchase_orders', 'approvals', 'savings_report', 'whatsapp', 'ai_rfq']],
            ['code' => 'business', 'name' => 'Business', 'tagline' => 'For multi-plant companies', 'price_monthly' => 9999, 'price_yearly' => 99990,
                'max_auctions_month' => null, 'max_users' => 25, 'max_ai_calls_month' => 1500, 'sort' => 3, 'contact_sales' => true,
                'features' => ['purchase_orders', 'approvals', 'savings_report', 'whatsapp', 'ai_rfq', 'priority_support', 'custom_terms']],
            // Old trial plan: kept so existing records stay valid, never offered again.
            ['code' => 'trial', 'name' => 'Free trial', 'tagline' => null, 'price_monthly' => 0, 'price_yearly' => 0,
                'max_auctions_month' => 25, 'max_users' => 10, 'max_ai_calls_month' => 300, 'sort' => 99, 'is_active' => false, 'contact_sales' => false,
                'features' => ['purchase_orders', 'approvals', 'savings_report', 'whatsapp', 'ai_rfq']],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['code' => $plan['code']], $plan + ['is_active' => true]);
        }
    }
}
