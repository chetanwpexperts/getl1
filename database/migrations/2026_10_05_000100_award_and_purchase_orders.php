<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Award → approval → purchase order.
 *
 * - awards keep a frozen snapshot of the PO lines (what was agreed never changes later),
 *   the reason when not awarding to L1, the approval decision and the supplier's acceptance.
 * - PO numbers are unique per buyer company (PO-2026-0001 in every company).
 * - Buyer companies set an approval limit and their standard PO terms.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('awards', function (Blueprint $table) {
            $table->dropUnique(['po_number']);
        });

        Schema::table('awards', function (Blueprint $table) {
            $table->unique(['organization_id', 'po_number']);
            $table->string('source', 10)->default('quote')->after('supplier_org_id');   // quote | auction
            $table->foreignId('auction_id')->nullable()->after('source')->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('rank')->nullable()->after('auction_id');          // rank when awarded (1 = L1)
            $table->text('reason')->nullable()->after('remarks');                           // required when not L1
            $table->json('lines')->nullable()->after('total');                               // frozen PO lines
            $table->decimal('gst_total', 15, 2)->default(0)->after('lines');
            $table->decimal('freight_total', 15, 2)->default(0)->after('gst_total');
            $table->decimal('grand_total', 15, 2)->default(0)->after('freight_total');
            $table->text('decision_note')->nullable()->after('approved_at');                 // approver's comment
            $table->timestamp('supplier_accepted_at')->nullable()->after('po_sent_at');
            $table->foreignId('supplier_accepted_by')->nullable()->after('supplier_accepted_at')->constrained('users')->nullOnDelete();
            $table->index(['organization_id', 'status']);
            $table->index(['supplier_org_id', 'status']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->decimal('award_approval_limit', 15, 2)->nullable()->after('status'); // null/0 = approve every award
            $table->text('po_terms')->nullable()->after('award_approval_limit');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn(['award_approval_limit', 'po_terms']));
        Schema::table('awards', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'po_number']);
            $table->dropIndex(['organization_id', 'status']);
            $table->dropIndex(['supplier_org_id', 'status']);
            $table->dropConstrainedForeignId('auction_id');
            $table->dropConstrainedForeignId('supplier_accepted_by');
            $table->dropColumn(['source', 'rank', 'reason', 'lines', 'gst_total', 'freight_total', 'grand_total', 'decision_note', 'supplier_accepted_at']);
            $table->unique('po_number');
        });
    }
};
