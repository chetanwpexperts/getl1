<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Prepaid AI reads (AI packs), used when the plan has no AI or the month's reads are used up. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->unsignedInteger('ai_credits')->default(0)->after('auction_credits');
        });
        Schema::table('ai_jobs', function (Blueprint $table) {
            $table->boolean('paid_with_credit')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('ai_jobs', fn (Blueprint $t) => $t->dropColumn('paid_with_credit'));
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn('ai_credits'));
    }
};
