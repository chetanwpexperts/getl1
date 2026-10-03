<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item-wise bidding.
 *
 * - auctions.bid_basis: copied from the RFQ when the auction is scheduled. 'per_item' means each
 *   RFQ line is ranked on its own (bids carry rfq_item_id and a unit price).
 * - awards.group_key: one award decision on an item-wise RFQ can create several awards (one per
 *   supplier, each with its own PO). They share a group key and are approved or rejected together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auctions', function (Blueprint $table) {
            $table->string('bid_basis', 20)->default('lot_total')->after('format');
        });

        Schema::table('bids', function (Blueprint $table) {
            $table->index(['auction_id', 'rfq_item_id', 'created_at'], 'bids_auction_item_index');
        });

        Schema::table('awards', function (Blueprint $table) {
            $table->string('group_key', 36)->nullable()->after('auction_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('awards', function (Blueprint $table) {
            $table->dropIndex(['group_key']);
            $table->dropColumn('group_key');
        });
        Schema::table('bids', fn (Blueprint $table) => $table->dropIndex('bids_auction_item_index'));
        Schema::table('auctions', fn (Blueprint $table) => $table->dropColumn('bid_basis'));
    }
};
