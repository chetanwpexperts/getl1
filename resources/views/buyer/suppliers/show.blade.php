@extends('layouts.app')

@section('title', $entry->company_name)

@section('content')
    @php
        $inr = fn ($v) => \App\Support\Money::inr($v);
        $icon = ['ok' => ['✓', 'bg-emerald-100 text-emerald-800'], 'warn' => ['!', 'bg-amber-100 text-amber-900'], 'missing' => ['–', 'bg-slate-100 text-slate-500']];
    @endphp
    <x-page-header :title="$entry->company_name" :subtitle="collect([$org?->city, $org?->state, $entry->tag])->filter()->implode(' · ') ?: 'Supplier'">
        <a href="{{ route('buyer.suppliers.index') }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium shadow-sm hover:bg-slate-50">All suppliers</a>
        @if ($canManage)
            <a href="{{ route('buyer.suppliers.edit', $entry->id) }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium shadow-sm hover:bg-slate-50">Edit</a>
        @endif
    </x-page-header>

    @if ($entry->status === 'blocked')
        <p class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">Blocked: this supplier isn't invited to new RFQs.</p>
    @endif

    @if (! $org)
        <section class="rounded-2xl border border-slate-200 bg-white p-6 text-sm text-slate-600 shadow-sm">
            <p class="font-medium text-slate-900">Not on GetL1 yet</p>
            <p class="mt-1">Invite {{ $entry->company_name }} to an RFQ. Once they sign up, their GST checks and scorecard appear here.</p>
            <p class="mt-3">{{ collect([$entry->contact_name, $entry->contact_phone, $entry->contact_email])->filter()->implode(' · ') }}</p>
        </section>
    @else
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
                        <div>
                            <h2 class="font-semibold">Scorecard</h2>
                            <p class="text-xs text-slate-500">From your orders with them in the last {{ \App\Services\Suppliers\SupplierProfile::MONTHS }} months. Only your company sees this.</p>
                        </div>
                        @if ($score['score'] !== null)
                            <div class="text-right">
                                <p class="text-3xl font-semibold tabular-nums">{{ $score['score'] }}<span class="text-base font-normal text-slate-400">/100</span></p>
                                @include('buyer.suppliers._score-badge', ['score' => $score['score'], 'grade' => $score['grade']])
                            </div>
                        @endif
                    </div>
                    @if ($score['score'] === null)
                        <p class="px-5 py-4 text-sm text-slate-600">{{ $score['pos'] === 0
                            ? 'No purchase orders with '.$entry->company_name.' in the last '.\App\Services\Suppliers\SupplierProfile::MONTHS.' months, so there\'s nothing to score yet.'
                            : 'The overall score appears after the first delivery is recorded.' }}</p>
                    @endif
                    <ul class="divide-y divide-slate-100">
                        @foreach ($score['parts'] as $key => $part)
                            <li class="flex items-center gap-4 px-5 py-3.5">
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium">{{ $part['label'] }} <span class="font-normal text-slate-400">· {{ \App\Services\Suppliers\SupplierProfile::WEIGHTS[$key] }}%</span></p>
                                    <p class="text-xs text-slate-500">{{ $part['detail'] }}</p>
                                </div>
                                <div class="w-40">
                                    @if ($part['score'] !== null)
                                        <div class="flex items-center gap-2">
                                            <div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100" role="img" aria-label="{{ $part['label'] }}: {{ $part['score'] }} out of 100">
                                                <div class="h-full rounded-full {{ $part['score'] >= 85 ? 'bg-emerald-600' : ($part['score'] >= 70 ? 'bg-sky-600' : ($part['score'] >= 50 ? 'bg-amber-500' : 'bg-red-600')) }}" style="width: {{ $part['score'] }}%"></div>
                                            </div>
                                            <span class="w-8 text-right text-sm font-medium tabular-nums">{{ $part['score'] }}</span>
                                        </div>
                                    @else
                                        <p class="text-right text-xs text-slate-400">No data yet</p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>

                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <h2 class="border-b border-slate-100 px-5 py-4 font-semibold">Recent purchase orders</h2>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[560px] text-sm">
                            <tbody class="divide-y divide-slate-100">
                                @forelse ($orders as $o)
                                    <tr>
                                        <td class="px-5 py-3"><a href="{{ route('buyer.orders.show', $o->id) }}" class="font-medium hover:underline">{{ $o->po_number }}</a><span class="block text-xs text-slate-500">{{ $o->po_sent_at?->ist()->format('d M Y') }}</span></td>
                                        <td class="px-5 py-3">{{ $o->rfq?->title }}<span class="block text-xs text-slate-500">{{ $o->rfq?->ref_no }}</span></td>
                                        <td class="px-5 py-3 text-right tabular-nums">{{ $inr($o->grand_total) }}</td>
                                    </tr>
                                @empty
                                    <tr><td class="px-5 py-6 text-center text-slate-500">No purchase orders yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <aside class="space-y-6">
                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="font-semibold">Registration checks</h2>
                    <ul class="mt-3 space-y-3">
                        @foreach ($checks as $c)
                            <li class="flex gap-3 text-sm">
                                <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full text-[11px] font-bold {{ $icon[$c['status']][1] }}">{{ $icon[$c['status']][0] }}</span>
                                <span class="min-w-0"><span class="block font-medium">{{ $c['label'] }}</span><span class="block break-words text-xs text-slate-500">{{ $c['detail'] }}</span></span>
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-4 text-xs text-slate-500">Checks are made from the details the supplier entered. For large orders, also confirm the GSTIN is active on the GST portal.</p>
                </section>

                <section class="rounded-2xl border border-slate-200 bg-white p-5 text-sm shadow-sm">
                    <h2 class="font-semibold">Contact</h2>
                    <dl class="mt-3 space-y-2">
                        @if ($entry->contact_name)<div><dt class="text-xs text-slate-500">Person</dt><dd>{{ $entry->contact_name }}</dd></div>@endif
                        @if ($entry->contact_phone)<div><dt class="text-xs text-slate-500">Mobile</dt><dd>{{ $entry->contact_phone }}</dd></div>@endif
                        @if ($entry->contact_email)<div><dt class="text-xs text-slate-500">Email</dt><dd class="break-all">{{ $entry->contact_email }}</dd></div>@endif
                        @if ($org->address)<div><dt class="text-xs text-slate-500">Address</dt><dd>{{ $org->address }}{{ $org->pincode ? ' '.$org->pincode : '' }}</dd></div>@endif
                    </dl>
                </section>

                @if ($contracts->isNotEmpty())
                    <section class="rounded-2xl border border-slate-200 bg-white p-5 text-sm shadow-sm">
                        <h2 class="font-semibold">Rate contracts</h2>
                        <ul class="mt-3 space-y-2">
                            @foreach ($contracts as $rc)
                                <li class="flex items-center justify-between gap-2">
                                    <a href="{{ route('buyer.contracts.show', $rc->id) }}" class="truncate hover:underline">{{ $rc->title }}</a>
                                    @include('buyer.prices._contract-state', ['rc' => $rc])
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            </aside>
        </div>
    @endif
@endsection
