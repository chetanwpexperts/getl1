@extends('layouts.app')

@section('title', $rc->title)

@section('content')
    @php $inr = fn ($v) => \App\Support\Money::inr($v); $state = $rc->state(); @endphp
    <x-page-header :title="$rc->title" :subtitle="$rc->rc_number.' · '.$rc->buyer?->name">
        <a href="{{ route('supplier.contracts.index') }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium shadow-sm hover:bg-slate-50">All contracts</a>
    </x-page-header>


    @if (in_array($state, ['active', 'upcoming'], true) && ! $rc->supplier_accepted_at)
        <form method="POST" action="{{ route('supplier.contracts.accept', $rc->id) }}" class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm"
              data-confirm="Confirm these rates for {{ $rc->valid_from->format('d M Y') }} to {{ $rc->valid_to->format('d M Y') }}?">
            @csrf
            <p class="text-amber-900">{{ $rc->buyer?->name }} has locked in these rates with you. Please check and confirm.</p>
            <button class="rounded-lg bg-emerald-700 px-4 py-2 font-semibold text-white hover:bg-emerald-800">Confirm rates</button>
        </form>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm lg:col-span-2">
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                <h2 class="font-semibold">Agreed rates</h2>
                @include('buyer.prices._contract-state', ['rc' => $rc])
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-slate-400">
                        <tr><th class="px-5 py-3">Item</th><th class="px-5 py-3 text-right">Rate</th><th class="px-5 py-3 text-right">GST</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($rc->items as $r)
                            <tr>
                                <td class="px-5 py-3"><span class="font-medium">{{ $r['name'] }}</span><span class="block text-xs text-slate-500">per {{ $r['unit'] }}{{ ! empty($r['spec']) ? ' · '.$r['spec'] : '' }}</span></td>
                                <td class="px-5 py-3 text-right font-medium tabular-nums">{{ $inr($r['rate']) }}</td>
                                <td class="px-5 py-3 text-right tabular-nums text-slate-600">{{ isset($r['gst_rate']) ? rtrim(rtrim(number_format((float) $r['gst_rate'], 2), '0'), '.').'%' : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
        <aside class="space-y-6">
            <section class="rounded-2xl border border-slate-200 bg-white p-5 text-sm shadow-sm">
                <dl class="space-y-3">
                    <div><dt class="text-xs text-slate-500">Valid</dt><dd class="font-medium">{{ $rc->valid_from->format('d M Y') }} – {{ $rc->valid_to->format('d M Y') }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Buyer</dt><dd class="font-medium">{{ $rc->buyer?->name }}</dd></div>
                    @if ($rc->supplier_accepted_at)<div><dt class="text-xs text-slate-500">You confirmed</dt><dd>{{ $rc->supplier_accepted_at->ist()->format('d M Y') }}</dd></div>@endif
                    @if ($rc->award)<div><dt class="text-xs text-slate-500">Based on</dt><dd><a href="{{ route('supplier.orders.show', $rc->award_id) }}" class="font-medium text-emerald-700 hover:underline">{{ $rc->award->po_number }}</a></dd></div>@endif
                    @if ($state === 'cancelled')<div><dt class="text-xs text-slate-500">Ended early</dt><dd>{{ $rc->cancel_reason }}</dd></div>@endif
                </dl>
            </section>
            @if ($rc->terms)
                <section class="rounded-2xl border border-slate-200 bg-white p-5 text-sm shadow-sm">
                    <h2 class="font-semibold">Terms</h2>
                    <p class="mt-2 whitespace-pre-line text-slate-700">{{ $rc->terms }}</p>
                </section>
            @endif
        </aside>
    </div>
@endsection
