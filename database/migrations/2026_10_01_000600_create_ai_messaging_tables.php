<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI & messaging: ai_jobs, supplier_scores, whatsapp_messages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30)->index();           // rfq_parse | supplier_match | auction_setup | award_suggest | doc_verify | assistant
            $table->nullableMorphs('input_ref');
            $table->string('input_file_path')->nullable();
            $table->longText('input_text')->nullable();
            $table->json('output')->nullable();
            $table->string('status', 20)->default('queued'); // queued | done | failed | accepted | rejected
            $table->string('error')->nullable();
            $table->string('model', 60)->nullable();
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->decimal('cost_inr', 10, 2)->default(0);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('supplier_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_org_id')->nullable()->constrained('organizations')->cascadeOnDelete(); // null = platform-wide
            $table->foreignId('supplier_org_id')->constrained('organizations')->cascadeOnDelete();
            $table->decimal('on_time_pct', 5, 2)->nullable();
            $table->decimal('quality_rating', 3, 2)->nullable(); // 0-5
            $table->decimal('response_rate', 5, 2)->nullable();
            $table->unsignedInteger('auctions_joined')->default(0);
            $table->unsignedInteger('auctions_won')->default(0);
            $table->timestamp('last_computed_at')->nullable();
            $table->timestamps();
            $table->unique(['buyer_org_id', 'supplier_org_id']);
        });

        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient_phone', 20)->index();
            $table->foreignId('supplier_org_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('template', 60)->nullable();
            $table->string('direction', 3);                // out | in
            $table->text('body')->nullable();
            $table->nullableMorphs('related');
            $table->string('provider_message_id')->nullable()->index();
            $table->string('status', 20)->default('queued'); // queued | sent | delivered | read | failed
            $table->string('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('supplier_scores');
        Schema::dropIfExists('ai_jobs');
    }
};
