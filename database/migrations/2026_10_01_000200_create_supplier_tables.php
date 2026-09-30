<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier network: categories, supplier_categories, supplier_documents, buyer_supplier_lists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('supplier_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'category_id']);
        });

        Schema::create('supplier_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);                     // gst | pan | udyam | other
            $table->string('file_path');
            $table->string('original_name')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('status', 20)->default('pending'); // pending | verified | rejected
            $table->string('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('buyer_supplier_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('supplier_org_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            // A buyer can add a supplier who hasn't joined yet: keep contact until they register.
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->string('company_name')->nullable();
            $table->string('tag', 50)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('active'); // active | blocked
            $table->timestamps();
            $table->index(['buyer_org_id', 'status']);
            $table->unique(['buyer_org_id', 'supplier_org_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_supplier_lists');
        Schema::dropIfExists('supplier_documents');
        Schema::dropIfExists('supplier_categories');
        Schema::dropIfExists('categories');
    }
};
