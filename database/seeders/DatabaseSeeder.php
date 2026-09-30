<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * php artisan db:seed              → plans + categories (safe for production)
     * php artisan db:seed --class=DemoSeeder  → local demo buyer + 5 suppliers
     */
    public function run(): void
    {
        $this->call([
            PlanSeeder::class,
            CategorySeeder::class,
        ]);

        if (app()->environment('local')) {
            $this->call(DemoSeeder::class);
        }
    }
}
