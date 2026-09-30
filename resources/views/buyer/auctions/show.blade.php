@extends('layouts.app')

@section('title', 'Live auction · '.$rfq->ref_no)

@section('content')
    @php
        $cfg = [
            'role' => 'buyer',
            'stateUrl' => route('buyer.auctions.state', $auction->id),
            'channel' => "auction.{$auction->id}.buyer",
            'state' => $state,
        ];
        $canManage = in_array($currentRole?->value, ['buyer_admin', 'buyer_user'], true);
    @endphp

    <div data-auction="{{ json_encode($cfg) }}">
        <a href="{{ route('buyer.rfqs.show', $rfq->id) }}" class="text-sm text-slate-600 hover:text-slate-900">← {{ $rfq->ref_no }}</a>

        <div class="mt-2 flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-semibold">{{ $rfq->title }}</h1>
                    <span data-status="{{ $state['status'] }}" class="auction-status">{{ ucfirst($state['status']) }}</span>
                </div>
                <p class="mt-1 text-sm text-slate-600">
                    Live reverse auction · {{ $auction->starts_at->ist()->format('d M Y, h:i A') }} IST ·
                    suppliers see {{ $auction->visibility === 'rank_and_l1' ? 'their rank and the lowest price' : 'their rank only' }}
                </p>
            </div>
            <div class="text-right">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500" data-countdown-label>Ends in</p>
                <p class="auction-countdown tabular-nums" data-countdown>--:--</p>
                <p class="mt-1 text-xs"><span class="auction-connection" data-connection data-state="down">Connecting…</span></p>
            </div>
        </div>

        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-5">
                <p class="text-sm text-emerald-900">Current L1 (before GST)</p>
                <p class="mt-1 text-3xl font-semibold tabular-nums text-emerald-800" data-l1>—</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <p class="text-sm text-slate-600">Saved vs best sealed quote</p>
                <p class="mt-1 text-3xl font-semibold tabular-nums" data-savings>—</p>
                <p class="mt-1 text-xs text-slate-500">Start price <span data-start-price>—</span></p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <p class="text-sm text-slate-600">Live bids</p>
                <p class="mt-1 text-3xl font-semibold tabular-nums" data-bid-count>0</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <p class="text-sm text-slate-600">Extensions used</p>
                <p class="mt-1 text-3xl font-semibold tabular-nums" data-extensions>0</p>
            </div>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-3">
            <section class="rounded-xl border border-slate-200 bg-white lg:col-span-2">
                <h2 class="border-b border-slate-200 px-5 py-3 font-semibold">Standings</h2>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[560px] text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr><th class="px-4 py-3">Rank</th><th class="px-4 py-3">Supplier</th><th class="px-4 py-3 text-right">Price</th><th class="px-4 py-3 text-right">Bids</th><th class="px-4 py-3 text-right">Since</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100" data-standings></tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white">
                <h2 class="border-b border-slate-200 px-5 py-3 font-semibold">Bid feed</h2>
                <ul class="max-h-96 divide-y divide-slate-100 overflow-y-auto text-sm" data-recent></ul>
            </section>
        </div>

        @if ($canManage && $state['status'] === 'scheduled')
            <details class="mt-6 max-w-xl rounded-xl border border-slate-200 bg-white p-5 text-sm">
                <summary class="cursor-pointer text-red-700">Cancel this auction</summary>
                <form method="POST" action="{{ route('buyer.auctions.cancel', $auction->id) }}" class="mt-3 flex flex-wrap gap-2" data-confirm="Cancel the scheduled auction?">
                    @csrf
                    <input name="reason" required minlength="5" maxlength="255" placeholder="Reason" class="min-w-60 flex-1 rounded-lg border border-slate-300 px-3 py-2">
                    <button class="rounded-lg border border-red-300 px-3 py-2 font-medium text-red-700 hover:bg-red-50">Cancel auction</button>
                </form>
                @error('reason') <p class="mt-2 text-red-600">{{ $message }}</p> @enderror
            </details>
        @endif

        <p class="mt-6 text-xs text-slate-500">
            Times are server time (IST). Every bid is recorded with time, user and IP and can't be edited.
            Rules: minimum drop {{ $auction->min_decrement_type === 'percent' ? rtrim(rtrim((string) $auction->min_decrement_value, '0'), '.').'%' : \App\Support\Money::inr($auction->min_decrement_value) }},
            typo guard {{ rtrim(rtrim((string) $auction->max_decrement_pct, '0'), '.') }}%,
            auto-extend {{ $auction->extend_window_sec ? ($auction->extend_by_sec / 60).' min when bid in last '.($auction->extend_window_sec / 60).' min, up to '.$auction->max_extensions.' times' : 'off' }}.
        </p>
    </div>
@endsection
