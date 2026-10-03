<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Negotiation after quotes or an auction: the buyer offers one supplier a lower price; the supplier
 * accepts or declines before it expires. An accepted offer becomes that supplier's price for award.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('counter_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete(); // buyer
            $table->foreignId('supplier_org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('auction_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('current_amount', 15, 2);   // the supplier's price when the offer was made
            $table->decimal('offered_amount', 15, 2);
            $table->text('message')->nullable();
            $table->string('status', 12)->default('pending'); // pending | accepted | declined | withdrawn
            $table->timestamp('expires_at');
            $table->foreignId('offered_by')->constrained('users');
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->text('response_note')->nullable();
            $table->timestamps();
            $table->index(['rfq_id', 'status']);
            $table->index(['supplier_org_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counter_offers');
    }
};
