<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Emergency pause by GetL1 staff: the clock stops and bids are refused until resumed.
        Schema::table('auctions', function (Blueprint $table) {
            $table->timestamp('paused_at')->nullable()->after('closed_at');
            $table->string('pause_reason', 255)->nullable()->after('paused_at');
            $table->unsignedInteger('paused_seconds')->default(0)->after('pause_reason'); // total time paused
        });

        // Every refused bid, for the live monitor and disputes. Never shown to other suppliers.
        Schema::create('bid_rejections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_org_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('amount_input', 40)->nullable();
            $table->string('reason', 40);   // too_high | below_floor | too_fast | not_live | paused | invalid
            $table->string('message', 255);
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at', 6);
            $table->index(['auction_id', 'created_at']);
        });

        // Staff can lock a single user (fraud, a leaver who still has access).
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('locked_at')->nullable()->after('two_factor_confirmed_at');
            $table->string('locked_reason', 255)->nullable()->after('locked_at');
        });

        // Platform-wide rules and defaults, edited in the admin console.
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->json('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['locked_at', 'locked_reason']));
        Schema::dropIfExists('bid_rejections');
        Schema::table('auctions', fn (Blueprint $t) => $t->dropColumn(['paused_at', 'pause_reason', 'paused_seconds']));
    }
};
