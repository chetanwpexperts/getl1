<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase requests (indents): staff ask for material, an approver approves, a buyer turns one or
 * more approved requests into an RFQ. The request then follows the RFQ to PO and delivery.
 *
 * items: [{name, spec, qty, unit, est_rate}] — est_rate is the requester's rough idea, never shown to suppliers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('pr_number', 20);
            $table->string('title', 150);
            $table->string('department', 100)->nullable();
            $table->date('needed_by')->nullable();
            $table->text('notes')->nullable();
            $table->json('items');
            $table->decimal('estimated_total', 15, 2)->nullable();
            $table->string('status', 20)->default('pending'); // pending | approved | rejected | converted | cancelled
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 1000)->nullable();
            $table->foreignId('rfq_id')->nullable()->constrained('rfqs')->nullOnDelete();
            $table->foreignId('converted_by')->nullable()->constrained('users');
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'pr_number']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'requested_by']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_requests');
    }
};
