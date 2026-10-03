<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A supplier's GST invoice against a PO. Scoped to the buyer company by the global scope;
 * supplier queries use withoutGlobalScopes() and filter by supplier_org_id.
 */
class SupplierInvoice extends Model
{
    use BelongsToOrganization;

    public const SUBMITTED = 'submitted';
    public const APPROVED = 'approved';
    public const DISPUTED = 'disputed';
    public const PAID = 'paid';

    protected $fillable = [
        'award_id', 'organization_id', 'supplier_org_id', 'invoice_number', 'active_key', 'invoice_date', 'taxable_amount', 'gst_amount', 'total_amount',
        'supplier_gstin', 'file_path', 'original_name', 'status', 'is_msme', 'due_date', 'due_basis', 'review_note', 'reviewed_by', 'reviewed_at',
        'paid_on', 'paid_amount', 'payment_ref', 'paid_marked_by', 'last_reminded_on', 'submitted_by',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'paid_on' => 'date',
            'last_reminded_on' => 'date',
            'reviewed_at' => 'datetime',
            'is_msme' => 'boolean',
            'taxable_amount' => 'decimal:2',
            'gst_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
        ];
    }

    public function award(): BelongsTo
    {
        return $this->belongsTo(Award::class)->withoutGlobalScopes();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'supplier_org_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Key that keeps an invoice number unique per supplier per Indian financial year (Apr–Mar). */
    public static function activeKey(int $supplierOrgId, \Carbon\CarbonInterface $invoiceDate, string $number): string
    {
        $y = $invoiceDate->month >= 4 ? $invoiceDate->year : $invoiceDate->year - 1;

        return $supplierOrgId.':'.$y.'-'.substr((string) ($y + 1), 2).':'.$number;
    }

    /** Still to be paid (approved, or submitted and waiting for review). */
    public function isOutstanding(): bool
    {
        return in_array($this->status, [self::SUBMITTED, self::APPROVED], true);
    }

    /** Days until the due date (negative when overdue), in IST calendar days. */
    public function daysLeft(): ?int
    {
        if (! $this->due_date) {
            return null;
        }
        // Plain calendar dates on both sides (today in IST), so the day after the due date is overdue.
        $today = \Illuminate\Support\Carbon::parse(now()->setTimezone(config('app.display_timezone'))->toDateString());
        $due = \Illuminate\Support\Carbon::parse($this->due_date->toDateString());

        return (int) round($today->diffInDays($due, false));
    }

    public function isOverdue(): bool
    {
        return $this->isOutstanding() && ($this->daysLeft() ?? 0) < 0;
    }
}
