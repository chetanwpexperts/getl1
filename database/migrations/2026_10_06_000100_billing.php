<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing: Razorpay subscriptions and one-time auction credits.
 *
 * - payments: every charge, one row per Razorpay order/payment, with its invoice number.
 * - webhook_events: Razorpay event ids already processed (webhooks are retried; process once).
 * - organizations.auction_credits: prepaid auctions beyond the monthly plan limit.
 * - auctions.paid_with_credit: so cancelling an auction gives the credit back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('razorpay_plan_amount_monthly')->nullable()->after('razorpay_plan_id_yearly'); // paise the Razorpay plan was created with
            $table->unsignedInteger('razorpay_plan_amount_yearly')->nullable()->after('razorpay_plan_amount_monthly');
            $table->boolean('contact_sales')->default(false)->after('is_active'); // show "Talk to us" instead of a price
            $table->string('tagline')->nullable()->after('name');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('current_period_start')->nullable()->after('trial_ends_at');
            $table->boolean('cancel_at_period_end')->default(false)->after('current_period_end');
            $table->foreignId('created_by')->nullable()->after('plan_id')->constrained('users')->nullOnDelete();
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->unsignedInteger('auction_credits')->default(0)->after('po_terms');
        });

        Schema::table('auctions', function (Blueprint $table) {
            $table->boolean('paid_with_credit')->default(false)->after('cancel_reason');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 20);                         // subscription | auction_credits
            $table->string('billing_cycle', 10)->nullable();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->decimal('amount', 12, 2);                   // before GST
            $table->decimal('gst_rate', 5, 2)->default(0);
            $table->decimal('gst_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2);                    // charged
            $table->string('currency', 3)->default('INR');
            $table->string('status', 20)->default('created')->index(); // created | paid | failed
            $table->string('razorpay_order_id')->nullable()->unique();
            $table->string('razorpay_payment_id')->nullable()->unique();
            $table->string('razorpay_subscription_id')->nullable()->index();
            $table->string('invoice_number', 40)->nullable()->unique();
            $table->string('invoice_pdf_path')->nullable();
            $table->json('billed_to')->nullable();              // buyer name/GSTIN/address frozen at payment time
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('event_id', 100);
            $table->string('event', 60);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('payments');
        Schema::table('auctions', fn (Blueprint $t) => $t->dropColumn('paid_with_credit'));
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn('auction_credits'));
        Schema::table('subscriptions', function (Blueprint $t) {
            $t->dropConstrainedForeignId('created_by');
            $t->dropColumn(['current_period_start', 'cancel_at_period_end']);
        });
        Schema::table('plans', fn (Blueprint $t) => $t->dropColumn(['razorpay_plan_amount_monthly', 'razorpay_plan_amount_yearly', 'contact_sales', 'tagline']));
    }
};
