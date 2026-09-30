<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live auction: auctions, bids.
 *
 * bids is append-only. Never update or delete a bid row. In production, give the
 * app DB user no DELETE/UPDATE grant on `bids` (see docs/deploy.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auctions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete(); // buyer, denormalised for scoping
            $table->string('format', 20)->default('english_reverse');
            $table->decimal('start_price', 15, 2);
            $table->string('min_decrement_type', 10)->default('percent'); // percent | amount
            $table->decimal('min_decrement_value', 15, 2)->default(0.5);
            $table->decimal('max_decrement_pct', 5, 2)->default(10);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('original_ends_at');
            $table->unsignedInteger('extend_window_sec')->default(120);
            $table->unsignedInteger('extend_by_sec')->default(120);
            $table->unsignedSmallInteger('max_extensions')->default(10);
            $table->unsignedSmallInteger('extensions_used')->default(0);
            $table->string('visibility', 20)->default('rank_only'); // rank_only | rank_and_l1 | blind
            $table->string('status', 20)->default('scheduled')->index(); // scheduled | live | closed | cancelled
            $table->decimal('current_l1', 15, 2)->nullable();
            $table->foreignId('current_l1_supplier_org_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->unsignedInteger('bid_count')->default(0);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'starts_at']);
            $table->index(['status', 'ends_at']);
        });

        Schema::create('bids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_org_id')->constrained('organizations');
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('rfq_item_id')->nullable()->constrained()->nullOnDelete(); // per-item mode
            $table->decimal('amount', 15, 2);
            $table->unsignedSmallInteger('rank_at_submit')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at', 6);            // microseconds: earlier bid wins ties
            $table->index(['auction_id', 'amount', 'created_at']);
            $table->index(['auction_id', 'supplier_org_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bids');
        Schema::dropIfExists('auctions');
    }
};
