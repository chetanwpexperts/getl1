{{-- Negotiate before awarding (lot RFQs): one open counter-offer at a time, plus history. --}}
@php
    $inr = fn ($v) => \App\Support\Money::inr($v);
    $open = $offers->first(fn ($o) => $o->isOpen());
    $field = 'block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20';
    $badge = ['pending' => 'bg-amber-100 text-amber-800', 'expired' => 'bg-slate-100 text-slate-600', 'accepted' => 'bg-emerald-100 text-emerald-800',
              'declined' => 'bg-red-50 text-red-700', 'withdrawn' => 'bg-slate-100 text-slate-600'];
    $l1 = $candidates->first();
@endphp

<section id="counter-offer" class="mt-6 scroll-mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="border-b border-slate-200 px-5 py-4">
        <h2 class="font-semibold">Negotiate before awarding</h2>
        <p class="mt-0.5 text-sm text-slate-600">Send one supplier a counter-offer. If they accept, it becomes their price in the list above and on the PO.</p>
    </div>

    @if ($open)
        <div class="flex flex-wrap items-start justify-between gap-4 bg-amber-50/60 px-5 py-4 text-sm">
            <div>
                <p class="font-medium">Waiting for {{ $open->supplier?->name }}</p>
                <p class="mt-0.5 text-slate-700">{{ $inr($open->offered_amount) }} offered (was {{ $inr($open->current_amount) }}, {{ number_format($open->savingPct(), 2) }}% lower) · open until {{ $open->expires_at->ist()->format('d M, h:i A') }} IST</p>
                @if ($open->message)<p class="mt-1 text-slate-600">“{{ $open->message }}”</p>@endif
            </div>
            <form method="POST" action="{{ route('buyer.rfqs.offers.withdraw', [$rfq->id, $open->id]) }}" data-confirm="Withdraw this counter-offer?">
                @csrf
                <button class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium hover:bg-slate-50">Withdraw</button>
            </form>
        </div>
    @else
        <form method="POST" action="{{ route('buyer.rfqs.offers.store', $rfq->id) }}" class="grid gap-4 px-5 py-4 sm:grid-cols-4 sm:items-end"
              data-confirm="Send this counter-offer? The supplier is emailed straight away.">
            @csrf
            <div class="sm:col-span-2">
                <label for="co_supplier" class="block text-xs font-medium text-slate-600">Supplier</label>
                <select id="co_supplier" name="supplier_org_id" class="{{ $field }} mt-1">
                    @foreach ($candidates as $c)
                        <option value="{{ $c['supplier']->id }}" @selected((int) old('supplier_org_id', $l1['supplier']->id) === $c['supplier']->id)>L{{ $c['rank'] }} · {{ $c['supplier']->name }} · {{ $inr($c['basic']) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="co_amount" class="block text-xs font-medium text-slate-600">Your offer (₹, before GST)</label>
                <input id="co_amount" name="offered_amount" inputmode="decimal" required value="{{ old('offered_amount') }}" class="{{ $field }} mt-1" placeholder="e.g. {{ number_format(floor((float) $l1['basic'] * 0.98), 0, '.', '') }}">
            </div>
            <div>
                <label for="co_hours" class="block text-xs font-medium text-slate-600">Open for</label>
                <select id="co_hours" name="hours" class="{{ $field }} mt-1">
                    @foreach (\App\Services\CounterOfferService::HOURS as $h)
                        <option value="{{ $h }}" @selected((int) old('hours', 24) === $h)>{{ $h }} hours</option>
                    @endforeach
                </select>
            </div>
            <div class="sm:col-span-3">
                <label for="co_message" class="block text-xs font-medium text-slate-600">Message (optional)</label>
                <input id="co_message" name="message" maxlength="1000" value="{{ old('message') }}" class="{{ $field }} mt-1" placeholder="e.g. We can confirm the order today at this price.">
            </div>
            <button class="rounded-lg border border-emerald-600 bg-white px-4 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-50">Send counter-offer</button>
            @if ($errors->hasAny(['offered_amount', 'hours', 'supplier_org_id']))
                <p class="text-sm text-red-600 sm:col-span-4">{{ $errors->first('offered_amount') ?: ($errors->first('hours') ?: $errors->first('supplier_org_id')) }}</p>
            @endif
        </form>
    @endif

    @php $past = $offers->reject(fn ($o) => $open && $o->is($open)); @endphp
    @if ($past->isNotEmpty())
        <ul class="divide-y divide-slate-100 border-t border-slate-100 text-sm">
            @foreach ($past as $o)
                @php $st = $o->displayStatus(); @endphp
                <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-2.5">
                    <span>{{ $o->supplier?->name }} · {{ $inr($o->offered_amount) }} <span class="text-slate-500">(was {{ $inr($o->current_amount) }})</span>
                        @if ($o->response_note)<span class="block text-xs text-slate-500">“{{ $o->response_note }}”</span>@endif</span>
                    <span class="flex items-center gap-2 text-xs text-slate-500">{{ $o->created_at->ist()->format('d M, h:i A') }}
                        <span class="rounded-full px-2 py-0.5 font-medium {{ $badge[$st] }}">{{ ucfirst($st) }}</span></span>
                </li>
            @endforeach
        </ul>
    @endif
</section>
