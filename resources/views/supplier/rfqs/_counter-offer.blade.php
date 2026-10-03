{{-- A buyer's counter-offer: accept or decline before it expires. --}}
@php
    $inr = fn ($v) => \App\Support\Money::inr($v);
    $open = $offers->first(fn ($o) => $o->isOpen());
    $last = $open ?? $offers->first();
@endphp

@if ($open)
    <section id="counter-offer" class="mt-6 scroll-mt-6 rounded-2xl border-2 border-emerald-600 bg-white p-5 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Counter-offer from {{ $rfq->organization->name }}</p>
        <div class="mt-2 flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-3xl font-bold tabular-nums">{{ $inr($open->offered_amount) }} <span class="text-base font-medium text-slate-500">before GST</span></p>
                <p class="mt-1 text-sm text-slate-600">Your current price {{ $inr($open->current_amount) }} · {{ number_format($open->savingPct(), 2) }}% lower · answer by
                    <span class="font-medium text-slate-800">{{ $open->expires_at->ist()->format('d M, h:i A') }} IST</span>
                    (<span class="tabular-nums" data-countdown-to="{{ $open->expires_at->getTimestampMs() }}"></span> left)</p>
                @if ($open->message)<p class="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">“{{ $open->message }}”</p>@endif
            </div>
        </div>
        <form method="POST" action="{{ route('supplier.rfqs.offers.respond', [$invite->id, $open->id]) }}" class="mt-4 flex flex-wrap items-center gap-3">
            @csrf
            <input name="note" maxlength="1000" placeholder="Note to the buyer (optional)" class="min-w-60 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20">
            <button name="decision" value="accept" class="rounded-lg bg-emerald-700 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-800"
                    data-confirm-click="Accept {{ $inr($open->offered_amount) }} as your price for this RFQ?">Accept {{ $inr($open->offered_amount) }}</button>
            <button name="decision" value="decline" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold hover:bg-slate-50">Decline</button>
        </form>
        @error('offer') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
        <p class="mt-3 text-xs text-slate-500">If you accept, this becomes your price for the award. If you decline or don't answer, your current price stays.</p>
    </section>
@elseif ($last)
    <p id="counter-offer" class="mt-6 rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm text-slate-600 shadow-sm">
        Counter-offer of {{ $inr($last->offered_amount) }}: <span class="font-medium">{{ ['accepted' => 'you accepted it', 'declined' => 'you declined it', 'expired' => 'expired without an answer', 'withdrawn' => 'withdrawn by the buyer'][$last->displayStatus()] ?? $last->displayStatus() }}</span>.
    </p>
@endif
