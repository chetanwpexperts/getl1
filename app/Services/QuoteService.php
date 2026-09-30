<?php

namespace App\Services;

use App\Enums\InviteStatus;
use App\Models\Quote;
use App\Models\RfqInvite;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sealed quotes. The total is always computed here from item prices; nothing the browser
 * sends as a "total" is trusted. Quotes can be revised until the deadline, and each revision
 * is audit-logged against the buyer's organization.
 */
class QuoteService
{
    public const GST_RATES = ['0', '0.25', '3', '5', '12', '18', '28', '40'];

    public function __construct(private AuditLogger $audit, private InviteService $invites) {}

    public static function rules(): array
    {
        return [
            'items' => ['required', 'array'],
            'items.*.unit_price' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'items.*.gst_rate' => ['required', 'in:'.implode(',', self::GST_RATES)],
            'items.*.freight' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'valid_till' => ['required', 'date', 'after_or_equal:today', 'before:+1 year'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public static function messages(): array
    {
        return [
            'items.*.unit_price.required' => 'Enter a price for every item.',
            'items.*.unit_price.gt' => 'Prices must be more than 0.',
            'valid_till.after_or_equal' => 'Validity can’t be in the past.',
        ];
    }

    public function submit(RfqInvite $invite, User $user, array $data): Quote
    {
        return DB::transaction(function () use ($invite, $user, $data) {
            // Lock the invite so two tabs can't submit at once, and re-check the deadline inside the lock.
            $invite = RfqInvite::whereKey($invite->id)->lockForUpdate()->firstOrFail();
            $this->invites->assertOpen($invite);

            if ($invite->status !== InviteStatus::Accepted) {
                throw ValidationException::withMessages(['items' => 'Accept the RFQ terms before quoting.']);
            }

            $items = $invite->rfq->items()->get();
            $posted = $data['items'] ?? [];
            $missing = $items->reject(fn ($i) => isset($posted[$i->id]['unit_price']));
            $extra = array_diff(array_keys($posted), $items->pluck('id')->all());
            if ($missing->isNotEmpty() || $extra) {
                throw ValidationException::withMessages(['items' => 'Quote every item in the RFQ.']);
            }

            $total = 0.0;
            foreach ($items as $item) {
                $total += (float) $posted[$item->id]['unit_price'] * (float) $item->qty;
            }

            $quote = Quote::firstOrNew(['rfq_id' => $invite->rfq_id, 'supplier_org_id' => $invite->supplier_org_id]);
            $before = $quote->exists ? ['total' => (float) $quote->total] : null;

            $quote->fill([
                'submitted_by' => $user->id,
                'total' => Money::round($total),
                'valid_till' => $data['valid_till'],
                'notes' => $data['notes'] ?? null,
                'submitted_at' => now(),
            ])->save();

            $quote->items()->delete();
            foreach ($items as $item) {
                $quote->items()->create([
                    'rfq_item_id' => $item->id,
                    'unit_price' => $posted[$item->id]['unit_price'],
                    'gst_rate' => $posted[$item->id]['gst_rate'],
                    'freight' => $posted[$item->id]['freight'] ?? 0,
                ]);
            }

            $this->audit->log($before ? 'quote_revised' : 'quote_submitted', $quote, before: $before,
                after: ['total' => (float) $quote->total, 'items' => $items->count()],
                user: $user, organizationId: $invite->rfq->organization_id);

            return $quote;
        });
    }
}
