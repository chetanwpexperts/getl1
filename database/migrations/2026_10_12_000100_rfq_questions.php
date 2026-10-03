<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clarifications on an RFQ while quotes are open.
 *
 * - A supplier asks (supplier_org_id + question); the buyer answers, either to everyone
 *   (visibility 'all': other suppliers see the question and answer, never who asked) or only
 *   to the supplier who asked ('private').
 * - The buyer can also post a clarification to everyone without a question
 *   (supplier_org_id and question null, answer filled).
 * Answers are final once posted (kept for the audit record).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfq_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete(); // buyer
            $table->foreignId('supplier_org_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('asked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('question')->nullable();
            $table->text('answer')->nullable();
            $table->string('visibility', 10)->default('all'); // all | private
            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();
            $table->index(['rfq_id', 'created_at']);
            $table->index(['rfq_id', 'supplier_org_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rfq_questions');
    }
};
