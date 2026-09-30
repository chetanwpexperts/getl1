<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sourcing: rfqs, rfq_items, rfq_attachments, rfq_invites, quotes, quote_items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfqs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->string('ref_no', 30);
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('bid_basis', 20)->default('lot_total'); // lot_total | per_item
            $table->string('currency', 3)->default('INR');
            $table->json('terms')->nullable();              // payment, delivery, taxes, freight
            $table->string('delivery_location')->nullable();
            $table->string('status', 30)->default('draft')->index();
            $table->timestamp('quote_deadline')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'ref_no']);
        });

        Schema::create('rfq_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('name');
            $table->text('spec')->nullable();
            $table->decimal('qty', 15, 3);
            $table->string('unit', 20);                     // pcs, kg, mt, ltr, box ...
            $table->date('delivery_date')->nullable();
            $table->decimal('last_purchase_price', 15, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('rfq_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_id')->constrained()->cascadeOnDelete();
            $table->string('file_path');
            $table->string('original_name');
            $table->unsignedInteger('size_bytes')->nullable();
            $table->timestamps();
        });

        Schema::create('rfq_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_org_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('buyer_supplier_list_id')->nullable()->constrained()->nullOnDelete();
            $table->string('token', 64)->unique();          // magic-link token for WhatsApp/email invite
            $table->string('status', 20)->default('invited'); // invited | accepted | declined
            $table->timestamp('accepted_terms_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
            $table->unique(['rfq_id', 'supplier_org_id']);
        });

        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('total', 15, 2);
            $table->date('valid_till')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['rfq_id', 'supplier_org_id']);
        });

        Schema::create('quote_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rfq_item_id')->constrained()->cascadeOnDelete();
            $table->decimal('unit_price', 15, 2);
            $table->decimal('gst_rate', 5, 2)->default(18);
            $table->decimal('freight', 15, 2)->default(0);
            $table->timestamps();
            $table->unique(['quote_id', 'rfq_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_items');
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('rfq_invites');
        Schema::dropIfExists('rfq_attachments');
        Schema::dropIfExists('rfq_items');
        Schema::dropIfExists('rfqs');
    }
};
