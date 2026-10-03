@extends('layouts.app')

@section('title', 'Savings report')

@section('content')
    @php
        $inr = fn ($v) => \App\Support\Money::inr($v, 0);
        $t = $r['totals'];
        $signed = fn ($v) => $v === null ? '—' : ($v >= 0 ? $inr($v) : '−'.$inr(abs($v)));
    @endphp

    @include('buyer.prices._tabs')
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Savings report</h1>
            <p class="mt-1 text-sm text-slate-600">Money saved on finished awards, before GST. Share it with your management.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <form method="GET" action="{{ route('buyer.reports.savings') }}">
                <select name="period" onchange="this.form.submit()" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" aria-label="Period">
                    @foreach ($periods as $key => $label)<option value="{{ $key }}" @selected($r['period'] === $key)>{{ $label }}</option>@endforeach
                </select>
            </form>
            @unless ($locked)
                <a href="{{ route('buyer.reports.savings.csv', ['period' => $r['period']]) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium hover:bg-slate-50">Export (CSV)</a>
                <button type="button" onclick="window.print()" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium hover:bg-slate-50 print:hidden">Print</button>
            @endunless
        </div>
    </div>

    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-5">
            <p class="text-sm text-emerald-900">Saved vs last purchase price</p>
            <p class="mt-1 text-3xl font-semibold tabular-nums text-emerald-800">{{ $t['vs_last_count'] ? $signed($t['vs_last']) : '—' }}</p>
            <p class="mt-1 text-xs text-emerald-900">{{ $t['vs_last_pct'] !== null ? $t['vs_last_pct'].'% lower' : 'Shown once an item is bought again: last prices fill in from your past POs' }}</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5">
            <p class="text-sm text-slate-600">Saved vs best sealed quote</p>
            <p class="mt-1 text-3xl font-semibold tabular-nums">{{ $signed($t['vs_sealed']) }}</p>
            <p class="mt-1 text-xs text-slate-500">{{ $t['vs_sealed_pct'] !== null ? $t['vs_sealed_pct'].'% from live auctions' : 'From live auctions' }}</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5">
            <p class="text-sm text-slate-600">Total awarded</p>
            <p class="mt-1 text-3xl font-semibold tabular-nums">{{ $inr($t['spend']) }}</p>
            <p class="mt-1 text-xs text-slate-500">{{ $t['awards'] }} {{ \Illuminate\Support\Str::plural('order', $t['awards']) }} · {{ $t['auctions'] }} via live auction</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5">
            <p class="text-sm text-slate-600">Competition</p>
            <p class="mt-1 text-3xl font-semibold tabular-nums">{{ $t['avg_quotes'] }}</p>
            <p class="mt-1 text-xs text-slate-500">quotes per RFQ on average · {{ $t['l1_rate'] }}% awarded to L1</p>
        </div>
    </div>

    @if ($locked)
        <div class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm p-6 text-center">
            <p class="font-semibold">See savings by order and category, and export for your accounts team</p>
            <p class="mt-1 text-sm text-slate-600">The detailed report and export are part of the Starter plan and above.</p>
            <a href="{{ route('buyer.billing.index') }}" class="mt-4 inline-block rounded-lg bg-emerald-700 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-800">See plans</a>
        </div>
    @elseif ($r['rows']->isEmpty())
        <p class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm px-5 py-10 text-center text-sm text-slate-600">No finished awards in this period yet. Savings appear here as soon as a purchase order is issued.</p>
    @else
        @if ($r['by_category']->count() > 1)
            <section class="mt-6 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-100 px-6 py-4 font-semibold">By category</h2>
                <table class="w-full min-w-[560px] text-sm">
                    <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                        <tr><th class="px-4 py-2">Category</th><th class="px-4 py-2 text-right">Orders</th><th class="px-4 py-2 text-right">Awarded</th><th class="px-4 py-2 text-right">vs sealed</th><th class="px-4 py-2 text-right">vs last price</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($r['by_category'] as $c)
                            <tr>
                                <td class="px-4 py-2">{{ $c['category'] }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $c['awards'] }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $inr($c['spend']) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $signed($c['vs_sealed']) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $signed($c['vs_last']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endif

        <section class="mt-6 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <h2 class="border-b border-slate-100 px-6 py-4 font-semibold">Orders</h2>
            <table class="w-full min-w-[820px] text-sm">
                <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                    <tr>
                        <th class="px-4 py-2">RFQ</th><th class="px-4 py-2">Supplier</th><th class="px-4 py-2 text-right">Awarded</th>
                        <th class="px-4 py-2 text-right">Best sealed</th><th class="px-4 py-2 text-right">vs sealed</th>
                        <th class="px-4 py-2 text-right">Last price</th><th class="px-4 py-2 text-right">vs last price</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($r['rows'] as $row)
                        <tr>
                            <td class="px-4 py-2">
                                <a href="{{ route('buyer.rfqs.show', $row['award']->rfq_id) }}" class="font-medium hover:underline">{{ $row['rfq']?->title }}</a>
                                <span class="block text-xs text-slate-500">{{ $row['rfq']?->ref_no }} · {{ $row['award']->approved_at?->ist()->format('d M Y') }} · {{ $row['source'] === 'auction' ? 'Live auction' : 'Sealed quotes' }} · {{ $row['quotes'] }} quotes</span>
                            </td>
                            <td class="px-4 py-2">{{ $row['supplier'] }}<span class="block text-xs text-slate-500">L{{ $row['award']->rank }} · {{ $row['award']->po_number }}</span></td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $inr($row['paid']) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-slate-600">{{ $row['best_sealed'] !== null ? $inr($row['best_sealed']) : '—' }}</td>
                            <td class="px-4 py-2 text-right tabular-nums {{ ($row['vs_sealed'] ?? 0) > 0 ? 'font-medium text-emerald-700' : (($row['vs_sealed'] ?? 0) < 0 ? 'text-red-700' : '') }}">{{ $signed($row['vs_sealed']) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-slate-600">{{ $row['last_total'] !== null ? $inr($row['last_total']) : '—' }}</td>
                            <td class="px-4 py-2 text-right tabular-nums {{ ($row['vs_last'] ?? 0) > 0 ? 'font-medium text-emerald-700' : (($row['vs_last'] ?? 0) < 0 ? 'text-red-700' : '') }}">{{ $signed($row['vs_last']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500">"vs sealed" is what the live auction (or your choice of supplier) changed against the best sealed quote; negative means you awarded above L1 for a recorded reason. "vs last price" uses the last purchase prices entered on the RFQ.</p>
        </section>
    @endif
@endsection
