@extends('layouts.app')

@section('title', $award->po_number)

@section('content')
    @php
        $inr = fn ($v) => \App\Support\Money::inr($v);
        $qty = fn ($q) => rtrim(rtrim(number_format((float) $q, 3, '.', ','), '0'), '.');
        $rate = fn ($v) => '₹'.number_format((float) $v, fmod((float) $v * 100, 1) != 0 ? 4 : 2);
    @endphp
    <a href="{{ route('supplier.orders.index') }}" class="text-sm text-slate-600 hover:text-slate-900">← Orders</a>

    <div class="mt-2 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Purchase order {{ $award->po_number }}</h1>
            <p class="mt-1 text-sm text-slate-600">{{ $buyer->name }} · {{ $rfq->title }} ({{ $rfq->ref_no }}) · {{ $award->po_sent_at?->ist()->format('d M Y') }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('supplier.orders.po', $award->id) }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold hover:bg-slate-50">Download PDF</a>
            @unless ($award->supplier_accepted_at)
                <form method="POST" action="{{ route('supplier.orders.accept', $award->id) }}" data-confirm="Accept purchase order {{ $award->po_number }}?">
                    @csrf
                    <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Accept order</button>
                </form>
            @endunless
        </div>
    </div>

    @if ($award->supplier_accepted_at)
        <p class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">✓ You accepted this order on {{ $award->supplier_accepted_at->ist()->format('d M Y, h:i A') }} IST.</p>
    @else
        <p class="mt-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">Please review and accept this order. The buyer is notified as soon as you do.</p>
    @endif

    <section class="mt-6 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full min-w-[640px] text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr><th class="px-4 py-2">Item</th><th class="px-4 py-2 text-right">Qty</th><th class="px-4 py-2 text-right">Rate</th><th class="px-4 py-2 text-right">Amount</th><th class="px-4 py-2 text-right">GST</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($award->lines['items'] ?? [] as $line)
                    <tr>
                        <td class="px-4 py-2">{{ $line['name'] }}@if (! empty($line['spec']))<span class="block text-xs text-slate-500">{{ $line['spec'] }}</span>@endif</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $qty($line['qty']) }} {{ $line['unit'] }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $rate($line['unit_price']) }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $inr($line['amount']) }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $inr($line['gst']) }} <span class="text-xs text-slate-500">({{ rtrim(rtrim(number_format($line['gst_rate'], 2), '0'), '.') }}%)</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <dl class="ml-auto grid max-w-sm grid-cols-2 gap-x-6 gap-y-1 border-t border-slate-100 px-5 py-4 text-sm tabular-nums">
            <dt class="text-slate-600">Taxable value</dt><dd class="text-right">{{ $inr($award->total) }}</dd>
            <dt class="text-slate-600">GST</dt><dd class="text-right">{{ $inr($award->gst_total) }}</dd>
            @if ((float) $award->freight_total > 0)<dt class="text-slate-600">Freight</dt><dd class="text-right">{{ $inr($award->freight_total) }}</dd>@endif
            <dt class="border-t border-slate-200 pt-1 font-semibold">Total</dt><dd class="border-t border-slate-200 pt-1 text-right font-semibold">{{ $inr($award->grand_total) }}</dd>
        </dl>
    </section>
@endsection
