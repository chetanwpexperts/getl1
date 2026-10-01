<?php

namespace App\Services\Admin;

use App\Enums\AuctionStatus;
use App\Enums\OrganizationType;
use App\Enums\SubscriptionStatus;
use App\Models\AiJob;
use App\Models\Auction;
use App\Models\AuditLog;
use App\Models\Award;
use App\Models\Bid;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Rfq;
use App\Models\Subscription;
use App\Services\SupplierTrust;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Numbers for the admin Overview over a chosen period, with the previous period for comparison.
 * Test and demo companies are left out unless asked for. Aggregation is done in PHP so the
 * same code runs on MySQL and the in-memory test database.
 */
class Dashboard
{
    /** Events worth showing on the Overview, in plain words. */
    public const ACTIVITY = [
        'registered' => 'joined GetL1', 'rfq_published' => 'published an RFQ', 'auction_scheduled' => 'scheduled a live auction',
        'auction_closed' => 'live auction closed', 'awarded' => 'awarded an order', 'po_issued' => 'purchase order sent',
        'po_accepted' => 'purchase order accepted', 'payment_received' => 'payment received', 'subscription_activated' => 'started a paid plan',
        'kyc_document_approved' => 'KYC document approved', 'admin_company_suspended' => 'company suspended',
        'auction_paused_by_getl1' => 'auction paused by GetL1', 'auction_cancelled_by_getl1' => 'auction cancelled by GetL1',
    ];

    public const PERIODS = ['7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days', 'fy' => 'This financial year'];

    private Carbon $from;
    private Carbon $to;
    private Carbon $prevFrom;
    private string $bucket; // day | week | month
    private ?array $orgIds = null; // null = everyone (including test data)

    public function __construct(private string $period = '30d', bool $includeTest = false)
    {
        $tz = config('app.display_timezone');
        $this->period = array_key_exists($period, self::PERIODS) ? $period : '30d';
        $this->to = now();
        $this->from = match ($this->period) {
            '7d' => now()->setTimezone($tz)->subDays(6)->startOfDay()->utc(),
            '90d' => now()->setTimezone($tz)->subDays(89)->startOfDay()->utc(),
            'fy' => self::fyStart(),
            default => now()->setTimezone($tz)->subDays(29)->startOfDay()->utc(),
        };
        $this->prevFrom = $this->from->copy()->subSeconds($this->to->diffInSeconds($this->from, true));
        $days = $this->from->diffInDays($this->to, true);
        $this->bucket = $days > 120 ? 'month' : ($days > 45 ? 'week' : 'day');
        if (! $includeTest) {
            $this->orgIds = Organization::withTrashed()->real()->pluck('id')->all();
        }
    }

    public static function fyStart(): Carbon
    {
        $n = now()->setTimezone(config('app.display_timezone'));

        return Carbon::create($n->month >= 4 ? $n->year : $n->year - 1, 4, 1, 0, 0, 0, config('app.display_timezone'))->utc();
    }

    public function period(): string
    {
        return $this->period;
    }

    public function testCompanies(): int
    {
        return Organization::count() - Organization::real()->count();
    }

    private function orgs($q, string $col = 'organization_id')
    {
        return $this->orgIds === null ? $q : $q->whereIn($col, $this->orgIds);
    }

    // ---------------------------------------------------------------- headline numbers

