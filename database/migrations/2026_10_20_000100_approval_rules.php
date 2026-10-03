<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rule-based award approvals.
 *
 * - approval_rules: a company's approval levels, in order. A level applies to an award when the
 *   amount (before GST) reaches min_amount, or when any ticked condition holds (not L1, only one
 *   quote, first order with that supplier). Approver: one named person, or any approver/admin.
 * - award_approval_steps: the levels an award (or an item-wise split, by group_key) must pass, in
 *   order, worked out when it was made. Changing the rules later never changes these.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('name', 80);
            $table->decimal('min_amount', 15, 2)->nullable();
            $table->boolean('when_not_l1')->default(false);
            $table->boolean('when_single_quote')->default(false);
            $table->boolean('when_new_supplier')->default(false);
            $table->foreignId('approver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'position']);
        });

        Schema::create('award_approval_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('award_id')->constrained()->cascadeOnDelete(); // the first award of a split
            $table->string('group_key', 36)->nullable()->index();
            $table->unsignedSmallInteger('position');
            $table->string('name', 80);
            $table->string('why', 255);
            $table->foreignId('approver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 12)->default('pending'); // pending | approved | rejected | cancelled
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('note', 1000)->nullable();
            $table->timestamps();
            $table->index(['award_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('award_approval_steps');
        Schema::dropIfExists('approval_rules');
    }
};
