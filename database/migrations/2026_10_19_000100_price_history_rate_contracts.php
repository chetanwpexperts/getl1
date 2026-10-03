<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Price history and rate contracts.
 *
 * - price_points: one row per item line of every purchase order issued, keyed by the item name +
 *   unit (item_key) so the same item can be followed across RFQs and suppliers.
 *   Existing POs are copied in here, so history starts full on day one.
 * - rate_contracts: an agreed rate per item with one supplier for a period.
 *   items: [{name, spec, unit, rate, gst_rate}]
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('item_key', 191);
            $table->string('item_name', 150);
            $table->string('spec', 1000)->nullable();
            $table->string('unit', 20);
            $table->foreignId('supplier_org_id')->constrained('organizations');
            $table->foreignId('award_id')->constrained()->cascadeOnDelete();
            $table->decimal('rate', 15, 4); // auction rates on cheap items can have 4 decimals
            $table->decimal('qty', 15, 3)->nullable();
            $table->decimal('gst_rate', 5, 2)->nullable();
            $table->date('priced_on');
            $table->timestamps();

            $table->index(['organization_id', 'item_key', 'priced_on']);
            $table->unique(['award_id', 'item_key']);
        });

        Schema::create('rate_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('rc_number', 20);
            $table->foreignId('supplier_org_id')->constrained('organizations');
            $table->string('title', 150);
            $table->date('valid_from');
            $table->date('valid_to');
            $table->json('items');
            $table->text('terms')->nullable();
            $table->string('status', 20)->default('active'); // active | cancelled (expired is worked out from valid_to)
            $table->foreignId('award_id')->nullable()->constrained()->nullOnDelete(); // made from this PO
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('supplier_accepted_at')->nullable();
            $table->foreignId('supplier_accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 1000)->nullable();
            $table->string('expiry_alerted', 10)->nullable(); // '30', '7': which reminders went out
            $table->timestamps();

            $table->unique(['organization_id', 'rc_number']);
            $table->index(['organization_id', 'status', 'valid_to']);
            $table->index(['supplier_org_id', 'status']);
        });

        // Each request remembers which RFQ lines are its own, so a combined RFQ reports per request.
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->json('rfq_item_ids')->nullable()->after('rfq_id');
        });

        // History from the POs already issued. Best-effort: a problem here never blocks the deploy;
        // new POs are recorded either way.
        try {
            $this->backfill();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('price_history_backfill_failed', ['error' => $e->getMessage()]);
        }
    }

    private function backfill(): void
    {
        $now = now();
        $tz = config('app.display_timezone', 'Asia/Kolkata');
        DB::table('awards')->whereNotNull('po_number')->where('status', 'po_sent')->orderBy('id')
            ->chunkById(200, function ($awards) use ($now, $tz) {
                $rows = [];
                foreach ($awards as $a) {
                    $lines = json_decode((string) $a->lines, true)['items'] ?? [];
                    foreach ($lines as $l) {
                        if (! isset($l['name'], $l['unit'], $l['unit_price'])) {
                            continue;
                        }
                        $key = \App\Services\Pricing\PriceHistory::key($l['name'], $l['unit'], $l['spec'] ?? null);
                        $rows[$a->id.'|'.$key] = [
                            'organization_id' => $a->organization_id, 'item_key' => $key, 'item_name' => mb_substr(\Illuminate\Support\Str::squish($l['name']), 0, 150),
                            'spec' => isset($l['spec']) && trim((string) $l['spec']) !== '' ? mb_substr(\Illuminate\Support\Str::squish((string) $l['spec']), 0, 1000) : null, 'unit' => $l['unit'],
                            'supplier_org_id' => $a->supplier_org_id, 'award_id' => $a->id,
                            'rate' => $l['unit_price'], 'qty' => $l['qty'] ?? null, 'gst_rate' => $l['gst_rate'] ?? null,
                            'priced_on' => \Illuminate\Support\Carbon::parse($a->po_sent_at ?? $a->created_at, 'UTC')->setTimezone($tz)->toDateString(), 'created_at' => $now, 'updated_at' => $now,
                        ];
                    }
                }
                if ($rows) {
                    DB::table('price_points')->insertOrIgnore(array_values($rows));
                }
            });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', fn (Blueprint $table) => $table->dropColumn('rfq_item_ids'));
        Schema::dropIfExists('rate_contracts');
        Schema::dropIfExists('price_points');
    }
};
