@extends('layouts.app')

@section('title', $award->po_number)

@section('content')
    @php
        $inr = fn ($v) => \App\Support\Money::inr($v);
        $qty = fn ($q) => rtrim(rtrim(number_format((float) $q, 3, '.', ','), '0'), '.');
        $rate = fn ($v) => '₹'.number_format((float) $v, fmod((float) $v * 100, 1) != 0 ? 4 : 2);
    @endphp
    <x-breadcrumb :items="[['Purchase orders', route('supplier.orders.index')]]" :current="$award->po_number" />

    <div class="flex flex-wrap items-start justify-between gap-4">
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
            <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
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

    @php
        $field = 'block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20';
        $statusText = ['submitted' => 'Sent, waiting for review', 'approved' => 'Approved', 'paid' => 'Paid', 'disputed' => 'Needs correction'];
        $statusBadge = ['submitted' => 'bg-amber-100 text-amber-900', 'approved' => 'bg-sky-100 text-sky-800', 'paid' => 'bg-emerald-100 text-emerald-800', 'disputed' => 'bg-red-50 text-red-700'];
        $today = now()->setTimezone(config('app.display_timezone'))->toDateString();
    @endphp

    <div class="mt-8 grid gap-6 lg:grid-cols-5">
        <section class="rounded-2xl border border-slate-200 bg-white shadow-sm lg:col-span-2">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="font-semibold">Deliveries</h2>
                <p class="mt-0.5 text-xs text-slate-500">What {{ $buyer->name }} has received and accepted.</p>
            </div>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($summary['lines'] as $l)
                    <li class="flex justify-between gap-3 px-5 py-2.5">
                        <span>{{ $l['name'] }}</span>
                        <span class="text-right tabular-nums text-xs"><span class="text-emerald-700">{{ $qty($l['accepted']) }}</span> / {{ $qty($l['ordered']) }} {{ $l['unit'] }} accepted
                            @if ($l['rejected'] > 0)<span class="block text-red-700">{{ $qty($l['rejected']) }} rejected</span>@endif</span>
                    </li>
                @endforeach
            </ul>
            @foreach ($receipts as $r)
                @foreach (collect($r->lines)->where('rejected', '>', 0) as $l)
                    <p class="border-t border-slate-100 px-5 py-2 text-xs text-red-700">{{ $r->received_on->format('d M') }} · {{ $l['name'] }}: {{ $qty($l['rejected']) }} rejected ({{ $l['reason'] }})</p>
                @endforeach
            @endforeach
        </section>

        <section id="invoices" class="scroll-mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm lg:col-span-3">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="font-semibold">Your invoices</h2>
                <p class="mt-0.5 text-xs text-slate-500">Upload your GST invoice here instead of emailing it. You'll see when it's approved and paid.</p>
            </div>
            @foreach ($invoices as $inv)
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-5 py-3 text-sm">
                    <div>
                        <p class="font-medium">{{ $inv->invoice_number }} <span class="font-normal text-slate-500">· {{ $inv->invoice_date->format('d M Y') }}</span></p>
                        <p class="tabular-nums">{{ $inr($inv->total_amount) }}</p>
                        <a href="{{ route('supplier.orders.invoices.file', [$award->id, $inv->id]) }}" class="text-xs text-emerald-700 hover:underline">{{ $inv->original_name }}</a>
                        @if ($inv->status === 'disputed' && $inv->review_note)<p class="mt-1 text-xs text-red-700">Reason: {{ $inv->review_note }}</p>@endif
                    </div>
                    <div class="text-right">
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $statusBadge[$inv->status] }}">{{ $statusText[$inv->status] }}</span>
                        @if ($inv->status === 'paid')
                            <p class="mt-1 text-xs text-slate-600">{{ $inr($inv->paid_amount) }} on {{ $inv->paid_on->format('d M Y') }}{{ $inv->payment_ref ? ' · '.$inv->payment_ref : '' }}</p>
                        @elseif ($inv->due_date && $inv->status !== 'disputed')
                            <p class="mt-1 text-xs text-slate-600">Payment due by {{ $inv->due_date->format('d M Y') }}</p>
                            @if ($inv->is_msme)<p class="text-[11px] text-violet-700">MSME: within 45 days of acceptance</p>@endif
                        @endif
                    </div>
                </div>
            @endforeach

            @if ($award->supplier_accepted_at)
                <form method="POST" action="{{ route('supplier.orders.invoices.store', $award->id) }}" enctype="multipart/form-data" class="space-y-3 px-5 py-4">
                    @csrf
                    <h3 class="text-sm font-semibold">{{ $invoices->isEmpty() ? 'Upload invoice' : 'Upload another invoice' }}</h3>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="text-xs text-slate-600">Invoice number<input name="invoice_number" required maxlength="16" value="{{ old('invoice_number') }}" class="{{ $field }} mt-1 uppercase" placeholder="e.g. INV/26-27/045"></label>
                        <label class="text-xs text-slate-600">Invoice date<input type="date" name="invoice_date" required max="{{ $today }}" value="{{ old('invoice_date', $today) }}" class="{{ $field }} mt-1"></label>
                        <label class="text-xs text-slate-600">Taxable value (₹)<input name="taxable_amount" inputmode="decimal" required value="{{ old('taxable_amount') }}" class="{{ $field }} mt-1"></label>
                        <label class="text-xs text-slate-600">GST (₹)<input name="gst_amount" inputmode="decimal" required value="{{ old('gst_amount') }}" class="{{ $field }} mt-1"></label>
                        <label class="text-xs text-slate-600">Invoice total (₹)<input name="total_amount" inputmode="decimal" required value="{{ old('total_amount') }}" class="{{ $field }} mt-1"></label>
                        <label class="text-xs text-slate-600">Your GSTIN on the invoice<input name="supplier_gstin" maxlength="15" value="{{ old('supplier_gstin', $currentOrg->gstin) }}" class="{{ $field }} mt-1 uppercase"></label>
                    </div>
                    <label class="block text-xs text-slate-600">Invoice file (PDF, JPG or PNG, up to 10 MB)
                        <input type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" class="mt-1 block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium hover:file:bg-slate-200">
                    </label>
                    @if ($errors->any())<p class="text-sm text-red-600">{{ $errors->first() }}</p>@endif
                    <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Send invoice</button>
                    @if (! $currentOrg->udyam_no)
                        <p class="text-xs text-slate-500">MSME? <a href="{{ route('company.edit') }}" class="font-medium text-emerald-700 hover:underline">Add your Udyam number</a> to your company profile, so buyers see your 45-day payment due date.</p>
                    @endif
                </form>
            @else
                <p class="px-5 py-4 text-sm text-slate-600">Accept the order above, then upload your invoice here.</p>
            @endif
        </section>
    </div>
@endsection
