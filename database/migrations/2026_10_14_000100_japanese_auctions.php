<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Japanese (falling-price) reverse auctions.
 *
 * - auctions.format = 'japanese'; opening_price is the round-1 price, round_seconds the length of
 *   each round; the drop per round reuses min_decrement_type/value and the floor reuses
 *   max_decrement_pct (stop at that % below the opening price).
 * - bids.round: which round a supplier accepted (one acceptance per supplier per round).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auctions', function (Blueprint $table) {
            $table->decimal('opening_price', 15, 2)->nullable()->after('start_price');
            $table->unsignedSmallInteger('round_seconds')->nullable()->after('opening_price');
        });
        Schema::table('bids', function (Blueprint $table) {
            $table->unsignedSmallInteger('round')->nullable()->after('kind');
            $table->unique(['auction_id', 'supplier_org_id', 'round'], 'bids_one_accept_per_round');
        });
    }

    public function down(): void
    {
        Schema::table('bids', function (Blueprint $table) {
            $table->dropUnique('bids_one_accept_per_round');
            $table->dropColumn('round');
        });
        Schema::table('auctions', fn (Blueprint $table) => $table->dropColumn(['opening_price', 'round_seconds']));
    }
};
