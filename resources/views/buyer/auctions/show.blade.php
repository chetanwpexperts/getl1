@extends('layouts.app')

@section('title', 'Live auction · '.$rfq->ref_no)

@section('content')
    <p data-notice role="status" class="mb-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900" @if (empty($state['notice'])) hidden @endif>{{ $state['notice'] ?? '' }}</p>
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
                    <h1 class="text-2xl font-semibold tracking-tight">{{ $rfq->title }}</h1>
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

        <div data-show-when="closed" @if ($state['status'] !== 'closed') hidden @endif
             class="mt-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-900">
            <p><span class="font-semibold">Auction closed.</span> Award the order and the purchase order goes to the supplier automatically.</p>
            <a href="{{ route('buyer.rfqs.show', $rfq->id) }}#award" class="rounded-lg bg-emerald-700 px-4 py-2 font-semibold text-white hover:bg-emerald-800">Award this RFQ</a>
        </div>

        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-5">
                <p class="text-sm text-emerald-900">{{ ($state['basis'] ?? 'lot_total') === 'per_item' ? 'Best total, each item at its L1' : 'Current L1' }} (before GST)</p>
                <p class="mt-1 text-3xl font-semibold tabular-nums text-emerald-800" data-l1>—</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5">
                <p class="text-sm text-slate-600">Saved vs best sealed quote</p>
                <p class="mt-1 text-3xl font-semibold tabular-nums" data-savings>—</p>
                <p class="mt-1 text-xs text-slate-500">Start price <span data-start-price>—</span></p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5">
                <p class="text-sm text-slate-600">Live bids</p>
                <p class="mt-1 text-3xl font-semibold tabular-nums" data-bid-count>0</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5">
                <p class="text-sm text-slate-600">Extensions used</p>
                <p class="mt-1 text-3xl font-semibold tabular-nums" data-extensions>0</p>
            </div>
        </div>

        @if (($state['basis'] ?? 'lot_total') === 'per_item')
            <section class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-6 py-4">
                    <h2 class="font-semibold">Item by item</h2>
                    <span class="text-xs text-slate-500">L1 above is the total if every item goes to its own L1.</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[760px] text-sm">
                        <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                            <tr><th class="px-5 py-3.5">Item</th><th class="px-5 py-3.5">L1 supplier</th><th class="px-5 py-3.5 text-right">L1 rate</th><th class="px-5 py-3.5 text-right">Line total</th><th class="px-5 py-3.5">Others</th><th class="px-5 py-3.5 text-right">Bids</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100" data-items-board></tbody>
                    </table>
                </div>
            </section>
        @endif

        <div class="mt-6 grid gap-6 lg:grid-cols-3">
            <section class="rounded-2xl border border-slate-200 bg-white shadow-sm lg:col-span-2">
                <h2 class="border-b border-slate-100 px-6 py-4 font-semibold">{{ ($state['basis'] ?? 'lot_total') === 'per_item' ? 'If one supplier took every item' : 'Standings' }}</h2>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[560px] text-sm">
                        <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                            <tr><th class="px-5 py-3.5">Rank</th><th class="px-5 py-3.5">Supplier</th><th class="px-5 py-3.5 text-right">Price</th><th class="px-5 py-3.5 text-right">Bids</th><th class="px-5 py-3.5 text-right">Since</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100" data-standings></tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-100 px-6 py-4 font-semibold">Bid feed</h2>
                <ul class="max-h-96 divide-y divide-slate-100 overflow-y-auto text-sm" data-recent></ul>
            </section>
        </div>

        @if (in_array($state['status'], ['scheduled', 'live'], true) && $participants->isNotEmpty())
            @php
                $roomUrl = route('supplier.auctions.show', $auction->id);
                $when = $state['status'] === 'live' ? 'is live now' : 'starts '.$auction->starts_at->ist()->format('d M, h:i A').' IST';
            @endphp
            <section class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-5 py-3">
                    <h2 class="font-semibold">Participants</h2>
                    <button type="button" data-copy="{{ $roomUrl }}" class="text-sm font-medium text-emerald-700 hover:underline">Copy auction room link</button>
                </div>
                <p class="px-5 pt-3 text-xs text-slate-500">They already got an email with the time and a reminder 15 minutes before. Send the room link on WhatsApp for a nudge; it opens straight into their bidding room after sign-in.</p>
                <ul class="divide-y divide-slate-100 text-sm">
                    @foreach ($participants as $p)
                        @php
                            $phone = $p->listEntry?->contact_phone;
                            $wa = $phone ? 'https://wa.me/91'.$phone.'?text='.rawurlencode(
                                "Hello, the live auction by {$currentOrg->name} for \"{$rfq->title}\" {$when}. Join your bidding room: {$roomUrl}") : null;
                        @endphp
                        <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                            <span>{{ $p->supplier?->name ?? $p->listEntry?->displayName() ?? 'Supplier' }}
                                <span class="block text-xs text-slate-500">{{ collect([$phone, $p->listEntry?->contact_email])->filter()->implode(' · ') ?: 'No contact details' }}</span>
                            </span>
                            @if ($wa)
                                <a href="{{ $wa }}" target="_blank" rel="noopener noreferrer" class="rounded-lg border border-emerald-200 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-50">Send on WhatsApp</a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($canManage && $state['status'] === 'scheduled')
            <details class="mt-6 max-w-xl rounded-2xl border border-slate-200 bg-white shadow-sm p-5 text-sm">
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
