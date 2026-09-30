<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Already sent" markers for automatic notifications, so each one goes out exactly once
 * even if the scheduler overlaps or is re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            $table->timestamp('quotes_opened_notified_at')->nullable()->after('published_at');
        });
        Schema::table('rfq_invites', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable()->after('last_sent_at');
        });
        Schema::table('auctions', function (Blueprint $table) {
            $table->timestamp('start_reminded_at')->nullable()->after('closed_at');
            $table->timestamp('results_notified_at')->nullable()->after('start_reminded_at');
        });
    }

    public function down(): void
    {
        Schema::table('rfqs', fn (Blueprint $t) => $t->dropColumn('quotes_opened_notified_at'));
        Schema::table('rfq_invites', fn (Blueprint $t) => $t->dropColumn('reminded_at'));
        Schema::table('auctions', fn (Blueprint $t) => $t->dropColumn(['start_reminded_at', 'results_notified_at']));
    }
};
