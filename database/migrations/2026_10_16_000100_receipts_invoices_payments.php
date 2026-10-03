<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * After the PO: goods receipts, supplier invoices and payments.
 *
 * - goods_receipts: what arrived against a PO (per line: received, accepted, rejected + reason).
 *   Several per PO for part deliveries. Numbered GRN-2026-0001 per buyer company.
 * - supplier_invoices: the supplier's GST invoice uploaded against a PO, checked against the PO,
 *   the goods received, the GST rate and the GSTIN; approved or disputed by the buyer; paid.
 *   due_date follows the MSMED Act for MSME suppliers (≤ 45 days from acceptance; 15 without an
 *   agreed credit period), which also decides the buyer's tax deduction (Income Tax Act 43B(h)).
 * - buyer_supplier_lists.is_msme: the buyer can mark a supplier as MSME even if the supplier
 *   hasn't entered its Udyam number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('award_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete(); // buyer
            $table->foreignId('supplier_org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('grn_number', 30);
            $table->date('received_on');
            $table->json('lines');                 // [{rfq_item_id, name, unit, received, accepted, rejected, reason}]
            $table->string('challan_no', 60)->nullable(); // supplier's delivery challan / e-way bill
            $table->text('notes')->nullable();
            $table->foreignId('received_by')->constrained('users');
            $table->timestamps();
            $table->unique(['organization_id', 'grn_number']);
            $table->index(['award_id', 'received_on']);
        });

        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('award_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete(); // buyer
            $table->foreignId('supplier_org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('invoice_number', 40);
            // "<supplier>:<FY>:<number>" while the invoice counts; null once disputed, so a corrected
            // invoice may reuse the number. GST rule 46: numbers are unique within a financial year.
            $table->string('active_key', 80)->nullable()->unique();
            $table->date('invoice_date');
            $table->decimal('taxable_amount', 15, 2);
            $table->decimal('gst_amount', 15, 2);
            $table->decimal('total_amount', 15, 2);
            $table->string('supplier_gstin', 15)->nullable(); // as printed on the invoice
            $table->string('file_path');
            $table->string('original_name');
            $table->string('status', 12)->default('submitted'); // submitted | approved | disputed | paid
            $table->boolean('is_msme')->default(false);
            $table->date('due_date')->nullable();
            $table->string('due_basis', 190)->nullable();   // how the due date was worked out
            $table->text('review_note')->nullable();        // dispute reason, or note when approving with warnings
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->date('paid_on')->nullable();
            $table->decimal('paid_amount', 15, 2)->nullable();
            $table->string('payment_ref', 60)->nullable();
            $table->foreignId('paid_marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('last_reminded_on')->nullable();
            $table->foreignId('submitted_by')->constrained('users');
            $table->timestamps();
            $table->index(['supplier_org_id', 'invoice_number']);
            $table->index(['organization_id', 'status', 'due_date']);
            $table->index('award_id');
        });

        Schema::table('buyer_supplier_lists', function (Blueprint $table) {
            $table->boolean('is_msme')->default(false)->after('tag');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->date('payments_digest_on')->nullable()->after('tally_settings'); // last MSME payment reminder
        });
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn('payments_digest_on'));
        Schema::table('buyer_supplier_lists', fn (Blueprint $table) => $table->dropColumn('is_msme'));
        Schema::dropIfExists('supplier_invoices');
        Schema::dropIfExists('goods_receipts');
    }
};
