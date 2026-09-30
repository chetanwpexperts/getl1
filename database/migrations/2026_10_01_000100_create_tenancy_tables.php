<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenancy & billing: organizations, org_user, plans, subscriptions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->index();            // buyer | supplier
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('gstin', 15)->nullable()->index();
            $table->string('pan', 10)->nullable();
            $table->string('udyam_no', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('address')->nullable();
            $table->string('city', 100)->nullable()->index();
            $table->string('state', 100)->nullable();
            $table->string('pincode', 10)->nullable();
            $table->string('locale', 5)->default('en');    // en | hi | pa
            $table->timestamp('verified_at')->nullable();
            $table->string('status', 20)->default('active'); // active | suspended
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('org_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);                     // buyer_admin | buyer_user | approver | supplier_user
            $table->boolean('is_owner')->default(false);
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();           // trial | starter | growth | business
            $table->string('name');
            $table->decimal('price_monthly', 15, 2)->default(0);
            $table->decimal('price_yearly', 15, 2)->default(0);
            $table->unsignedInteger('max_auctions_month')->nullable(); // null = unlimited
            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_ai_calls_month')->nullable();
            $table->json('features')->nullable();
            $table->string('razorpay_plan_id_monthly')->nullable();
            $table->string('razorpay_plan_id_yearly')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();
            $table->string('billing_cycle', 10)->default('monthly'); // monthly | yearly
            $table->string('razorpay_subscription_id')->nullable()->unique();
            $table->string('status', 20)->index();          // trialing | active | past_due | cancelled | expired
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('current_organization_id')->references('id')->on('organizations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['current_organization_id']);
        });
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('org_user');
        Schema::dropIfExists('organizations');
    }
};
