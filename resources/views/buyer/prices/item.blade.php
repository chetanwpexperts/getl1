@extends('layouts.app')

@section('title', $name)

@section('content')
    @php
        $inr = fn ($v) => \App\Support\Money::inr($v);
        $chron = $points->sortBy(fn ($p) => $p->priced_on->toDateString().'|'.str_pad((string) $p->id, 12, '0', STR_PAD_LEFT))->values();
        $max = (float) $points->max('rate');
        $min = (float) $points->min('rate');
    @endphp
    <x-page-header :title="$name" :subtitle="($points->last()->spec ? $points->last()->spec.' · ' : '').'Price paid per '.$unit.', before GST.'">
        <a href="{{ route('buyer.prices.index') }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium shadow-sm hover:bg-slate-50">All items</a>
    </x-page-header>

    <div class="mb-6 grid gap-4 sm:grid-cols-4">
        <x-stat-card label="Last rate" icon="billing" :value="$inr($points->first()->rate)" :hint="$points->first()->priced_on->format('d M Y')" />
        <x-stat-card label="Lowest paid" icon="savings" tone="emerald" :value="$inr($min)" />
        <x-stat-card label="Highest paid" icon="alert" tone="amber" :value="$inr($max)" />
        <x-stat-card label="Orders" icon="orders" tone="sky" :value="number_format($points->count())" />
    </div>

    @if ($contracts->isNotEmpty())
        <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
            @foreach ($contracts as $c)
                <p><span class="font-semibold">Rate contract {{ $c['contract']->rc_number }}:</span> {{ $inr($c['rate']) }} per {{ $unit }} with {{ $c['contract']->supplier?->name }}, until {{ $c['contract']->valid_to->format('d M Y') }}.
                    <a href="{{ route('buyer.contracts.show', $c['contract']->id) }}" class="font-medium underline">Open</a></p>
            @endforeach
        </div>
    @endif

    @if ($chron->count() > 1)
        @php
            $w = 720; $h = 160; $pad = 24;
            $span = max(0.01, $max - $min);
            $x = fn ($i) => $pad + ($chron->count() === 1 ? 0 : $i * ($w - 2 * $pad) / ($chron->count() - 1));
            $y = fn ($v) => $h - $pad - (((float) $v - $min) / $span) * ($h - 2 * $pad);
            $path = $chron->map(fn ($p, $i) => ($i ? 'L' : 'M').round($x($i), 1).' '.round($y($p->rate), 1))->implode(' ');
        @endphp
        <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-sm font-semibold">Rate over time</h2>
            <svg viewBox="0 0 {{ $w }} {{ $h }}" class="mt-3 h-40 w-full" role="img" aria-label="Rate paid over time">
                <line x1="{{ $pad }}" x2="{{ $w - $pad }}" y1="{{ $h - $pad }}" y2="{{ $h - $pad }}" stroke="#e2e8f0" />
                <path d="{{ $path }}" fill="none" stroke="#047857" stroke-width="2" stroke-linejoin="round" />
                @foreach ($chron as $i => $p)
                    <circle cx="{{ round($x($i), 1) }}" cy="{{ round($y($p->rate), 1) }}" r="3.5" fill="#047857"><title>{{ $p->priced_on->format('d M Y') }}: {{ $inr($p->rate) }}</title></circle>
                @endforeach
                <text x="{{ $pad }}" y="14" class="fill-slate-400 text-[11px]">{{ $inr($max) }}</text>
                <text x="{{ $pad }}" y="{{ $h - 6 }}" class="fill-slate-400 text-[11px]">{{ $inr($min) }}</text>
            </svg>
        </section>
    @endif

    <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full min-w-[700px] text-sm">
            <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                <tr><th class="px-5 py-3.5">Date</th><th class="px-5 py-3.5">Supplier</th><th class="px-5 py-3.5">PO</th><th class="px-5 py-3.5 text-right">Quantity</th><th class="px-5 py-3.5 text-right">Rate</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($points as $p)
                    <tr>
                        <td class="px-5 py-3.5">{{ $p->priced_on->format('d M Y') }}</td>
                        <td class="px-5 py-3.5">{{ $p->supplier?->name }}</td>
                        <td class="px-5 py-3.5"><a href="{{ route('buyer.orders.show', $p->award_id) }}" class="hover:underline">{{ $p->award?->po_number }}</a></td>
                        <td class="px-5 py-3.5 text-right tabular-nums">{{ $p->qty !== null ? rtrim(rtrim(number_format((float) $p->qty, 3), '0'), '.') : '—' }} {{ $unit }}</td>
                        <td class="px-5 py-3.5 text-right font-medium tabular-nums">{{ $inr($p->rate) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
