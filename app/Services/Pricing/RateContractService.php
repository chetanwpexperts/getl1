<?php

namespace App\Services\Pricing;

use App\Enums\AwardStatus;
use App\Enums\OrgRole;
use App\Models\Award;
use App\Models\Organization;
use App\Models\RateContract;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Notifier;
use App\Services\RfqService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Rate contracts: an agreed rate per item with one supplier for a period, usually made from a PO
 * that came out of an RFQ or auction. The supplier confirms it; the buyer team sees it whenever
 * the item comes up again, and is reminded 30 and 7 days before it ends.
 */
class RateContractService
{
    public const MAX_MONTHS = 36;

    public function __construct(private AuditLogger $audit) {}

    public static function rules(): array
    {
        $today = RateContract::today();

        return [
            'title' => ['required', 'string', 'max:150'],
            'supplier_org_id' => ['required', 'integer'],
            'valid_from' => ['required', 'date', 'after_or_equal:'.Carbon::parse($today)->subDays(30)->toDateString()],
            'valid_to' => ['required', 'date', 'after_or_equal:valid_from', 'after_or_equal:'.$today],
            'terms' => ['nullable', 'string', 'max:3000'],
            'award_id' => ['nullable', 'integer'],
            'items' => ['required', 'array', 'min:1', 'max:'.RateContract::MAX_ITEMS],
            'items.*.name' => ['required', 'string', 'max:150'],
            'items.*.spec' => ['nullable', 'string', 'max:1000'],
            'items.*.unit' => ['required', 'in:'.implode(',', RfqService::UNITS)],
            'items.*.rate' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'items.*.gst_rate' => ['nullable', 'in:'.implode(',', \App\Services\QuoteService::GST_RATES)],
        ];
    }

    public static function messages(): array
    {
        return [
            'items.required' => 'Add at least one item.',
            'items.*.rate.gt' => 'Every item needs a rate above 0.',
            'valid_to.after_or_equal' => 'The end date must be today or later, and not before the start date.',
            'valid_from.after_or_equal' => 'The start date can be at most 30 days back.',
        ];
    }

    /** Suppliers this company can make a contract with: those it has issued a PO to. */
    public static function suppliers(int $orgId): Collection
    {
        $ids = Award::withoutGlobalScopes()->where('organization_id', $orgId)->where('status', AwardStatus::PoSent->value)
            ->distinct()->pluck('supplier_org_id');

        return Organization::whereKey($ids)->orderBy('name')->get(['id', 'name', 'gstin']);
    }

    /** Form values taken from a PO: its supplier, items and rates. */
    public static function fromAward(Award $award): array
    {
        $rfq = $award->rfq;

        return [
            'title' => $rfq?->title ?? 'Rate contract',
            'supplier_org_id' => $award->supplier_org_id,
            'award_id' => $award->id,
            'items' => collect($award->lines['items'] ?? [])->map(fn ($l) => [
                'name' => $l['name'], 'spec' => $l['spec'] ?? null, 'unit' => $l['unit'], 'rate' => $l['unit_price'], 'gst_rate' => $l['gst_rate'] ?? null,
            ])->values()->all(),
        ];
    }