    public function kpis(): array
    {
        $calc = function (Carbon $from, Carbon $to) {
            $closed = $this->orgs(Auction::withoutGlobalScopes())->where('status', AuctionStatus::Closed->value)->whereBetween('closed_at', [$from, $to])
                ->get(['start_price', 'current_l1']);

            return [
                'revenue' => (float) $this->orgs(Payment::query())->where('status', 'paid')->whereBetween('paid_at', [$from, $to])->sum('total'),
                'savings' => (float) $closed->sum(fn ($a) => max(0, (float) $a->start_price - (float) $a->current_l1)),
                'po_value' => (float) $this->orgs(Award::withoutGlobalScopes())->whereNotNull('po_sent_at')->whereBetween('po_sent_at', [$from, $to])->sum('grand_total'),
                'active_buyers' => $this->orgs(Rfq::withoutGlobalScopes())->whereBetween('created_at', [$from, $to])->distinct()->count('organization_id'),
                'rfqs' => $this->orgs(Rfq::withoutGlobalScopes())->whereBetween('created_at', [$from, $to])->count(),
                'auctions' => $closed->count(),
                'bids' => $this->orgs(Bid::query(), 'supplier_org_id')->where('kind', Bid::KIND_LIVE)->whereBetween('created_at', [$from, $to])->count(),
                'new_buyers' => $this->orgs(Organization::query(), 'id')->where('type', OrganizationType::Buyer->value)->whereBetween('created_at', [$from, $to])->count(),
                'new_suppliers' => $this->orgs(Organization::query(), 'id')->where('type', OrganizationType::Supplier->value)->whereBetween('created_at', [$from, $to])->count(),
                'ai_reads' => $this->orgs(AiJob::withoutGlobalScopes())->where('status', 'done')->whereBetween('created_at', [$from, $to])->count(),
                'ai_cost' => (float) $this->orgs(AiJob::withoutGlobalScopes())->whereBetween('created_at', [$from, $to])->sum('cost_inr'),
            ];
        };
        $now = $calc($this->from, $this->to);
        $prev = $calc($this->prevFrom, $this->from);

        $live = $this->orgs(Subscription::with('plan'))->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value])->get();
        $now['mrr'] = round($live->sum(fn ($s) => $s->billing_cycle === 'yearly' ? (float) $s->plan?->price_yearly / 12 : (float) $s->plan?->price_monthly));
        $now['paid'] = $live->count();
        $now['trials'] = $this->orgs(Subscription::query())->where('status', SubscriptionStatus::Trialing->value)->where('trial_ends_at', '>', now())->count();
        $now['buyers'] = $this->orgs(Organization::query(), 'id')->where('type', OrganizationType::Buyer->value)->count();
        $now['suppliers'] = $this->orgs(Organization::query(), 'id')->where('type', OrganizationType::Supplier->value)->count();

        $delta = fn ($k) => $prev[$k] > 0 ? round(($now[$k] - $prev[$k]) / $prev[$k] * 100) : ($now[$k] > 0 ? null : 0);

        return ['now' => $now, 'prev' => $prev, 'delta' => collect(array_keys($prev))->mapWithKeys(fn ($k) => [$k => $delta($k)])->all()];
    }

    // ---------------------------------------------------------------- series for charts

    /** @return array{labels: list<string>, keys: list<string>} */
    private function buckets(): array
    {
        $tz = config('app.display_timezone');
        $cur = $this->from->copy()->setTimezone($tz);
        $end = $this->to->copy()->setTimezone($tz);
        $labels = $keys = [];
        while ($cur->lte($end)) {
            $keys[] = $this->key($cur);
            $labels[] = match ($this->bucket) { 'month' => $cur->format('M'), 'week' => $cur->format('d M'), default => $cur->format('d M') };
            $cur = match ($this->bucket) { 'month' => $cur->addMonthNoOverflow()->startOfMonth(), 'week' => $cur->addWeek(), default => $cur->addDay() };
        }

        return ['labels' => $labels, 'keys' => array_values(array_unique($keys))];
    }

    private function key(Carbon $t): string
    {
        $l = $t->copy()->setTimezone(config('app.display_timezone'));

        return match ($this->bucket) {
            'month' => $l->format('Y-m'),
            'week' => $this->from->copy()->setTimezone(config('app.display_timezone'))->addWeeks(intdiv((int) $this->from->diffInDays($t, true), 7))->format('Y-m-d'),
            default => $l->format('Y-m-d'),
        };
    }

    /** Count (or sum of $value) per bucket, in bucket order. */
    private function series(Collection $rows, string $timeCol, ?\Closure $value = null): array
    {
        $b = $this->buckets();
        $sums = array_fill_keys($b['keys'], 0);
        foreach ($rows as $r) {
            if (! $r->{$timeCol}) {
                continue;
            }
            $k = $this->key(Carbon::parse($r->{$timeCol}));
            if (array_key_exists($k, $sums)) {
                $sums[$k] += $value ? $value($r) : 1;
            }
        }

        return array_values(array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $sums));
    }

    public function charts(): array
    {
        $b = $this->buckets();
        $range = [$this->from, $this->to];
        $orgs = $this->orgs(Organization::query(), 'id')->whereBetween('created_at', $range)->get(['type', 'created_at']);
        $closed = $this->orgs(Auction::withoutGlobalScopes())->where('status', AuctionStatus::Closed->value)->whereBetween('closed_at', $range)->get(['closed_at', 'start_price', 'current_l1']);

        return [
            'labels' => array_slice($b['labels'], 0, count($b['keys'])),
            'bucket' => $this->bucket,
            'rfqs' => $this->series($this->orgs(Rfq::withoutGlobalScopes())->whereBetween('created_at', $range)->get(['created_at']), 'created_at'),
            'auctions' => $this->series($closed, 'closed_at'),
            'bids' => $this->series($this->orgs(Bid::query(), 'supplier_org_id')->where('kind', Bid::KIND_LIVE)->whereBetween('created_at', $range)->get(['created_at']), 'created_at'),
            'savings' => $this->series($closed, 'closed_at', fn ($a) => max(0, (float) $a->start_price - (float) $a->current_l1)),
            'revenue' => $this->series($this->orgs(Payment::query())->where('status', 'paid')->whereBetween('paid_at', $range)->get(['paid_at', 'total']), 'paid_at', fn ($p) => (float) $p->total),
            'po_value' => $this->series($this->orgs(Award::withoutGlobalScopes())->whereNotNull('po_sent_at')->whereBetween('po_sent_at', $range)->get(['po_sent_at', 'grand_total']), 'po_sent_at', fn ($a) => (float) $a->grand_total),
            'new_buyers' => $this->series($orgs->where('type', OrganizationType::Buyer), 'created_at'),
            'new_suppliers' => $this->series($orgs->where('type', OrganizationType::Supplier), 'created_at'),
        ];
    }

    /** RFQs in the period by top-level category, top 5 + Other. */
    public function categories(): array
    {
        $cats = Category::all(['id', 'parent_id', 'name'])->keyBy('id');
        $top = fn ($id) => $id && isset($cats[$id]) ? ($cats[$id]->parent_id ? ($cats[$cats[$id]->parent_id]->name ?? $cats[$id]->name) : $cats[$id]->name) : 'Uncategorised';
        $counts = $this->orgs(Rfq::withoutGlobalScopes())->whereBetween('created_at', [$this->from, $this->to])->pluck('category_id')
            ->map($top)->countBy()->sortDesc();
        $head = $counts->take(5);
        if ($counts->count() > 5) {
            $head['Other'] = $counts->slice(5)->sum();
        }

        return $head->map(fn ($n, $name) => ['name' => $name, 'n' => $n])->values()->all();
    }

    // ---------------------------------------------------------------- lists

    public function topBuyers(): Collection
    {
        $awards = $this->orgs(Award::withoutGlobalScopes())->whereNotNull('po_sent_at')->whereBetween('po_sent_at', [$this->from, $this->to])
            ->get(['organization_id', 'grand_total'])->groupBy('organization_id')->map(fn ($g) => ['value' => (float) $g->sum('grand_total'), 'pos' => $g->count()]);
        $rfqs = $this->orgs(Rfq::withoutGlobalScopes())->whereBetween('created_at', [$this->from, $this->to])->get(['organization_id'])->countBy('organization_id');
        $ids = $awards->keys()->merge($rfqs->keys())->unique();
        $orgs = Organization::withTrashed()->whereIn('id', $ids)->get(['id', 'name', 'city'])->keyBy('id');

        return $ids->map(fn ($id) => ['org' => $orgs[$id] ?? null, 'value' => $awards[$id]['value'] ?? 0, 'pos' => $awards[$id]['pos'] ?? 0, 'rfqs' => $rfqs[$id] ?? 0])
            ->filter(fn ($r) => $r['org'])->sortByDesc(fn ($r) => [$r['value'], $r['rfqs']])->take(5)->values();
    }

    public function topSuppliers(): Collection
    {
        $wins = $this->orgs(Award::withoutGlobalScopes(), 'supplier_org_id')->whereNotNull('po_sent_at')->whereBetween('po_sent_at', [$this->from, $this->to])
            ->get(['supplier_org_id', 'grand_total'])->groupBy('supplier_org_id')->map(fn ($g) => ['value' => (float) $g->sum('grand_total'), 'wins' => $g->count()]);
        $bids = $this->orgs(Bid::query(), 'supplier_org_id')->where('kind', Bid::KIND_LIVE)->whereBetween('created_at', [$this->from, $this->to])
            ->get(['supplier_org_id'])->countBy('supplier_org_id');
        $ids = $wins->keys()->merge($bids->keys())->unique();
        $orgs = Organization::withTrashed()->whereIn('id', $ids)->get();
        $trust = app(SupplierTrust::class);

        return $orgs->map(fn ($o) => ['org' => $o, 'value' => $wins[$o->id]['value'] ?? 0, 'wins' => $wins[$o->id]['wins'] ?? 0,
            'bids' => $bids[$o->id] ?? 0, 'trust' => $trust->for($o)])
            ->sortByDesc(fn ($r) => [$r['value'], $r['bids']])->take(5)->values();
    }

    public function liveAndNext(): Collection
    {
        return $this->orgs(Auction::withoutGlobalScopes()->with('rfq:id,title'))
            ->where(fn ($q) => $q->where('status', AuctionStatus::Live->value)
                ->orWhere(fn ($q) => $q->where('status', AuctionStatus::Scheduled->value)->where('starts_at', '<=', now()->addDay())))
            ->orderByRaw("case when status = 'live' then 0 else 1 end")->orderBy('starts_at')->limit(5)->get();
    }

    public function activity(): Collection
    {
        $important = array_keys(self::ACTIVITY);

        return $this->orgs(AuditLog::with('user:id,name'))->whereIn('action', $important)->latest('id')->limit(12)->get();
    }
}
