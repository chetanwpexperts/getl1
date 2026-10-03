@extends('layouts.app')

@section('title', 'Purchase orders')

@section('content')
    @php
        $inr = fn ($v) => \App\Support\Money::inr($v);
        $q = array_filter($filters, fn ($v) => $v !== null && $v !== '');
        $field = 'block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20';
        $has = $totals->n > 0;
    @endphp

    <x-page-header title="Purchase orders" subtitle="Every PO issued from GetL1, ready to export to Tally or Excel.">
        @if ($has)
            <a href="{{ route('buyer.orders.csv', $q) }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium shadow-sm hover:bg-slate-50">
                <x-icon name="download" class="size-4 text-slate-500" /> Excel (CSV)
            </a>
            <a href="#tally" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800">
                <x-icon name="download" class="size-4" /> Export to Tally
            </a>
        @endif
    </x-page-header>

    <form method="GET" class="mb-6 grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-5 sm:items-end">
        <div>
            <label for="from" class="block text-xs font-medium text-slate-600">PO date from</label>
            <input type="date" id="from" name="from" value="{{ $filters['from'] }}" class="{{ $field }} mt-1">
        </div>
        <div>
            <label for="to" class="block text-xs font-medium text-slate-600">To</label>
            <input type="date" id="to" name="to" value="{{ $filters['to'] }}" class="{{ $field }} mt-1">
        </div>
        <div>
            <label for="supplier" class="block text-xs font-medium text-slate-600">Supplier</label>
            <select id="supplier" name="supplier" class="{{ $field }} mt-1">
                <option value="">All suppliers</option>
                @foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected($filters['supplier'] === $s->id)>{{ $s->name }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="accepted" class="block text-xs font-medium text-slate-600">Supplier acceptance</label>
            <select id="accepted" name="accepted" class="{{ $field }} mt-1">
                <option value="">Any</option>
                <option value="yes" @selected($filters['accepted'] === 'yes')>Accepted</option>
                <option value="no" @selected($filters['accepted'] === 'no')>Not yet accepted</option>
            </select>
        </div>
        <div class="flex gap-2">
            <button class="flex-1 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Apply</button>
            @if ($q)<a href="{{ route('buyer.orders.index') }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50">Clear</a>@endif
        </div>
        @if ($errors->any())<p class="text-sm text-red-600 sm:col-span-5">{{ $errors->first() }}</p>@endif
    </form>

    <div class="mb-4 grid gap-4 sm:grid-cols-3">
        <x-stat-card label="Purchase orders" icon="orders" :value="number_format($totals->n)" />
        <x-stat-card label="Value before GST" icon="savings" tone="emerald" :value="$inr($totals->basic)" />
        <x-stat-card label="Value incl. GST" icon="billing" tone="sky" :value="$inr($totals->grand)" />
    </div>

    <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full min-w-[820px] text-sm">
            <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                <tr>
                    <th class="px-5 py-3.5">PO</th><th class="px-5 py-3.5">Supplier</th><th class="px-5 py-3.5">RFQ</th>
                    <th class="px-5 py-3.5 text-right">Before GST</th><th class="px-5 py-3.5 text-right">Incl. GST</th><th class="px-5 py-3.5">Status</th><th class="px-5 py-3.5"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($orders as $o)
                    <tr class="hover:bg-slate-50">
                        <td class="px-5 py-3.5">
                            <a href="{{ route('buyer.orders.show', $o->id) }}" class="font-medium hover:underline">{{ $o->po_number }}</a>
                            <span class="block text-xs text-slate-500">{{ $o->po_sent_at?->ist()->format('d M Y') }}{{ $o->source === 'auction' ? ' · live auction' : '' }}</span>
                        </td>
                        <td class="px-5 py-3.5">{{ $o->supplier?->name }}@if ($o->supplier?->gstin)<span class="block text-xs text-slate-500">{{ $o->supplier->gstin }}</span>@endif</td>
                        <td class="px-5 py-3.5"><a href="{{ route('buyer.rfqs.show', $o->rfq_id) }}#award" class="hover:underline">{{ $o->rfq?->ref_no }}</a><span class="block max-w-56 truncate text-xs text-slate-500">{{ $o->rfq?->title }}</span></td>
                        <td class="px-5 py-3.5 text-right tabular-nums">{{ $inr($o->total) }}</td>
                        <td class="px-5 py-3.5 text-right font-medium tabular-nums">{{ $inr($o->grand_total) }}</td>
                        <td class="px-5 py-3.5">
                            @if ($o->supplier_accepted_at)
                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800">Accepted</span>
                            @else
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900">Not yet accepted</span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-right"><a href="{{ route('buyer.orders.show', $o->id) }}" class="text-sm font-medium text-emerald-700 hover:underline">Open</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-12 text-center text-slate-600">{{ $q ? 'No purchase orders match these filters.' : 'No purchase orders yet. Award an RFQ and its PO appears here.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $orders->links() }}</div>

    @if ($has)
        <section id="tally" class="mt-10 scroll-mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="font-semibold">Export to Tally</h2>
                <p class="mt-0.5 text-sm text-slate-600">Works with TallyPrime and Tally.ERP 9. Exports the {{ number_format($totals->n) }} {{ $totals->n == 1 ? 'PO' : 'POs' }} shown above.</p>
            </div>
            <div class="grid gap-6 p-5 lg:grid-cols-2">
                <ol class="space-y-4 text-sm">
                    <li class="flex gap-3">
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-xs font-bold text-emerald-800">1</span>
                        <div>
                            <a href="{{ route('buyer.orders.tally.masters', $q) }}" class="font-semibold text-emerald-700 hover:underline">Download masters (suppliers, items, units)</a>
                            <p class="mt-0.5 text-slate-600">In Tally: <span class="font-medium">Import → Masters</span>, choose this file. Pick "Combine" or "Ignore duplicates" so existing ledgers stay as they are.</p>
                        </div>
                    </li>
                    <li class="flex gap-3">
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-xs font-bold text-emerald-800">2</span>
                        <div>
                            <a href="{{ route('buyer.orders.tally.vouchers', $q) }}" class="font-semibold text-emerald-700 hover:underline">Download purchase orders (vouchers)</a>
                            <p class="mt-0.5 text-slate-600">In Tally: <span class="font-medium">Import → Transactions</span>. Each PO comes in as a Purchase Order voucher with items, GST (CGST + SGST or IGST) and freight.</p>
                        </div>
                    </li>
                    <li class="flex gap-3">
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-bold text-slate-600">i</span>
                        <p class="text-slate-600">Inventory must be on in your Tally company (F11 features). The ledger names on the right must match yours; GST and purchase ledgers must already exist in Tally.</p>
                    </li>
                </ol>

                <form method="POST" action="{{ route('buyer.orders.tally.settings', $q) }}" class="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
                    @csrf
                    <h3 class="text-sm font-semibold">Your Tally ledger names</h3>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        @foreach (\App\Services\Exports\TallyExporter::LABELS as $key => [$label, $help])
                            <div>
                                <label for="tally_{{ $key }}" class="block text-xs font-medium text-slate-600" title="{{ $help }}">{{ $label }}</label>
                                <input id="tally_{{ $key }}" name="{{ $key }}" value="{{ old($key, $tally[$key]) }}" maxlength="90" @disabled(! $canEdit)
                                       placeholder="{{ \App\Services\Exports\TallyExporter::DEFAULTS[$key] }}" class="{{ $field }} mt-1 bg-white">
                                @error($key) <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                        @endforeach
                    </div>
                    @if ($canEdit)
                        <button class="mt-4 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold hover:bg-slate-50">Save ledger names</button>
                    @endif
                </form>
            </div>
        </section>
    @endif
@endsection
