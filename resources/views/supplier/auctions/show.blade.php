@extends('layouts.app')

@section('title', 'Live auction · '.$rfq->ref_no)

@section('content')
    @php
        $cfg = [
            'role' => 'supplier',
            'stateUrl' => route('supplier.auctions.state', $auction->id),
            'bidUrl' => route('supplier.auctions.bid', $auction->id),
            'channel' => "auction.{$auction->id}.supplier.{$currentOrg->id}",
            'state' => $state,
        ];
    @endphp

    <div data-auction="{{ json_encode($cfg) }}">
        <a href="{{ route('supplier.rfqs.index') }}" class="text-sm text-slate-600 hover:text-slate-900">← RFQs</a>

        <div class="mt-2 flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-semibold">{{ $rfq->title }}</h1>
                    <span data-status="{{ $state['status'] }}" class="auction-status">{{ ucfirst($state['status']) }}</span>
                </div>
                <p class="mt-1 text-sm text-slate-600">{{ $rfq->ref_no }} · {{ $rfq->organization->name }} · <span data-participants>{{ $state['participants'] }}</span> suppliers bidding</p>
            </div>
            <div class="text-right">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500" data-countdown-label>Ends in</p>
                <p class="auction-countdown tabular-nums" data-countdown>--:--</p>
                <p class="mt-1 text-xs"><span class="auction-connection" data-connection data-state="down">Connecting…</span></p>
            </div>
        </div>

        <div class="mt-6 grid gap-4 sm:grid-cols-3">
            <div class="auction-rank rounded-xl border p-5 text-center" data-rank-badge data-rank="other">
                <p class="text-sm">Your rank</p>
                <p class="mt-1 text-5xl font-bold tabular-nums" data-my-rank>—</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <p class="text-sm text-slate-600">Your current price (before GST)</p>
                <p class="mt-1 text-3xl font-semibold tabular-nums" data-my-amount>—</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <p class="text-sm text-slate-600">Lowest price (L1)</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums" data-l1>—</p>
            </div>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-3">
            <section class="rounded-xl border border-slate-200 bg-white p-5 lg:col-span-2">
                <h2 class="font-semibold">Place a bid</h2>
                <p class="mt-1 text-sm text-slate-600">Total for the whole RFQ, before GST. Bid at most <span class="font-semibold" data-max-next>—</span> (at least <span data-min-dec>—</span> below your price).</p>

                <p class="mt-4 rounded-lg bg-slate-50 p-3 text-sm text-slate-700" data-bid-waiting hidden></p>

                <form class="mt-4" data-bid-form hidden novalidate>
                    <div class="flex flex-wrap gap-2">
                        <div class="relative min-w-52 flex-1">
                            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-500">₹</span>
                            <input name="amount" inputmode="decimal" autocomplete="off" required placeholder="New total price"
                                   class="block w-full rounded-lg border border-slate-300 py-2.5 pl-7 pr-3 text-lg tabular-nums focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20">
                        </div>
                        <button class="rounded-lg bg-emerald-700 px-5 py-2.5 font-semibold text-white hover:bg-emerald-800">Review bid</button>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2 text-xs">
                        <span class="self-center text-slate-500">Quick:</span>
                        @foreach ([0.5, 1, 2] as $p)
                            <button type="button" data-quick-drop="{{ $p }}" class="rounded-full border border-slate-300 px-3 py-1 hover:bg-slate-50">−{{ $p }}%</button>
                        @endforeach
                    </div>
                </form>

                <div class="mt-4 rounded-xl border-2 border-emerald-600 bg-emerald-50 p-4" data-bid-confirm hidden>
                    <p class="text-sm text-emerald-900">Confirm your bid</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-emerald-900" data-confirm-amount></p>
                    <p class="text-sm text-emerald-800" data-confirm-drop></p>
                    <div class="mt-3 flex gap-2">
                        <button type="button" data-bid-submit class="rounded-lg bg-emerald-700 px-5 py-2 font-semibold text-white hover:bg-emerald-800">Confirm bid</button>
                        <button type="button" data-bid-cancel class="rounded-lg border border-slate-300 bg-white px-4 py-2 hover:bg-slate-50">Change</button>
                    </div>
                    <p class="mt-2 text-xs text-emerald-900">Bids are final and can't be withdrawn.</p>
                </div>

                <p class="auction-message mt-3 text-sm" data-bid-message role="status" aria-live="polite"></p>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white">
                <h2 class="border-b border-slate-200 px-5 py-3 font-semibold">Your bids</h2>
                <ul class="max-h-80 divide-y divide-slate-100 overflow-y-auto text-sm" data-my-bids></ul>
                <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500">Extensions used: <span data-extensions>0</span></p>
            </section>
        </div>

        <p class="mt-6 text-xs text-slate-500">
            Other suppliers can't see your name or prices. Times follow the GetL1 server clock (IST).
            @if ($auction->extend_window_sec)
                A bid in the last {{ $auction->extend_window_sec / 60 }} min extends the auction by {{ $auction->extend_by_sec / 60 }} min (up to {{ $auction->max_extensions }} times).
            @endif
        </p>
    </div>
@endsection
