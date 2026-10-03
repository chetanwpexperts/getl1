@extends('layouts.app')

@section('title', 'Schedule auction')

@section('content')
    @php
        $input = 'mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20';
        $best = $quotes->first();
    @endphp

    <x-breadcrumb :items="[['RFQs & auctions', route('buyer.rfqs.index')], [$rfq->ref_no, route('buyer.rfqs.show', $rfq->id)]]" current="Schedule auction" />
    <h1 class="text-2xl font-semibold tracking-tight">Schedule a live auction</h1>
    <p class="mt-1 text-sm text-slate-500">{{ $rfq->title }}. Suppliers who quoted start at their sealed price and bid it down live.</p>

    @if ($allowance['left'] !== null)
        @php
            $blocked = $allowance['left'] === 0 && $allowance['credits'] === 0;
        @endphp
        <p class="mt-4 rounded-xl border px-4 py-3 text-sm {{ $blocked ? 'border-red-200 bg-red-50 text-red-900' : 'border-slate-200 bg-white text-slate-700' }}">
            {{ $allowance['used'] }} of {{ $allowance['limit'] }} live {{ \Illuminate\Support\Str::plural('auction', $allowance['limit']) }} used this month on your {{ $allowance['plan']?->name }} plan.
            @if ($allowance['left'] > 0)
                This one is included.
            @elseif ($allowance['credits'] > 0)
                This one uses 1 of your {{ $allowance['credits'] }} auction {{ \Illuminate\Support\Str::plural('credit', $allowance['credits']) }}.
            @else
                <a href="{{ route('buyer.billing.index') }}" class="font-semibold underline">Upgrade or buy a single auction</a> to run this one.
            @endif
        </p>
    @endif

    @if ($quotes->count() < $minParticipants)
        <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
            An auction needs at least {{ $minParticipants }} suppliers who quoted. Only {{ $quotes->count() }} quoted for this RFQ, so you can award directly from the comparison instead.
        </div>
    @else
        <div class="mt-6 grid gap-6 lg:grid-cols-3">
            @php $format = old('format', 'english_reverse'); @endphp
            <form method="POST" action="{{ route('buyer.auctions.store', $rfq->id) }}" class="space-y-6 lg:col-span-2" data-auction-form>
                @csrf
                @if ($rfq->isPerItem())
                    <input type="hidden" name="format" value="english_reverse">
                @else
                    <section class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5">
                        <h2 class="text-sm font-semibold">Auction type</h2>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            @foreach ([
                                'english_reverse' => ['Standard reverse auction', 'Suppliers bid lower and lower until time runs out. They see their rank. Best for most purchases.'],
                                'japanese' => ['Japanese auction', 'The price drops every round. Suppliers accept to stay in or drop out. Last one standing wins. Fast and very transparent.'],
                            ] as $v => [$l, $d])
                                <label class="flex cursor-pointer gap-3 rounded-xl border border-slate-300 p-3 text-sm has-[:checked]:border-emerald-600 has-[:checked]:bg-emerald-50">
                                    <input type="radio" name="format" value="{{ $v }}" class="mt-0.5" data-format-switch @checked($format === $v)>
                                    <span><span class="block font-medium">{{ $l }}</span><span class="block text-slate-600">{{ $d }}</span></span>
                                </label>
                            @endforeach
                        </div>
                        @error('format') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </section>
                @endif

                <section class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5" data-format-only="japanese" @if ($format !== 'japanese') hidden @endif>
                    <h2 class="text-sm font-semibold">Rounds</h2>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="opening_price" class="block text-sm font-medium text-slate-700">Opening price (₹, before GST)</label>
                            <input id="opening_price" name="opening_price" inputmode="decimal" required value="{{ old('opening_price', number_format((float) $best->total, 2, '.', '')) }}" class="{{ $input }}">
                            <p class="mt-1 text-xs text-slate-500">Round 1 price. The best sealed quote is filled in; start a little higher to give room.</p>
                            @error('opening_price') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="round_seconds" class="block text-sm font-medium text-slate-700">Each round lasts</label>
                            <select id="round_seconds" name="round_seconds" class="{{ $input }}">
                                @foreach (\App\Services\Auction\Japanese::ROUND_SECONDS as $sec)
                                    <option value="{{ $sec }}" @selected((int) old('round_seconds', 60) === $sec)>{{ $sec < 60 ? $sec.' seconds' : ($sec / 60).' '.\Illuminate\Support\Str::plural('minute', $sec / 60) }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-slate-500">Suppliers must accept within the round or they drop out.</p>
                        </div>
                    </div>
                </section>

                <section class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5">
                    <h2 class="text-sm font-semibold">Timing</h2>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="starts_at" class="block text-sm font-medium text-slate-700">Start (IST)</label>
                            <input id="starts_at" type="datetime-local" name="starts_at" required value="{{ old('starts_at', $defaultStart) }}" class="{{ $input }}">
                            <p class="mt-1 text-xs text-slate-500">At least 5 minutes from now, so suppliers can get ready.</p>
                            @error('starts_at') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div data-format-only="english_reverse" @if ($format === 'japanese') hidden @endif>
                            <label for="duration_min" class="block text-sm font-medium text-slate-700">Duration</label>
                            <select id="duration_min" name="duration_min" class="{{ $input }}">
                                @foreach (\App\Services\Auction\AuctionService::durationOptions() as $m)
                                    <option value="{{ $m }}" @selected((int) old('duration_min', $defaults['auction.default_duration_min']) === $m)>{{ $m }} minutes</option>
                                @endforeach
                            </select>
                        </div>
                        <div data-format-only="english_reverse" @if ($format === 'japanese') hidden @endif>
                            <label for="extend_window_sec" class="block text-sm font-medium text-slate-700">Auto-extend if a bid comes in the last</label>
                            <select id="extend_window_sec" name="extend_window_sec" class="{{ $input }}">
                                @foreach ([0 => 'Off', 60 => '1 minute', 120 => '2 minutes', 180 => '3 minutes', 300 => '5 minutes'] as $v => $l)
                                    <option value="{{ $v }}" @selected((int) old('extend_window_sec', $defaults['auction.default_extend_window_sec']) === $v)>{{ $l }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="grid grid-cols-2 gap-3" data-format-only="english_reverse" @if ($format === 'japanese') hidden @endif>
                            <div>
                                <label for="extend_by_sec" class="block text-sm font-medium text-slate-700">Extend by</label>
                                <select id="extend_by_sec" name="extend_by_sec" class="{{ $input }}">
                                    @foreach ([60 => '1 min', 120 => '2 min', 180 => '3 min', 300 => '5 min'] as $v => $l)
                                        <option value="{{ $v }}" @selected((int) old('extend_by_sec', $defaults['auction.default_extend_by_sec']) === $v)>{{ $l }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="max_extensions" class="block text-sm font-medium text-slate-700">Max times</label>
                                <input id="max_extensions" name="max_extensions" type="number" min="0" max="{{ $defaults['auction.max_extensions_limit'] }}" value="{{ old('max_extensions', $defaults['auction.default_max_extensions']) }}" class="{{ $input }}">
                            </div>
                        </div>
                    </div>
                    <p class="mt-3 text-xs text-slate-500" data-format-only="english_reverse" @if ($format === 'japanese') hidden @endif>Auto-extend stops "last-second sniping": everyone gets a fair chance to respond.</p>
                    <p class="mt-3 text-xs text-slate-500" data-format-only="japanese" @if ($format !== 'japanese') hidden @endif>It ends as soon as a round finishes with one supplier (or none) accepting, or at the floor price.</p>
                </section>

                <section class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5">
                    <h2 class="text-sm font-semibold">Bidding rules</h2>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-slate-700" data-text-japanese="Price drop each round">Minimum drop per bid</label>
                            <div class="mt-1 flex gap-2">
                                <input name="min_decrement_value" inputmode="decimal" required value="{{ old('min_decrement_value', $defaults['auction.default_min_decrement_pct']) }}" class="{{ $input }} mt-0">
                                @if ($rfq->isPerItem())
                                    <input type="hidden" name="min_decrement_type" value="percent">
                                    <span class="flex w-16 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-sm text-slate-600">%</span>
                                @else
                                    <select name="min_decrement_type" class="{{ $input }} mt-0 w-32">
                                        <option value="percent" @selected(old('min_decrement_type', 'percent') === 'percent')>%</option>
                                        <option value="amount" @selected(old('min_decrement_type') === 'amount')>₹</option>
                                    </select>
                                @endif
                            </div>
                            <p class="mt-1 text-xs text-slate-500" data-text-japanese="Percent of the opening price, or a fixed amount in rupees.">{{ $rfq->isPerItem() ? 'Item by item: each new rate must beat the supplier\'s own rate for that item by at least this much.' : 'Each new bid must beat the supplier\'s own price by at least this much.' }}</p>
                            @error('min_decrement_value') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="max_decrement_pct" class="block text-sm font-medium text-slate-700" data-text-japanese="Lowest price (% below opening)">Typo guard (max % below current L1)</label>
                            <input id="max_decrement_pct" name="max_decrement_pct" inputmode="decimal" required value="{{ old('max_decrement_pct', $defaults['auction.default_max_decrement_pct']) }}" class="{{ $input }}">
                            <p class="mt-1 text-xs text-slate-500" data-text-japanese="Rounds stop at this price, so it never goes below what is realistic.">Blocks accidental bids like ₹1,200 instead of ₹12,000.</p>
                            @error('max_decrement_pct') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="sm:col-span-2" data-format-only="english_reverse" @if ($format === 'japanese') hidden @endif>
                            <span class="block text-sm font-medium text-slate-700">What suppliers see</span>
                            <div class="mt-2 grid gap-3 sm:grid-cols-2">
                                @foreach (['rank_only' => ['Rank only', 'Suppliers see L1/L2/L3, not the winning price. Recommended.'],
                                           'rank_and_l1' => ['Rank + lowest price', 'Suppliers also see the current L1 price (no names).']] as $v => [$l, $d])
                                    <label class="flex cursor-pointer gap-3 rounded-xl border border-slate-300 p-3 text-sm has-[:checked]:border-emerald-600 has-[:checked]:bg-emerald-50">
                                        <input type="radio" name="visibility" value="{{ $v }}" class="mt-0.5" @checked(old('visibility', 'rank_only') === $v)>
                                        <span><span class="block font-medium">{{ $l }}</span><span class="block text-slate-600">{{ $d }}</span></span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </section>

                <div class="sm:w-60"><x-button>Schedule auction</x-button></div>
            </form>

            <aside class="space-y-4">
                <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold">Participants ({{ $quotes->count() }})</h2>
                    <ul class="divide-y divide-slate-100 text-sm">
                        @foreach ($quotes as $q)
                            <li class="flex justify-between gap-3 px-5 py-2.5">
                                <span>{{ $q->supplier->name }}</span>
                                <span class="tabular-nums {{ $q->is($best) ? 'font-semibold text-emerald-700' : '' }}">{{ \App\Support\Money::inr($q->total) }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500">@if ($rfq->isPerItem())
                        Item by item: every supplier starts at its quoted rates; each item is ranked on its own.
                    @else
                        Start price is the best sealed quote (before GST): <span class="font-semibold text-slate-700">{{ \App\Support\Money::inr($best->total) }}</span>.
                    @endif</p>
                </section>
                <p class="text-xs text-slate-500">Participants get an email with the start time. Suppliers never see each other's names.</p>
            </aside>
        </div>
    @endif
@endsection
