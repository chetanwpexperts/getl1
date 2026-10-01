@extends('layouts.admin')

@section('title', 'Overview')

@section('content')
    @php
        $n = $kpis['now'];
        $dl = $kpis['delta'];
        $inr = fn ($v) => \App\Support\Money::inr($v, 0);
        $short = function ($v) {
            $v = (float) $v;
            return $v >= 1e7 ? '₹'.rtrim(rtrim(number_format($v / 1e7, 2), '0'), '.').' Cr'
                : ($v >= 1e5 ? '₹'.rtrim(rtrim(number_format($v / 1e5, 2), '0'), '.').' L' : \App\Support\Money::inr($v, 0));
        };
        $chart = fn (array $cfg) => json_encode($cfg, JSON_UNESCAPED_UNICODE); // escaped once by {{ }}
        $q = fn (array $over) => route('admin.dashboard', array_merge(['period' => $d->period(), 'test' => $includeTest ? 1 : null], $over));
        $prevLabel = ['7d' => 'previous 7 days', '30d' => 'previous 30 days', '90d' => 'previous 90 days', 'fy' => 'same span before'][$d->period()];
        $initials = fn ($name) => collect(preg_split('/\s+/', trim((string) $name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
        $tone = fn ($id) => ['bg-sky-100 text-sky-800', 'bg-orange-100 text-orange-800', 'bg-emerald-100 text-emerald-800', 'bg-violet-100 text-violet-800', 'bg-rose-100 text-rose-800'][$id % 5];
        $catMax = max(1, collect($categories)->max('n'));
    @endphp

    {{-- Header: period + health --}}
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Overview</h1>
            <p class="mt-0.5 text-sm text-slate-500">{{ now()->ist()->format('l, d M Y · h:i A') }} IST</p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <nav class="inline-flex rounded-lg border border-slate-200 bg-white p-0.5 text-sm shadow-sm" aria-label="Period">
                @foreach (\App\Services\Admin\Dashboard::PERIODS as $key => $label)
                    <a href="{{ $q(['period' => $key]) }}" class="rounded-md px-3 py-1.5 font-medium {{ $d->period() === $key ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-50' }}"
                       @if ($d->period() === $key) aria-current="page" @endif>{{ str_replace(['Last ', 'This financial year'], ['', 'This FY'], $label) }}</a>
                @endforeach
            </nav>
            <a href="{{ route('admin.health') }}" class="inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-sm font-semibold ring-1 {{ ['ok' => 'bg-emerald-50 text-emerald-800 ring-emerald-200', 'warn' => 'bg-amber-50 text-amber-900 ring-amber-300', 'fail' => 'bg-red-50 text-red-700 ring-red-200'][$health] }}">
                <span class="size-2 rounded-full {{ ['ok' => 'bg-emerald-500', 'warn' => 'bg-amber-500', 'fail' => 'bg-red-600'][$health] }}"></span>
                {{ $health === 'ok' ? 'All systems healthy' : implode(', ', $healthIssues) }}
            </a>
        </div>
    </div>
    <p class="mt-2 text-xs text-slate-500">
        @if ($includeTest)
            Including test and demo companies. <a href="{{ $q(['test' => null]) }}" class="underline">Hide them</a>
        @elseif ($d->testCompanies() > 0)
            {{ $d->testCompanies() === 1 ? '1 test or demo company is' : $d->testCompanies().' test and demo companies are' }} hidden from these numbers. <a href="{{ $q(['test' => 1]) }}" class="underline">Include them</a>
        @endif
    </p>

    {{-- Needs attention --}}
    @if (array_sum($attention) > 0)
        <div class="mt-4 flex flex-wrap items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <span class="font-semibold">Needs attention:</span>
            @if ($attention['paused'])<a href="{{ route('admin.auctions') }}" class="rounded-full bg-white px-3 py-1 font-medium ring-1 ring-amber-300 hover:bg-amber-100">{{ $attention['paused'] }} paused {{ \Illuminate\Support\Str::plural('auction', $attention['paused']) }}</a>@endif
            @if ($attention['kyc'])<a href="{{ route('admin.kyc.index') }}" class="rounded-full bg-white px-3 py-1 font-medium ring-1 ring-amber-300 hover:bg-amber-100">{{ $attention['kyc'] }} KYC {{ \Illuminate\Support\Str::plural('document', $attention['kyc']) }} to review</a>@endif
            @if ($attention['leads'])<a href="{{ route('admin.leads', ['status' => 'new']) }}" class="rounded-full bg-white px-3 py-1 font-medium ring-1 ring-amber-300 hover:bg-amber-100">{{ $attention['leads'] }} new {{ \Illuminate\Support\Str::plural('lead', $attention['leads']) }}</a>@endif
            @if ($attention['past_due'])<a href="{{ route('admin.payments') }}" class="rounded-full bg-white px-3 py-1 font-medium ring-1 ring-amber-300 hover:bg-amber-100">{{ $attention['past_due'] }} failed {{ \Illuminate\Support\Str::plural('renewal', $attention['past_due']) }}</a>@endif
        </div>
    @endif

    {{-- Headline KPIs with trend --}}
    <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['Revenue', $short($n['revenue']), 'revenue', $charts['revenue'], true, 'Plans, credits and AI packs', 'M3 3v18h18M7 14l4-4 3 3 6-6'],
            ['Savings delivered', $short($n['savings']), 'savings', $charts['savings'], true, 'Opening price minus final L1, closed auctions', 'M12 3v18M17 7.5c0-1.9-2.2-3.5-5-3.5s-5 1.6-5 3.5S9.2 11 12 11s5 1.6 5 3.5-2.2 3.5-5 3.5-5-1.6-5-3.5'],
            ['Purchase orders', $short($n['po_value']), 'po_value', $charts['po_value'], true, 'Value of POs sent on GetL1', 'M9 5h6M9 9h6M9 13h4M6 3h12v18l-3-2-3 2-3-2-3 2z'],
            ['MRR', $short($n['mrr']), null, null, true, $n['paid'].' paid · '.$n['trials'].' on trial', 'M4 12a8 8 0 1 0 16 0 8 8 0 0 0-16 0zm8-4v4l3 2'],
        ] as [$label, $value, $deltaKey, $spark, $money, $sub, $icon])
            <section class="relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex size-10 items-center justify-center rounded-xl bg-slate-900 text-white">
                        <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icon }}"/></svg>
                    </div>
                    @if ($deltaKey !== null)
                        @php $dv = $dl[$deltaKey]; @endphp
                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $dv === null ? 'bg-emerald-50 text-emerald-700' : ($dv > 0 ? 'bg-emerald-50 text-emerald-700' : ($dv < 0 ? 'bg-red-50 text-red-700' : 'bg-slate-100 text-slate-500')) }}"
                              title="Compared with the {{ $prevLabel }}">
                            {{ $dv === null ? 'New' : ($dv > 0 ? '▲ '.$dv.'%' : ($dv < 0 ? '▼ '.abs($dv).'%' : '0%')) }}
                        </span>
                    @endif
                </div>
                <p class="mt-4 text-sm font-medium text-slate-500">{{ $label }}</p>
                <p class="mt-1 text-3xl font-semibold tracking-tight tabular-nums">{{ $value }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ $sub }}</p>
                @if ($spark)
                    <div class="mt-3 h-10"><canvas data-chart="{{ $chart(['type' => 'spark', 'labels' => $charts['labels'], 'series' => [['name' => $label, 'data' => $spark]], 'money' => $money]) }}" role="img" aria-label="{{ $label }} trend"></canvas></div>
                @endif
            </section>
        @endforeach
    </div>

    {{-- Secondary numbers --}}
    <div class="mt-4 grid grid-cols-2 gap-px overflow-hidden rounded-2xl border border-slate-200 bg-slate-200 sm:grid-cols-3 xl:grid-cols-6">
        @foreach ([
            ['Buyers', number_format($n['buyers']), '+'.$n['new_buyers'].' in period'],
            ['Suppliers', number_format($n['suppliers']), '+'.$n['new_suppliers'].' in period'],
            ['Active buyers', number_format($n['active_buyers']), 'posted an RFQ'],
            ['RFQs', number_format($n['rfqs']), ($dl['rfqs'] === null ? 'new' : ($dl['rfqs'] >= 0 ? '+' : '').$dl['rfqs'].'% vs before')],
            ['Auctions run', number_format($n['auctions']), number_format($n['bids']).' live bids'],
            ['AI reads', number_format($n['ai_reads']), 'cost '.\App\Support\Money::inr($n['ai_cost'])],
        ] as [$k, $v, $s])
            <div class="bg-white px-4 py-3">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $k }}</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">{{ $v }}</p>
                <p class="text-xs text-slate-500">{{ $s }}</p>
            </div>
        @endforeach
    </div>

    {{-- Charts row 1 --}}
    <div class="mt-4 grid gap-4 xl:grid-cols-3">
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm xl:col-span-2">
            <div class="flex items-baseline justify-between gap-3">
                <h2 class="font-semibold">Platform activity</h2>
                <span class="text-xs text-slate-500">per {{ $charts['bucket'] }}</span>
            </div>
            <div class="mt-3 h-64"><canvas data-chart="{{ $chart(['type' => 'line', 'labels' => $charts['labels'], 'series' => [['name' => 'RFQs posted', 'data' => $charts['rfqs']], ['name' => 'Auctions run', 'data' => $charts['auctions']]]]) }}" role="img" aria-label="RFQs posted and auctions run per {{ $charts['bucket'] }}"></canvas></div>
        </section>
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="font-semibold">What buyers are buying</h2>
            <p class="text-xs text-slate-500">RFQs by category</p>
            <ul class="mt-4 space-y-3">
                @forelse ($categories as $c)
                    <li>
                        <div class="flex justify-between gap-2 text-sm"><span class="truncate">{{ $c['name'] }}</span><span class="tabular-nums text-slate-600">{{ $c['n'] }}</span></div>
                        <div class="mt-1 h-2 rounded-full bg-slate-100"><div class="h-2 rounded-full bg-[#2a78d6]" style="width: {{ max(3, (int) round($c['n'] / $catMax * 100)) }}%"></div></div>
                    </li>
                @empty
                    <li class="py-10 text-center text-sm text-slate-500">No RFQs in this period.</li>
                @endforelse
            </ul>
        </section>
    </div>

    {{-- Charts row 2 --}}
    <div class="mt-4 grid gap-4 lg:grid-cols-3">
        @foreach ([
            ['Live bids', 'Bids placed in auctions', ['type' => 'bar', 'series' => [['name' => 'Live bids', 'data' => $charts['bids']]]]],
            ['Savings delivered', 'Opening price minus final L1', ['type' => 'bar', 'money' => true, 'series' => [['name' => 'Saved', 'data' => $charts['savings']]]]],
            ['New companies', 'Sign-ups by type', ['type' => 'bar', 'stacked' => true, 'series' => [['name' => 'Buyers', 'data' => $charts['new_buyers']], ['name' => 'Suppliers', 'data' => $charts['new_suppliers']]]]],
        ] as [$title, $sub, $cfg])
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="font-semibold">{{ $title }}</h2>
                <p class="text-xs text-slate-500">{{ $sub }}</p>
                <div class="mt-3 h-48"><canvas data-chart="{{ $chart($cfg + ['labels' => $charts['labels']]) }}" role="img" aria-label="{{ $title }} per {{ $charts['bucket'] }}"></canvas></div>
            </section>
        @endforeach
    </div>

    {{-- People and auctions --}}
    <div class="mt-4 grid gap-4 xl:grid-cols-3">
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="font-semibold">Top buyers</h2>
            <p class="text-xs text-slate-500">By purchase order value in the period</p>
            <ul class="mt-3 divide-y divide-slate-100">
                @forelse ($topBuyers as $r)
                    <li class="flex items-center gap-3 py-2.5">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-full text-xs font-bold {{ $tone($r['org']->id) }}">{{ $initials($r['org']->name) }}</span>
                        <a href="{{ route('admin.companies.show', $r['org']->id) }}" class="min-w-0 flex-1"><span class="block truncate text-sm font-medium hover:underline">{{ $r['org']->name }}</span><span class="text-xs text-slate-500">{{ $r['org']->city }} · {{ $r['rfqs'] }} RFQs · {{ $r['pos'] }} POs</span></a>
                        <span class="text-sm font-semibold tabular-nums">{{ $short($r['value']) }}</span>
                    </li>
                @empty
                    <li class="py-8 text-center text-sm text-slate-500">No buyer activity in this period.</li>
                @endforelse
            </ul>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="font-semibold">Top suppliers</h2>
            <p class="text-xs text-slate-500">By orders won, with trust score</p>
            <ul class="mt-3 divide-y divide-slate-100">
                @forelse ($topSuppliers as $r)
                    <li class="flex items-center gap-3 py-2.5">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-full text-xs font-bold {{ $tone($r['org']->id) }}">{{ $initials($r['org']->name) }}</span>
                        <a href="{{ route('admin.companies.show', $r['org']->id) }}" class="min-w-0 flex-1"><span class="block truncate text-sm font-medium hover:underline">{{ $r['org']->name }}{{ $r['org']->verified_at ? ' ✓' : '' }}</span><span class="text-xs text-slate-500">{{ $r['wins'] }} won · {{ $r['bids'] }} bids</span></a>
                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ ['Excellent' => 'bg-emerald-100 text-emerald-800', 'Good' => 'bg-emerald-50 text-emerald-700', 'Fair' => 'bg-amber-100 text-amber-900', 'Low' => 'bg-red-100 text-red-700'][$r['trust']['label']] ?? 'bg-slate-100 text-slate-600' }}">{{ $r['trust']['score'] ?? 'New' }}</span>
                    </li>
                @empty
                    <li class="py-8 text-center text-sm text-slate-500">No supplier activity in this period.</li>
                @endforelse
            </ul>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-baseline justify-between">
                <h2 class="font-semibold">Live and next 24 hours</h2>
                <a href="{{ route('admin.auctions') }}" class="text-xs font-semibold text-emerald-700 hover:underline">Open monitor →</a>
            </div>
            <ul class="mt-3 divide-y divide-slate-100">
                @forelse ($live as $a)
                    <li class="py-2.5">
                        <a href="{{ route('admin.auctions.show', $a->id) }}" class="flex items-center justify-between gap-3">
                            <span class="min-w-0"><span class="block truncate text-sm font-medium hover:underline">{{ $a->rfq?->title ?? 'Auction #'.$a->id }}</span>
                                <span class="text-xs text-slate-500">{{ $a->current_l1 !== null ? 'L1 '.\App\Support\Money::inr($a->current_l1) : '' }} · {{ $a->bid_count }} bids</span></span>
                            <span class="shrink-0 text-right">@include('admin.auctions._status', ['a' => $a])
                                <span class="mt-0.5 block text-xs tabular-nums text-slate-500">
                                    @if ($a->isPaused()) frozen
                                    @elseif ($a->status->value === 'live') <span data-countdown-to="{{ $a->ends_at->getTimestampMs() }}" data-countdown-done="ending"></span>
                                    @else {{ $a->starts_at->ist()->format('h:i A') }}
                                    @endif
                                </span>
                            </span>
                        </a>
                    </li>
                @empty
                    <li class="py-8 text-center text-sm text-slate-500">No auction running or due in the next 24 hours.</li>
                @endforelse
            </ul>
        </section>
    </div>

    {{-- Activity --}}
    <section class="mt-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex items-baseline justify-between">
            <h2 class="font-semibold">Recent activity</h2>
            <a href="{{ route('admin.audit') }}" class="text-xs font-semibold text-emerald-700 hover:underline">Full audit log →</a>
        </div>
        <ol class="mt-3 grid gap-x-8 sm:grid-cols-2">
            @forelse ($activity as $l)
                <li class="flex items-start gap-3 border-b border-slate-100 py-2.5 text-sm">
                    <span class="mt-1.5 size-2 shrink-0 rounded-full {{ str_contains($l->action, 'getl1') || str_contains($l->action, 'suspended') ? 'bg-amber-500' : 'bg-emerald-500' }}" aria-hidden="true"></span>
                    <span class="min-w-0 flex-1">
                        <span class="font-medium">{{ $l->organization_id && isset($activityOrgs[$l->organization_id]) ? $activityOrgs[$l->organization_id] : ($l->user?->name ?? 'GetL1') }}</span>
                        {{ \App\Services\Admin\Dashboard::ACTIVITY[$l->action] ?? str_replace('_', ' ', $l->action) }}
                    </span>
                    <span class="shrink-0 text-xs text-slate-500" title="{{ $l->created_at->ist()->format('d M Y, h:i A') }}">{{ $l->created_at->diffForHumans(short: true) }}</span>
                </li>
            @empty
                <li class="py-6 text-sm text-slate-500">Nothing yet.</li>
            @endforelse
        </ol>
    </section>
@endsection
