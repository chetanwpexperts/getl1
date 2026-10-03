@extends('layouts.app')

@section('title', $rc->title)

@section('content')
    @php
        $inr = fn ($v) => \App\Support\Money::inr($v);
        $state = $rc->state();
        $field = 'block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20';
    @endphp
    <x-page-header :title="$rc->title" :subtitle="$rc->rc_number.' · '.$rc->supplier?->name">
        <a href="{{ route('buyer.contracts.index') }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium shadow-sm hover:bg-slate-50">All contracts</a>
    </x-page-header>


    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                    <h2 class="font-semibold">Agreed rates</h2>
                    @include('buyer.prices._contract-state', ['rc' => $rc])
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[620px] text-sm">
                        <thead class="text-left text-xs uppercase tracking-wide text-slate-400">
                            <tr><th class="px-5 py-3">Item</th><th class="px-5 py-3 text-right">Agreed rate</th><th class="px-5 py-3 text-right">GST</th><th class="px-5 py-3 text-right">Last paid</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($rows as $r)
                                <tr>
                                    <td class="px-5 py-3"><span class="font-medium">{{ $r['name'] }}</span><span class="block text-xs text-slate-500">per {{ $r['unit'] }}{{ $r['spec'] ? ' · '.$r['spec'] : '' }}</span></td>
                                    <td class="px-5 py-3 text-right font-medium tabular-nums">{{ $inr($r['rate']) }}</td>
                                    <td class="px-5 py-3 text-right tabular-nums text-slate-600">{{ $r['gst_rate'] !== null ? rtrim(rtrim(number_format((float) $r['gst_rate'], 2), '0'), '.').'%' : '—' }}</td>
                                    <td class="px-5 py-3 text-right tabular-nums text-slate-600">
                                        @if ($r['last'])
                                            <a href="{{ route('buyer.prices.item', ['key' => $r['last']->item_key]) }}" class="hover:underline">{{ $inr($r['last']->rate) }}</a>
                                            <span class="block text-xs text-slate-400">{{ $r['last']->priced_on->format('d M Y') }}</span>
                                        @else — @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
            @if ($rc->terms)
                <section class="rounded-2xl border border-slate-200 bg-white p-5 text-sm shadow-sm">
                    <h2 class="font-semibold">Terms</h2>
                    <p class="mt-2 whitespace-pre-line text-slate-700">{{ $rc->terms }}</p>
                </section>
            @endif
        </div>

        <aside class="space-y-6">
            <section class="rounded-2xl border border-slate-200 bg-white p-5 text-sm shadow-sm">
                <dl class="space-y-3">
                    <div><dt class="text-xs text-slate-500">Valid</dt><dd class="font-medium">{{ $rc->valid_from->format('d M Y') }} – {{ $rc->valid_to->format('d M Y') }}</dd>
                        @if ($state === 'active')<dd class="text-xs {{ $rc->daysLeft() <= 30 ? 'font-medium text-amber-800' : 'text-slate-500' }}">{{ $rc->daysLeft() === 0 ? 'Ends today' : $rc->daysLeft().' '.\Illuminate\Support\Str::plural('day', $rc->daysLeft()).' left' }}</dd>@endif</div>
                    <div><dt class="text-xs text-slate-500">Supplier</dt><dd class="font-medium">{{ $rc->supplier?->name }}</dd>
                        <dd class="text-xs {{ $rc->supplier_accepted_at ? 'text-emerald-700' : 'text-amber-800' }}">{{ $rc->supplier_accepted_at ? '✓ Confirmed on '.$rc->supplier_accepted_at->ist()->format('d M Y') : 'Waiting for the supplier to confirm' }}</dd></div>
                    @if ($rc->award)<div><dt class="text-xs text-slate-500">Made from</dt><dd><a href="{{ route('buyer.orders.show', $rc->award_id) }}" class="font-medium text-emerald-700 hover:underline">{{ $rc->award->po_number }}</a></dd></div>@endif
                    <div><dt class="text-xs text-slate-500">Created by</dt><dd>{{ $rc->creator?->name }} · {{ $rc->created_at->ist()->format('d M Y') }}</dd></div>
                    @if ($state === 'cancelled')<div><dt class="text-xs text-slate-500">Ended early</dt><dd>{{ $rc->cancelled_at?->ist()->format('d M Y') }}: {{ $rc->cancel_reason }}</dd></div>@endif
                </dl>
            </section>

            @if ($canEdit && in_array($state, ['active', 'upcoming'], true))
                <form method="POST" action="{{ route('buyer.contracts.cancel', $rc->id) }}" class="space-y-2 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" data-confirm="End this rate contract? The supplier is told.">
                    @csrf
                    <h2 class="text-sm font-semibold">End early</h2>
                    <input name="cancel_reason" required minlength="5" maxlength="1000" placeholder="Reason (the supplier sees it)" class="{{ $field }}">
                    @error('cancel_reason') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    <button class="w-full rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">End rate contract</button>
                </form>
            @endif
        </aside>
    </div>
@endsection
