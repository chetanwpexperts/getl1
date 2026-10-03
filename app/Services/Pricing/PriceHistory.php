<?php

namespace App\Services\Pricing;

use App\Models\Award;
use App\Models\PricePoint;
use App\Models\RateContract;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What a buyer company has paid per item, from every PO issued. The same item is matched across
 * RFQs by its name, specification and unit (case and spacing ignored), so "MS pipe 25 mm" and
 * "MS pipe 40 mm" never share a price.
 */
class PriceHistory
{
    public static function key(string $name, string $unit, ?string $spec = null): string
    {
        $norm = fn (?string $v) => mb_strtolower(Str::squish((string) $v));

        return mb_substr($norm($name).'|'.$norm($spec).'|'.mb_strtolower(trim($unit)), 0, 191);
    }

    /** Called when a PO is issued. Safe to call again for the same PO. */
    public static function record(Award $award): void
    {
        $now = now();
        $on = ($award->po_sent_at ?? $now)->copy()->setTimezone(config('app.display_timezone'))->toDateString();
        $rows = [];
        foreach ($award->lines['items'] ?? [] as $l) {
            if (! isset($l['name'], $l['unit'], $l['unit_price'])) {
                continue;
            }
            $key = self::key($l['name'], $l['unit'], $l['spec'] ?? null);
            $rows[$key] = [
                'organization_id' => $award->organization_id, 'item_key' => $key, 'item_name' => mb_substr(Str::squish($l['name']), 0, 150),
                'spec' => isset($l['spec']) && trim((string) $l['spec']) !== '' ? mb_substr(Str::squish((string) $l['spec']), 0, 1000) : null, 'unit' => $l['unit'],
                'supplier_org_id' => $award->supplier_org_id, 'award_id' => $award->id,
                'rate' => $l['unit_price'], 'qty' => $l['qty'] ?? null, 'gst_rate' => $l['gst_rate'] ?? null,
                'priced_on' => $on, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        if ($rows) {
            DB::table('price_points')->insertOrIgnore(array_values($rows));
        }
    }

    /** The latest rate paid for this item, or null. */
    public static function last(int $orgId, string $name, string $unit, ?string $spec = null): ?PricePoint
    {
        return self::lastMany($orgId, [self::key($name, $unit, $spec)])->first();
    }

    /**
     * Latest purchase for each key (newest date, then newest row), in two queries.
     *
     * @param  list<string>  $keys
     * @return Collection<string, PricePoint> keyed by item_key
     */
    public static function lastMany(int $orgId, array $keys): Collection
    {
        $keys = array_values(array_unique($keys));
        if (! $keys) {
            return collect();
        }
        $ids = DB::table('price_points as p')->joinSub(
            DB::table('price_points')->where('organization_id', $orgId)->whereIn('item_key', $keys)->selectRaw('item_key, MAX(priced_on) as d')->groupBy('item_key'),
            'm', fn ($j) => $j->on('m.item_key', '=', 'p.item_key')->on('m.d', '=', 'p.priced_on'))
            ->where('p.organization_id', $orgId)->selectRaw('p.item_key, MAX(p.id) as id')->groupBy('p.item_key')->pluck('id');

        return PricePoint::withoutGlobalScopes()->with('supplier:id,name')->whereKey($ids)->get()->keyBy('item_key');
    }

    /**
     * One row per item bought, newest purchase first: last rate, change from the previous purchase,
     * lowest, number of orders. Grouped in the database, one page at a time.
     */
    public static function items(int $orgId, ?string $search, int $perPage, int $page, string $path, array $query): LengthAwarePaginator
    {
        $grouped = DB::table('price_points')->where('organization_id', $orgId)
            ->when($search, fn ($w) => $w->where('item_name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->groupBy('item_key')
            ->selectRaw('item_key, MIN(rate) as lowest, MAX(rate) as highest, COUNT(*) as n, MAX(priced_on) as last_on, MAX(id) as last_id');
        $total = DB::query()->fromSub($grouped, 'g')->count();
        $rows = DB::query()->fromSub($grouped, 'g')->orderByDesc('last_on')->orderByDesc('last_id')->forPage($page, $perPage)->get();

        $keys = $rows->pluck('item_key')->all();
        $last = self::lastMany($orgId, $keys);
        $first = PricePoint::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('item_key', $keys)
            ->orderBy('id')->get(['item_key', 'item_name', 'spec'])->unique('item_key')->keyBy('item_key');
        // The purchase before the last one, per item on this page (at most one small query each).
        $previous = [];
        foreach ($last as $key => $p) {
            $day = $p->priced_on->toDateString();
            $previous[$key] = PricePoint::withoutGlobalScopes()->where('organization_id', $orgId)->where('item_key', $key)
                ->where(fn ($q) => $q->where('priced_on', '<', $day)->orWhere(fn ($w) => $w->where('priced_on', $day)->where('id', '<', $p->id)))
                ->orderByDesc('priced_on')->orderByDesc('id')->value('rate');
        }

        $items = $rows->map(function ($r) use ($last, $first, $previous) {
            $l = $last[$r->item_key] ?? null;
            $prev = $previous[$r->item_key] ?? null;

            return [
                'key' => $r->item_key,
                // As first bought: stable even if later RFQs type it differently.
                'name' => $first[$r->item_key]->item_name ?? $l?->item_name,
                'spec' => $first[$r->item_key]->spec ?? null,
                'unit' => $l?->unit,
                'last' => $l,
                'change_pct' => $l && $prev !== null && (float) $prev > 0 ? round(((float) $l->rate - (float) $prev) / (float) $prev * 100, 1) : null,
                'lowest' => (float) $r->lowest,
                'highest' => (float) $r->highest,
                'count' => (int) $r->n,
            ];
        })->filter(fn ($i) => $i['last'] !== null)->values();

        return new LengthAwarePaginator($items, $total, $perPage, $page, ['path' => $path, 'query' => $query]);
    }

    /** Every purchase of one item, newest first. */
    public static function forItem(int $orgId, string $key): Collection
    {
        return PricePoint::withoutGlobalScopes()->with(['supplier:id,name', 'award:id,po_number'])->where('organization_id', $orgId)
            ->where('item_key', $key)->orderByDesc('priced_on')->orderByDesc('id')->limit(500)->get();
    }

    /** Contracts in force today for a company, looked up once per request. */
    private static function inForce(int $orgId): Collection
    {
        // Web requests only: a queue worker or command keeps one request object for its whole life.
        $attrs = app()->runningInConsole() && ! app()->runningUnitTests() ? new \Symfony\Component\HttpFoundation\ParameterBag : request()->attributes;
        $memo = 'getl1.contracts_in_force.'.$orgId;
        if (! $attrs->has($memo)) {
            $attrs->set($memo, RateContract::withoutGlobalScopes()->with('supplier:id,name')->where('organization_id', $orgId)->inForce()->get());
        }

        return $attrs->get($memo);
    }

    /**
     * Rate contracts in force today that cover this item: [{contract, rate, gst_rate}], cheapest first.
     *
     * @return Collection<int, array{contract: RateContract, rate: float, gst_rate: ?float}>
     */
    public static function contractsFor(int $orgId, string $name, string $unit, ?string $spec = null): Collection
    {
        $key = self::key($name, $unit, $spec);

        return self::inForce($orgId)->flatMap(function (RateContract $c) use ($key) {
            return collect($c->items)->filter(fn ($i) => self::key($i['name'], $i['unit'], $i['spec'] ?? null) === $key)
                ->map(fn ($i) => ['contract' => $c, 'rate' => (float) $i['rate'], 'gst_rate' => isset($i['gst_rate']) ? (float) $i['gst_rate'] : null]);
        })->sortBy('rate')->values();
    }
}
