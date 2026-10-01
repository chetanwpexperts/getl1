@extends('layouts.admin')

@section('title', 'Live auctions')

@section('content')
    @php $inr = fn ($v) => $v === null ? '—' : \App\Support\Money::inr($v); @endphp
    <x-live-page :url="route('admin.auctions.live')" :live="$live" interval="4000" />

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">Live auctions</h1>
            <p class="mt-1 text-sm text-slate-600">Every company's running and upcoming auctions. Updates on its own every few seconds.</p>
        </div>
        <p class="text-xs text-slate-500">Server time <span class="font-mono tabular-nums">{{ now()->ist()->format('h:i:s A') }}</span> IST</p>
    </div>

    <div class="mt-5 grid grid-cols-2 gap-3 md:grid-cols-5">
        @include('admin._stat', ['label' => 'Running now', 'value' => $stats['live']])
        @include('admin._stat', ['label' => 'Paused', 'value' => $stats['paused'], 'warn' => $stats['paused'] > 0])
        @include('admin._stat', ['label' => 'Bids, last 5 min', 'value' => $stats['bids_5m']])
        @include('admin._stat', ['label' => 'Refused, last 5 min', 'value' => $stats['rejected_5m'], 'warn' => $stats['rejected_5m'] > 20])
        @include('admin._stat', ['label' => 'Scheduled today', 'value' => $stats['today']])
    </div>

    <h2 class="mt-8 text-sm font-semibold uppercase tracking-wide text-slate-500">Running</h2>
    <div class="mt-3 grid gap-3 lg:grid-cols-2">
        @forelse ($running as $a)
            @php $act = $activity[$a->id] ?? null; @endphp
            <a href="{{ route('admin.auctions.show', $a->id) }}" class="block rounded-xl border bg-white p-4 transition hover:shadow-md {{ $a->isPaused() ? 'border-amber-300 ring-1 ring-amber-200' : 'border-slate-200' }}">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="truncate font-semibold">{{ $rfqs[$a->rfq_id] ?? 'RFQ' }}</p>
                        <p class="truncate text-xs text-slate-500">{{ $orgs[$a->organization_id] ?? '' }} · #{{ $a->id }}</p>
                    </div>
                    <div class="text-right">
                        @include('admin.auctions._status', ['a' => $a])
                        <p class="mt-1 font-mono text-lg font-semibold tabular-nums">
                            @if ($a->isPaused())
                                {{ gmdate($a->remainingMs() >= 3600000 ? 'H:i:s' : 'i:s', intdiv($a->remainingMs(), 1000)) }}
                            @else
                                <span data-countdown-to="{{ $a->ends_at->getTimestampMs() }}" data-countdown-done="ending…">--:--</span>
                            @endif
                        </p>
                    </div>
                </div>
                <dl class="mt-3 grid grid-cols-4 gap-2 text-xs">
                    <div><dt class="text-slate-500">Current L1</dt><dd class="text-sm font-semibold tabular-nums">{{ $inr($a->current_l1) }}</dd></div>
                    <div><dt class="text-slate-500">Saving</dt><dd class="text-sm font-semibold tabular-nums text-emerald-700">{{ $a->savingsPct() !== null ? $a->savingsPct().'%' : '—' }}</dd></div>
                    <div><dt class="text-slate-500">Bids (5 min)</dt><dd class="text-sm font-semibold tabular-nums">{{ $a->bid_count }} <span class="font-normal text-slate-500">({{ $act->recent_bids ?? 0 }})</span></dd></div>
                    <div><dt class="text-slate-500">Active / total</dt><dd class="text-sm font-semibold tabular-nums">{{ $act->active ?? 0 }} / {{ $participants[$a->id] ?? 0 }}</dd></div>
                </dl>
                @if (($rejected[$a->id] ?? 0) > 0)<p class="mt-2 text-xs text-amber-800">{{ $rejected[$a->id] }} refused {{ \Illuminate\Support\Str::plural('bid', $rejected[$a->id]) }} in the last 5 minutes</p>@endif
                @if ($a->isPaused())<p class="mt-2 text-xs font-medium text-amber-900">Paused {{ $a->paused_at->diffForHumans() }}: {{ $a->pause_reason }}</p>@endif
            </a>
        @empty
            <p class="rounded-xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-500 lg:col-span-2">No auction is running right now.</p>
        @endforelse
    </div>

    <div class="mt-8 grid gap-6 lg:grid-cols-2">
        <section>
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Next 24 hours</h2>
            <ul class="mt-3 divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white text-sm">
                @forelse ($upcoming as $a)
                    <li><a href="{{ route('admin.auctions.show', $a->id) }}" class="flex items-center justify-between gap-3 px-4 py-2.5 hover:bg-slate-50">
                        <span class="min-w-0"><span class="block truncate font-medium">{{ $rfqs[$a->rfq_id] ?? 'RFQ' }}</span><span class="text-xs text-slate-500">{{ $orgs[$a->organization_id] ?? '' }} · {{ $participants[$a->id] ?? 0 }} suppliers</span></span>
                        <span class="shrink-0 text-right text-xs text-slate-600">{{ $a->starts_at->ist()->format('h:i A') }}<br><span data-countdown-to="{{ $a->starts_at->getTimestampMs() }}" data-countdown-prefix="in " data-countdown-done="starting"></span></span>
                    </a></li>
                @empty
                    <li class="px-4 py-6 text-center text-slate-500">Nothing scheduled.</li>
                @endforelse
            </ul>
        </section>
        <section>
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Finished in the last 6 hours</h2>
            <ul class="mt-3 divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white text-sm">
                @forelse ($recent as $a)
                    <li><a href="{{ route('admin.auctions.show', $a->id) }}" class="flex items-center justify-between gap-3 px-4 py-2.5 hover:bg-slate-50">
                        <span class="min-w-0"><span class="block truncate font-medium">{{ $rfqs[$a->rfq_id] ?? 'RFQ' }}</span><span class="text-xs text-slate-500">{{ $orgs[$a->organization_id] ?? '' }} · {{ $a->bid_count }} bids</span></span>
                        <span class="shrink-0 text-right">@include('admin.auctions._status', ['a' => $a])<span class="mt-0.5 block text-xs text-slate-500">{{ $a->savingsPct() !== null ? $a->savingsPct().'% saved' : '' }}</span></span>
                    </a></li>
                @empty
                    <li class="px-4 py-6 text-center text-slate-500">None.</li>
                @endforelse
            </ul>
        </section>
    </div>
@endsection
