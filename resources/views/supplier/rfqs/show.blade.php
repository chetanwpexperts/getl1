@extends('layouts.app')

@section('title', $rfq->ref_no)

@section('content')
    @php
        $open = $rfq->isOpenForQuotes();
        $accepted = $invite->status->value === 'accepted';
        $declined = $invite->status->value === 'declined';
        $deadline = $rfq->quote_deadline?->ist()->format('d M Y, h:i A');
        $qtyFmt = fn ($q) => rtrim(rtrim(number_format((float) $q, 3, '.', ','), '0'), '.');
        $quoteLines = $quote?->items->keyBy('rfq_item_id') ?? collect();
        $cls = 'block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20';
    @endphp

    <x-live-page :url="route('supplier.rfqs.show.live', $invite->id)" :live="$live" />
    <nav class="flex items-center gap-1.5 text-sm text-slate-500" aria-label="Breadcrumb">
        <a href="{{ route('supplier.rfqs.index') }}" class="hover:text-slate-900">RFQs &amp; auctions</a>
        <x-icon name="right" class="size-3.5 text-slate-400" />
        <span class="text-slate-700">{{ $rfq->ref_no }}</span>
    </nav>

    <div class="mt-2 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">{{ $rfq->title }}</h1>
            <p class="mt-1 text-sm text-slate-600">
                {{ $rfq->ref_no }} · <span class="font-medium text-slate-800">{{ $rfq->organization->name }}</span>, {{ $rfq->organization->city }}
            </p>
        </div>
        <div class="rounded-xl border px-4 py-2 text-sm {{ $open ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-slate-200 bg-white text-slate-700' }}">
            @if ($rfq->isCancelled()) Cancelled by the buyer
            @elseif ($open) Quote by <span class="font-semibold">{{ $deadline }} IST</span>
                <span class="block text-xs">Closes in <span class="font-semibold tabular-nums" data-countdown-to="{{ $rfq->quote_deadline->getTimestampMs() }}"></span></span>
            @else Quotes closed on {{ $deadline }} IST
            @endif
        </div>
    </div>

    @error('rfq') <p class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</p> @enderror

    @if ($order)
        <div class="mt-6 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-emerald-600 bg-emerald-600 p-4 text-sm text-white">
            <div>
                <p class="font-semibold">You won this order: purchase order {{ $order->po_number }}</p>
                <p class="mt-0.5 text-emerald-50">{{ \App\Support\Money::inr($order->grand_total) }} incl. GST · {{ $order->supplier_accepted_at ? 'accepted' : 'please review and accept' }}</p>
            </div>
            <a href="{{ route('supplier.orders.show', $order->id) }}" class="rounded-lg bg-white px-4 py-2 font-semibold text-emerald-800 hover:bg-emerald-50">{{ $order->supplier_accepted_at ? 'View order' : 'Review and accept' }}</a>
        </div>
    @endif

    @if ($auction)
        @php
            $aStatus = \App\Services\Auction\Standings::effectiveStatus($auction)->value;
        @endphp
        <div class="mt-6 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
            <div>
                <p class="font-semibold">
                    @if ($aStatus === 'scheduled') You're invited to a live auction on {{ $auction->starts_at->ist()->format('d M Y, h:i A') }} IST (starts in <span class="tabular-nums" data-countdown-to="{{ $auction->starts_at->getTimestampMs() }}"></span>)
                    @elseif ($aStatus === 'live') The live auction is running now
                    @else The live auction has closed
                    @endif
                </p>
                <p class="mt-1">
                    @if ($aStatus === 'closed') See your final rank and price. The buyer will now award the order.
                    @else You start at your sealed quote. Other suppliers never see your name or price.
                    @endif
                </p>
            </div>
            <a href="{{ route('supplier.auctions.show', $auction->id) }}" class="rounded-lg bg-emerald-700 px-4 py-2 font-semibold text-white hover:bg-emerald-800">
                {{ $aStatus === 'live' ? 'Join auction' : ($aStatus === 'scheduled' ? 'Open auction room' : 'View your result') }}
            </a>
        </div>
    @endif

    <div class="mt-8 grid gap-8 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- Accept / decline --}}
            @if ($open && ! $accepted && ! $declined)
                <section class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5">
                    <h2 class="font-semibold">Will you quote?</h2>
                    <p class="mt-1 text-sm text-slate-600">Review the items and terms, then accept to submit your price. Your quote is sealed: the buyer sees it only after the deadline, and other suppliers never see it.</p>
                    <form method="POST" action="{{ route('supplier.rfqs.accept', $invite->id) }}" class="mt-4 space-y-3">
                        @csrf
                        <label class="flex items-start gap-2 text-sm">
                            <input type="checkbox" name="agree" value="1" class="mt-0.5 rounded border-slate-300" required>
                            I agree to the buyer's terms shown on this page and confirm our prices will be genuine.
                        </label>
                        @error('agree') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                        <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Accept and quote</button>
                    </form>
                    <details class="mt-4 text-sm">
                        <summary class="cursor-pointer text-slate-600">Not interested?</summary>
                        <form method="POST" action="{{ route('supplier.rfqs.decline', $invite->id) }}" class="mt-2 flex flex-wrap gap-2" data-confirm="Decline this RFQ?">
                            @csrf
                            <input name="reason" maxlength="255" placeholder="Reason (optional)" class="{{ $cls }} flex-1">
                            <button class="rounded-lg border border-slate-300 px-3 py-2 text-slate-700 hover:bg-slate-50">Decline</button>
                        </form>
                    </details>
                </section>
            @elseif ($declined)
                <p class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5 text-sm text-slate-700">You declined this RFQ.</p>
            @elseif (! $open && ! $quote && ! $rfq->isCancelled())
                <p class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
                    You didn't submit a quote before the deadline, so you're not part of this RFQ's comparison or auction.
                </p>
            @endif

            {{-- Quote form (accepted & open) or read-only items --}}
            <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-5 py-3">
                    <h2 class="font-semibold">{{ $accepted && $open ? 'Your quote' : 'Items' }}</h2>
                    @if ($quote)<span class="text-xs text-slate-500">Last submitted {{ $quote->submitted_at?->ist()->format('d M Y, h:i A') }} IST</span>@endif
                </div>

                @if ($accepted && $open)
                    @php
                        $freightIncluded = ($rfq->terms['freight'] ?? null) === 'included';
                    @endphp
                    <form method="POST" action="{{ route('supplier.rfqs.quote', $invite->id) }}" data-quote-form>
                        @csrf
                        @error('items') <p class="mx-5 mt-3 text-sm text-red-600">{{ $message }}</p> @enderror
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[640px] text-sm">
                                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th class="px-4 py-2">Item</th><th class="px-4 py-2 text-right">Qty</th><th class="px-4 py-2">Unit price (₹, ex-GST)</th><th class="px-4 py-2">GST</th>
                                        @unless ($freightIncluded)<th class="px-4 py-2">Freight, total for line (₹)</th>@endunless
                                        <th class="px-4 py-2 text-right">Amount (ex-GST)</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach ($rfq->items as $item)
                                        @php $line = $quoteLines[$item->id] ?? null; @endphp
                                        <tr data-quote-row data-qty="{{ (float) $item->qty }}">
                                            <td class="px-4 py-2">{{ $item->name }}@if ($item->spec)<span class="block text-xs text-slate-500">{{ $item->spec }}</span>@endif</td>
                                            <td class="px-4 py-2 text-right tabular-nums">{{ $qtyFmt($item->qty) }} {{ $item->unit }}</td>
                                            <td class="px-4 py-2">
                                                <input name="items[{{ $item->id }}][unit_price]" inputmode="decimal" required data-price
                                                       value="{{ old("items.{$item->id}.unit_price", $line?->unit_price) }}" class="{{ $cls }} w-32">
                                                @error("items.{$item->id}.unit_price") <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                            </td>
                                            <td class="px-4 py-2">
                                                @php $gst = (string) old("items.{$item->id}.gst_rate", $line ? rtrim(rtrim((string) $line->gst_rate, '0'), '.') : '18'); @endphp
                                                <select name="items[{{ $item->id }}][gst_rate]" class="{{ $cls }} w-24" data-gst>
                                                    @foreach ($gstRates as $r)<option value="{{ $r }}" @selected($gst === $r)>{{ $r }}%</option>@endforeach
                                                </select>
                                            </td>
                                            @unless ($freightIncluded)
                                                <td class="px-4 py-2">
                                                    <input name="items[{{ $item->id }}][freight]" inputmode="decimal" data-freight
                                                           value="{{ old("items.{$item->id}.freight", $line && (float) $line->freight > 0 ? $line->freight : '') }}" class="{{ $cls }} w-28" placeholder="0">
                                                </td>
                                            @endunless
                                            <td class="px-4 py-2 text-right font-medium tabular-nums" data-line-amount>—</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <dl class="ml-auto grid max-w-sm grid-cols-2 gap-x-6 gap-y-1 border-t border-slate-100 px-5 py-4 text-sm tabular-nums">
                            <dt class="text-slate-600">Total before GST</dt><dd class="text-right text-base font-semibold" data-sum-basic>—</dd>
                            <dt class="text-slate-600">GST</dt><dd class="text-right" data-sum-gst>—</dd>
                            @unless ($freightIncluded)<dt class="text-slate-600">Freight</dt><dd class="text-right" data-sum-freight>—</dd>@endunless
                            <dt class="border-t border-slate-200 pt-1 font-medium">Landed cost</dt><dd class="border-t border-slate-200 pt-1 text-right font-semibold" data-sum-landed>—</dd>
                            <dd class="col-span-2 mt-1 text-xs text-slate-500">The total before GST is your quote price{{ $freightIncluded ? ', with freight included as the buyer asked' : '' }}. If there's a live auction, you start from it.</dd>
                        </dl>
                        <div class="grid gap-4 border-t border-slate-100 px-5 py-4 sm:grid-cols-3">
                            <div>
                                <label for="valid_till" class="block text-sm font-medium text-slate-700">Quote valid till</label>
                                <input id="valid_till" type="date" name="valid_till" required class="{{ $cls }} mt-1"
                                       value="{{ old('valid_till', $quote?->valid_till?->format('Y-m-d') ?? now()->addDays(15)->format('Y-m-d')) }}">
                                @error('valid_till') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div class="sm:col-span-2">
                                <label for="notes" class="block text-sm font-medium text-slate-700">Notes for the buyer (optional)</label>
                                <input id="notes" name="notes" maxlength="2000" value="{{ old('notes', $quote?->notes) }}" class="{{ $cls }} mt-1" placeholder="Delivery time, brand, make…">
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-3 border-t border-slate-100 px-5 py-4">
                            <button class="rounded-lg bg-emerald-700 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-800">{{ $quote ? 'Update sealed quote' : 'Submit sealed quote' }}</button>
                            @if ($quote)<span class="text-sm text-slate-600">Current total (ex-GST): <span class="font-semibold">{{ \App\Support\Money::inr($quote->total) }}</span></span>@endif
                        </div>
                    </form>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[480px] text-sm">
                            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                <tr><th class="px-4 py-2">Item</th><th class="px-4 py-2 text-right">Qty</th><th class="px-4 py-2">Needed by</th>@if ($quote)<th class="px-4 py-2 text-right">{{ $auction ? 'Your sealed quote' : 'Your price' }}</th>@endif</tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($rfq->items as $item)
                                    <tr>
                                        <td class="px-4 py-2">{{ $item->name }}@if ($item->spec)<span class="block text-xs text-slate-500">{{ $item->spec }}</span>@endif</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ $qtyFmt($item->qty) }} {{ $item->unit }}</td>
                                        <td class="px-4 py-2">{{ $item->delivery_date?->format('d M Y') ?? '—' }}</td>
                                        @if ($quote)<td class="px-4 py-2 text-right tabular-nums">{{ isset($quoteLines[$item->id]) ? \App\Support\Money::inr($quoteLines[$item->id]->unit_price) : '—' }}</td>@endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($quote)<p class="border-t border-slate-100 px-5 py-3 text-sm">{{ $auction ? 'Your sealed quote total' : 'Your total' }} (ex-GST): <span class="font-semibold">{{ \App\Support\Money::inr($quote->total) }}</span>@if ($auction) <span class="text-slate-500">· your auction bids are in the auction room</span>@endif</p>@endif
                @endif
            </section>
        </div>

        <div class="space-y-6">
            <section class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5 text-sm">
                <h2 class="font-semibold">Buyer's terms</h2>
                <dl class="mt-3 space-y-2">
                    <div><dt class="text-xs text-slate-500">Payment</dt><dd>{{ $paymentTerms[$rfq->terms['payment'] ?? ''] ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Freight</dt><dd>{{ $freightTerms[$rfq->terms['freight'] ?? ''] ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Delivery</dt><dd>{{ $rfq->terms['delivery'] ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Delivery location</dt><dd>{{ $rfq->delivery_location ?? '—' }}</dd></div>
                    @if (! empty($rfq->terms['other']))<div><dt class="text-xs text-slate-500">Other</dt><dd class="whitespace-pre-line">{{ $rfq->terms['other'] }}</dd></div>@endif
                    @if ($rfq->description)<div><dt class="text-xs text-slate-500">Details</dt><dd class="whitespace-pre-line">{{ $rfq->description }}</dd></div>@endif
                </dl>
            </section>

            @if ($rfq->attachments->isNotEmpty())
                <section class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5 text-sm">
                    <h2 class="font-semibold">Attachments</h2>
                    <ul class="mt-3 space-y-2">
                        @foreach ($rfq->attachments as $att)
                            <li><a href="{{ route('supplier.rfqs.attachments.download', [$invite->id, $att->id]) }}" class="text-emerald-700 hover:underline">{{ $att->original_name }}</a></li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    </div>
@endsection
