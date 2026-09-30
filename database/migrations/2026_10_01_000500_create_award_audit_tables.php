<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Award & audit: awards, audit_logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('awards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete(); // buyer
            $table->foreignId('supplier_org_id')->constrained('organizations');
            $table->foreignId('rfq_item_id')->nullable()->constrained()->nullOnDelete(); // null = whole lot
            $table->decimal('qty', 15, 3)->nullable();
            $table->decimal('unit_price', 15, 2)->nullable();
            $table->decimal('total', 15, 2);
            $table->string('status', 30)->default('pending_approval'); // pending_approval | approved | rejected | po_sent
            $table->foreignId('awarded_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('po_number', 40)->nullable()->unique();
            $table->string('po_pdf_path')->nullable();
            $table->timestamp('po_sent_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->nullableMorphs('auditable');
            $table->string('action', 50);                  // created | updated | published | bid_placed | awarded ...
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at', 6);
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('awards');
    }
};
