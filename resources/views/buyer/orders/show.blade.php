@extends('layouts.app')

@section('title', $award->po_number)

@section('content')
    @php
        $inr = fn ($v) => \App\Support\Money::inr($v);
        $qty = fn ($q) => rtrim(rtrim(number_format((float) $q, 3, '.', ','), '0'), '.');
        $field = 'block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20';
        $statusBadge = ['submitted' => 'bg-amber-100 text-amber-900', 'approved' => 'bg-sky-100 text-sky-800', 'paid' => 'bg-emerald-100 text-emerald-800', 'disputed' => 'bg-red-50 text-red-700'];
        $statusText = ['submitted' => 'To review', 'approved' => 'Approved, to pay', 'paid' => 'Paid', 'disputed' => 'Disputed'];
        $today = now()->setTimezone(config('app.display_timezone'))->toDateString();
    @endphp

    <x-breadcrumb :items="[['Purchase orders', route('buyer.orders.index')]]" :current="$award->po_number" />

    <x-page-header :title="'Purchase order '.$award->po_number" :subtitle="$award->supplier->name.' · '.$award->rfq?->title.' ('.$award->rfq?->ref_no.') · '.$award->po_sent_at?->ist()->format('d M Y')">
        <a href="{{ route('buyer.awards.po', $award->id) }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium shadow-sm hover:bg-slate-50"><x-icon name="download" class="size-4 text-slate-500" /> PO (PDF)</a>
    </x-page-header>

    <div class="mb-6 flex flex-wrap gap-2 text-xs">
        <span class="rounded-full px-2.5 py-1 font-medium {{ $award->supplier_accepted_at ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900' }}">{{ $award->supplier_accepted_at ? '✓ Accepted by supplier '.$award->supplier_accepted_at->ist()->format('d M') : 'Not yet accepted by supplier' }}</span>
        <span class="rounded-full px-2.5 py-1 font-medium {{ $summary['complete'] ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700' }}">{{ $summary['complete'] ? '✓ Fully received' : 'Delivery pending' }}</span>
        @if ($msme)<span class="rounded-full bg-violet-100 px-2.5 py-1 font-medium text-violet-800" title="Pay within 45 days of accepting the goods (MSMED Act); also needed for your tax deduction (43B(h)).">MSME supplier · 45-day rule</span>@endif
    </div>

    {{-- Lines: ordered vs received --}}
    <section class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full min-w-[760px] text-sm">
            <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                <tr><th class="px-5 py-3.5">Item</th><th class="px-5 py-3.5 text-right">Ordered</th><th class="px-5 py-3.5 text-right">Accepted</th><th class="px-5 py-3.5 text-right">Rejected</th><th class="px-5 py-3.5 text-right">Pending</th><th class="px-5 py-3.5 text-right">Rate</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($summary['lines'] as $l)
                    <tr>
                        <td class="px-5 py-3.5 font-medium">{{ $l['name'] }}</td>
                        <td class="px-5 py-3.5 text-right tabular-nums">{{ $qty($l['ordered']) }} {{ $l['unit'] }}</td>
                        <td class="px-5 py-3.5 text-right tabular-nums text-emerald-700">{{ $qty($l['accepted']) }}</td>
                        <td class="px-5 py-3.5 text-right tabular-nums {{ $l['rejected'] > 0 ? 'text-red-700' : 'text-slate-400' }}">{{ $qty($l['rejected']) }}</td>
                        <td class="px-5 py-3.5 text-right tabular-nums {{ $l['pending'] > 0 ? 'font-medium' : 'text-slate-400' }}">{{ $qty($l['pending']) }}</td>
                        <td class="px-5 py-3.5 text-right tabular-nums">{{ $inr($l['rate']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <dl class="ml-auto grid max-w-md grid-cols-2 gap-x-6 gap-y-1 border-t border-slate-100 px-5 py-4 text-sm tabular-nums">
            <dt class="text-slate-600">PO value (before GST)</dt><dd class="text-right">{{ $inr($award->total) }}</dd>
            <dt class="text-slate-600">Accepted so far</dt><dd class="text-right text-emerald-700">{{ $inr($summary['accepted_value']) }}</dd>
            <dt class="text-slate-600">Total incl. GST</dt><dd class="text-right font-semibold">{{ $inr($award->grand_total) }}</dd>
        </dl>
    </section>

    <div class="mt-8 grid gap-6 lg:grid-cols-5">
        {{-- Goods receipts --}}
        <section id="receipts" class="scroll-mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm lg:col-span-2">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="font-semibold">Goods received</h2>
                <p class="mt-0.5 text-xs text-slate-500">Record each delivery. The supplier sees what was accepted or rejected.</p>
            </div>
            @if ($receipts->isEmpty())
                <p class="px-5 py-5 text-sm text-slate-600">Nothing received yet.</p>
            @else
                <ul class="divide-y divide-slate-100 text-sm">
                    @foreach ($receipts as $r)
                        <li class="px-5 py-3">
                            <p class="flex flex-wrap justify-between gap-2"><span class="font-medium">{{ $r->grn_number }}</span><span class="text-xs text-slate-500">{{ $r->received_on->format('d M Y') }} · {{ $r->receiver?->name }}</span></p>
                            <ul class="mt-1 space-y-0.5 text-xs text-slate-600">
                                @foreach ($r->lines as $l)
                                    <li>{{ $l['name'] }}: {{ $qty($l['accepted']) }} {{ $l['unit'] }} accepted @if ($l['rejected'] > 0)<span class="text-red-700">· {{ $qty($l['rejected']) }} rejected ({{ $l['reason'] }})</span>@endif</li>
                                @endforeach
                            </ul>
                            @if ($r->challan_no)<p class="mt-1 text-xs text-slate-500">Challan / e-way bill: {{ $r->challan_no }}</p>@endif
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($canEdit && ! $summary['complete'])
                <details class="border-t border-slate-100 px-5 py-4 text-sm" @if ($errors->hasAny(['received_on', 'lines']) || $errors->has('lines.*')) open @endif>
                    <summary class="cursor-pointer font-semibold text-emerald-700">Record a delivery</summary>
                    <form method="POST" action="{{ route('buyer.orders.receive', $award->id) }}" class="mt-3 space-y-3">
                        @csrf
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label for="received_on" class="block text-xs font-medium text-slate-600">Received on</label>
                                <input type="date" id="received_on" name="received_on" required max="{{ $today }}" value="{{ old('received_on', $today) }}" class="{{ $field }} mt-1">
                            </div>
                            <div>
                                <label for="challan_no" class="block text-xs font-medium text-slate-600">Challan / e-way bill no.</label>
                                <input id="challan_no" name="challan_no" maxlength="60" value="{{ old('challan_no') }}" class="{{ $field }} mt-1">
                            </div>
                        </div>
                        @foreach ($summary['lines'] as $id => $l)
                            @continue($l['pending'] <= 0)
                            <fieldset class="rounded-xl border border-slate-200 p-3">
                                <legend class="px-1 text-xs font-medium text-slate-700">{{ $l['name'] }} <span class="font-normal text-slate-500">· {{ $qty($l['pending']) }} {{ $l['unit'] }} pending</span></legend>
                                <div class="grid grid-cols-2 gap-2">
                                    <label class="text-xs text-slate-600">Received<input name="lines[{{ $id }}][received]" inputmode="decimal" value="{{ old("lines.$id.received") }}" class="{{ $field }} mt-1" placeholder="0"></label>
                                    <label class="text-xs text-slate-600">Rejected<input name="lines[{{ $id }}][rejected]" inputmode="decimal" value="{{ old("lines.$id.rejected") }}" class="{{ $field }} mt-1" placeholder="0"></label>
                                </div>
                                <input name="lines[{{ $id }}][reason]" maxlength="255" value="{{ old("lines.$id.reason") }}" class="{{ $field }} mt-2" placeholder="Reason, if any rejected">
                                @foreach (['received', 'rejected', 'reason'] as $f) @error("lines.$id.$f") <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror @endforeach
                            </fieldset>
                        @endforeach
                        @error('lines') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                        @error('received_on') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                        <input name="notes" maxlength="1000" value="{{ old('notes') }}" class="{{ $field }}" placeholder="Notes (optional)">
                        <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Save goods receipt</button>
                    </form>
                </details>
            @endif
        </section>

        {{-- Invoices --}}
        <section id="invoices" class="scroll-mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm lg:col-span-3">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="font-semibold">Supplier invoices</h2>
                <p class="mt-0.5 text-xs text-slate-500">The supplier uploads invoices here. Each is checked against the PO, goods received, GST and GSTIN.</p>
            </div>
            @if ($invoices->isEmpty())
                <p class="px-5 py-5 text-sm text-slate-600">No invoice yet. {{ $award->supplier_accepted_at ? 'The supplier can upload one from their order page.' : 'The supplier can upload one after accepting the PO.' }}</p>
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach ($invoices as $inv)
                        @php $days = $inv->daysLeft(); @endphp
                        <li class="px-5 py-4 text-sm">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="font-medium">{{ $inv->invoice_number }} <span class="font-normal text-slate-500">· {{ $inv->invoice_date->format('d M Y') }}</span></p>
                                    <p class="mt-0.5 tabular-nums">{{ $inr($inv->total_amount) }} <span class="text-xs text-slate-500">({{ $inr($inv->taxable_amount) }} + GST {{ $inr($inv->gst_amount) }})</span></p>
                                    <a href="{{ route('buyer.invoices.file', $inv->id) }}" class="mt-1 inline-block text-xs font-medium text-emerald-700 hover:underline">View invoice ({{ $inv->original_name }})</a>
                                </div>
                                <div class="text-right">
                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $statusBadge[$inv->status] }}">{{ $statusText[$inv->status] }}</span>
                                    @if ($inv->isOutstanding() && $inv->due_date)
                                        <p class="mt-1 text-xs {{ $days < 0 ? 'font-semibold text-red-700' : ($days <= 7 ? 'text-amber-800' : 'text-slate-600') }}">
                                            Pay by {{ $inv->due_date->format('d M Y') }} · {{ $days < 0 ? abs($days).' days overdue' : ($days === 0 ? 'today' : $days.' days left') }}
                                        </p>
                                        <p class="text-[11px] text-slate-500">{{ $inv->due_basis }}</p>
                                    @elseif ($inv->status === 'paid')
                                        <p class="mt-1 text-xs text-slate-600">Paid {{ $inr($inv->paid_amount) }} on {{ $inv->paid_on->format('d M Y') }}{{ $inv->payment_ref ? ' · '.$inv->payment_ref : '' }}</p>
                                        @if ($inv->due_date && $inv->paid_on->gt($inv->due_date))<p class="text-xs text-red-700">Paid after the due date</p>@endif
                                    @endif
                                </div>
                            </div>

                            <ul class="mt-3 grid gap-1.5 sm:grid-cols-2">
                                @foreach ($checks[$inv->id] as $c)
                                    <li class="flex gap-2 rounded-lg px-2.5 py-1.5 text-xs {{ $c['ok'] ? 'bg-emerald-50 text-emerald-900' : 'bg-amber-50 text-amber-900' }}">
                                        <span class="font-bold">{{ $c['ok'] ? '✓' : '!' }}</span><span><span class="font-medium">{{ $c['label'] }}</span><span class="block opacity-80">{{ $c['detail'] }}</span></span>
                                    </li>
                                @endforeach
                            </ul>
                            @if ($inv->review_note)<p class="mt-2 text-xs text-slate-600"><span class="font-medium">{{ $inv->status === 'disputed' ? 'Dispute reason' : 'Note' }}:</span> {{ $inv->review_note }} <span class="text-slate-400">· {{ $inv->reviewer?->name }}</span></p>@endif

                            @if ($canEdit && $inv->status === 'submitted')
                                <form method="POST" action="{{ route('buyer.invoices.review', $inv->id) }}" class="mt-3 flex flex-wrap gap-2">
                                    @csrf
                                    <input name="review_note" maxlength="1000" class="{{ $field }} min-w-56 flex-1" placeholder="{{ collect($checks[$inv->id])->every('ok') ? 'Note (optional)' : 'Note (needed to approve with a failed check)' }}">
                                    <button name="decision" value="approve" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Approve</button>
                                    <button name="decision" value="dispute" class="rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">Dispute</button>
                                </form>
                            @elseif ($canEdit && $inv->status === 'approved')
                                <details class="mt-3">
                                    <summary class="cursor-pointer text-sm font-semibold text-emerald-700">Mark as paid</summary>
                                    <form method="POST" action="{{ route('buyer.invoices.paid', $inv->id) }}" class="mt-2 grid gap-2 sm:grid-cols-4 sm:items-end">
                                        @csrf
                                        <label class="text-xs text-slate-600">Paid on<input type="date" name="paid_on" required max="{{ $today }}" value="{{ $today }}" class="{{ $field }} mt-1"></label>
                                        <label class="text-xs text-slate-600">Amount (₹)<input name="paid_amount" inputmode="decimal" required value="{{ number_format((float) $inv->total_amount, 2, '.', '') }}" class="{{ $field }} mt-1"></label>
                                        <label class="text-xs text-slate-600">UTR / cheque no.<input name="payment_ref" maxlength="60" class="{{ $field }} mt-1"></label>
                                        <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Save payment</button>
                                    </form>
                                </details>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
            @if ($errors->hasAny(['review_note', 'paid_on', 'paid_amount']))
                <p class="border-t border-slate-100 px-5 py-3 text-sm text-red-600">{{ $errors->first('review_note') ?: ($errors->first('paid_on') ?: $errors->first('paid_amount')) }}</p>
            @endif
        </section>
    </div>
@endsection