    public function create(Organization $org, User $by, array $data): RateContract
    {
        $supplierId = (int) $data['supplier_org_id'];
        if (! self::suppliers($org->id)->contains('id', $supplierId)) {
            throw ValidationException::withMessages(['supplier_org_id' => 'Choose a supplier you have ordered from on GetL1.']);
        }
        $awardId = isset($data['award_id']) ? (int) $data['award_id'] : null;
        if ($awardId && ! Award::withoutGlobalScopes()->whereKey($awardId)->where('organization_id', $org->id)->where('supplier_org_id', $supplierId)->exists()) {
            $awardId = null; // never link to someone else's PO
        }
        $from = Carbon::parse($data['valid_from']);
        $to = Carbon::parse($data['valid_to']);
        if ($from->copy()->addMonths(self::MAX_MONTHS)->lt($to)) {
            throw ValidationException::withMessages(['valid_to' => 'A rate contract can run for at most '.self::MAX_MONTHS.' months.']);
        }

        $items = [];
        $seen = [];
        foreach (array_values($data['items']) as $i) {
            $key = PriceHistory::key($i['name'], $i['unit'], $i['spec'] ?? null);
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(['items' => 'Each item can appear only once: "'.Str::squish($i['name']).'" with the same specification is listed twice.']);
            }
            $seen[$key] = true;
            $items[] = [
                'name' => Str::squish($i['name']),
                'spec' => isset($i['spec']) && trim((string) $i['spec']) !== '' ? Str::squish($i['spec']) : null,
                'unit' => $i['unit'],
                'rate' => round((float) $i['rate'], 4), // auction rates on cheap items can have 4 decimals
                'gst_rate' => isset($i['gst_rate']) && $i['gst_rate'] !== '' ? (float) $i['gst_rate'] : null,
            ];
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                $rc = DB::transaction(function () use ($org, $by, $data, $supplierId, $awardId, $items, $from, $to) {
                    $rc = RateContract::create([
                        'organization_id' => $org->id,
                        'rc_number' => $this->nextNumber($org->id),
                        'supplier_org_id' => $supplierId,
                        'title' => Str::squish($data['title']),
                        'valid_from' => $from->toDateString(),
                        'valid_to' => $to->toDateString(),
                        'items' => $items,
                        'terms' => isset($data['terms']) && trim((string) $data['terms']) !== '' ? trim($data['terms']) : null,
                        'status' => RateContract::ACTIVE,
                        'award_id' => $awardId,
                        'created_by' => $by->id,
                        // Made with 30 or 7 days or less to run: that reminder would only repeat what they just did.
                        'expiry_alerted' => match (true) {
                            Carbon::parse(RateContract::today())->diffInDays($to, false) <= 7 => '7',
                            Carbon::parse(RateContract::today())->diffInDays($to, false) <= 30 => '30',
                            default => null,
                        },
                    ]);
                    $this->audit->log('rate_contract_created', $rc, after: [
                        'rc_number' => $rc->rc_number, 'supplier_org_id' => $supplierId, 'items' => count($items),
                        'valid_from' => $rc->valid_from->toDateString(), 'valid_to' => $rc->valid_to->toDateString(), 'from_po' => $awardId,
                    ], user: $by, organizationId: $org->id);

                    return $rc;
                });
                break;
            } catch (QueryException $e) {
                if ($attempt >= 3 || ! str_contains(strtolower($e->getMessage()), 'unique')) {
                    throw $e;
                }
            }
        }

        Notifier::toOrg($supplierId, 'orders', "Rate contract from {$org->name}: {$rc->rc_number}",
            count($items).' '.Str::plural('item', count($items)).' at agreed rates, '.$rc->valid_from->format('d M Y').' to '.$rc->valid_to->format('d M Y').'. Please review and confirm it.',
            route('supplier.contracts.show', $rc->id));

        return $rc;
    }

    public function accept(RateContract $rc, User $by): bool
    {
        return DB::transaction(function () use ($rc, $by) {
            $c = RateContract::withoutGlobalScopes()->whereKey($rc->id)->lockForUpdate()->firstOrFail();
            if ($c->supplier_accepted_at || $c->state() === 'cancelled' || $c->state() === 'expired') {
                return false;
            }
            $c->update(['supplier_accepted_at' => now(), 'supplier_accepted_by' => $by->id]);
            $this->audit->log('rate_contract_accepted', $c, after: ['rc_number' => $c->rc_number], user: $by, organizationId: $c->organization_id);
            $supplier = Organization::whereKey($c->supplier_org_id)->value('name');
            Notifier::toUsers(self::buyerTeam($c->organization_id), $c->organization_id, 'orders', "Rate contract confirmed: {$c->rc_number}",
                "{$supplier} confirmed the agreed rates for \"{$c->title}\".", route('buyer.contracts.show', $c->id));

            return true;
        });
    }

    public function cancel(RateContract $rc, User $by, string $reason): void
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['cancel_reason' => 'Say why it’s being ended; the supplier is told.']);
        }
        DB::transaction(function () use ($rc, $by, $reason) {
            $c = RateContract::withoutGlobalScopes()->whereKey($rc->id)->lockForUpdate()->firstOrFail();
            if (in_array($c->state(), ['cancelled', 'expired'], true)) {
                throw ValidationException::withMessages(['cancel_reason' => 'This rate contract has already ended.']);
            }
            $c->update(['status' => RateContract::CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => trim(mb_substr($reason, 0, 1000))]);
            $this->audit->log('rate_contract_cancelled', $c, after: ['rc_number' => $c->rc_number, 'reason' => $c->cancel_reason], user: $by, organizationId: $c->organization_id);
            $buyer = Organization::whereKey($c->organization_id)->value('name');
            Notifier::toOrg($c->supplier_org_id, 'orders', "Rate contract ended: {$c->rc_number}",
                "{$buyer} ended the rate contract \"{$c->title}\". Reason: {$c->cancel_reason}", route('supplier.contracts.show', $c->id));
        });
    }

    /** Daily: tell the buyer team 30 and 7 days before a contract ends. Each reminder goes once. */
    public function remindExpiring(): int
    {
        $today = Carbon::parse(RateContract::today());
        $sent = 0;
        $due = RateContract::withoutGlobalScopes()->with('supplier:id,name')->where('status', RateContract::ACTIVE)
            ->where('valid_to', '>=', $today->toDateString())->where('valid_to', '<=', $today->copy()->addDays(30)->toDateString())
            ->where(fn ($q) => $q->whereNull('expiry_alerted')->orWhere('expiry_alerted', '30'))->get();

        foreach ($due as $c) {
            $left = $c->daysLeft();
            [$mark, $from] = $left <= 7 ? ['7', [null, '30']] : ['30', [null]];
            if ($mark === $c->expiry_alerted) {
                continue;
            }
            // Claim the reminder on the row, so two overlapping schedulers never both send it.
            $claimed = RateContract::withoutGlobalScopes()->whereKey($c->id)
                ->where(fn ($q) => in_array('30', $from, true) ? $q->whereNull('expiry_alerted')->orWhere('expiry_alerted', '30') : $q->whereNull('expiry_alerted'))
                ->update(['expiry_alerted' => $mark]);
            if (! $claimed) {
                continue;
            }
            Notifier::toUsers(self::buyerTeam($c->organization_id), $c->organization_id, 'orders', "Rate contract ending: {$c->rc_number}",
                "\"{$c->title}\" with {$c->supplier?->name} ends ".($left === 0 ? 'today' : "in {$left} ".Str::plural('day', $left)).' ('.$c->valid_to->format('d M Y').'). Start an RFQ or agree new rates in time.',
                route('buyer.contracts.show', $c->id));
            $sent++;
        }

        return $sent;
    }

    public static function buyerTeam(int $orgId): Collection
    {
        return Organization::find($orgId)?->users()->wherePivotIn('role', [OrgRole::BuyerAdmin->value, OrgRole::BuyerUser->value])->get() ?? collect();
    }

    private function nextNumber(int $orgId): string
    {
        $prefix = 'RC-'.now()->setTimezone(config('app.display_timezone'))->year.'-';
        $last = RateContract::withoutGlobalScopes()->where('organization_id', $orgId)->where('rc_number', 'like', $prefix.'%')->orderByDesc('id')->value('rc_number');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 4, '0', STR_PAD_LEFT);
    }
}
