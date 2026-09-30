<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['code' => 'trial', 'name' => 'Free trial', 'price_monthly' => 0, 'price_yearly' => 0,
                'max_auctions_month' => 3, 'max_users' => 2, 'max_ai_calls_month' => 20, 'sort' => 0,
                'features' => ['email_notifications', 'savings_report', 'whatsapp', 'approval_chain']],
            ['code' => 'starter', 'name' => 'Starter', 'price_monthly' => 2999, 'price_yearly' => 29990,
                'max_auctions_month' => 10, 'max_users' => 3, 'max_ai_calls_month' => 50, 'sort' => 1,
                'features' => ['email_notifications', 'basic_reports']],
            ['code' => 'growth', 'name' => 'Growth', 'price_monthly' => 7999, 'price_yearly' => 79990,
                'max_auctions_month' => 40, 'max_users' => 10, 'max_ai_calls_month' => 300, 'sort' => 2,
                'features' => ['email_notifications', 'whatsapp', 'approval_chain', 'savings_report']],
            ['code' => 'business', 'name' => 'Business', 'price_monthly' => 19999, 'price_yearly' => 199990,
                'max_auctions_month' => null, 'max_users' => 25, 'max_ai_calls_month' => 1500, 'sort' => 3,
                'features' => ['email_notifications', 'whatsapp', 'approval_chain', 'savings_report', 'custom_fields', 'api', 'priority_support']],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['code' => $plan['code']], $plan);
        }
    }
}
