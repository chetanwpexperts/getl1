@extends('layouts.admin')

@section('title', 'Auction #'.$a->id)

@section('content')
    @php
        $inr = fn ($v) => $v === null ? '—' : \App\Support\Money::inr($v);
        $eff = \App\Services\Auction\Standings::effectiveStatus($a);
        $running = $eff === \App\Enums\AuctionStatus::Live;
        $canCancel = in_array($eff, [\App\Enums\AuctionStatus::Scheduled, \App\Enums\AuctionStatus::Live], true);
        $peak = max(1, collect($velocity)->max('n'));
        $input = 'w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm';
    @endphp
    <x-live-page :url="route('admin.auctions.show.live', $a->id)" :live="$live" interval="3000" />

    <a href="{{ route('admin.auctions') }}" class="text-sm text-slate-600 hover:text-slate-900">← Live auctions</a>
    <div class="mt-2 flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-2xl font-semibold">{{ $rfq?->title ?? 'Auction' }}</h1>
            <p class="mt-1 text-sm text-slate-600">
                <a href="{{ route('admin.companies.show', $a->organization_id) }}" class="underline">{{ $orgs[$a->organization_id]->name ?? 'Buyer' }}</a>
                · {{ $rfq?->ref_no }} · auction #{{ $a->id }} · {{ $a->visibility === 'rank_and_l1' ? 'suppliers see rank + L1' : 'suppliers see rank only' }}
            </p>
        </div>
        <div class="text-right">
            @include('admin.auctions._status', ['a' => $a])
            <p class="mt-1 font-mono text-3xl font-semibold tabular-nums">
                @if ($a->isPaused())
                    {{ gmdate($a->remainingMs() >= 3600000 ? 'H:i:s' : 'i:s', intdiv($a->remainingMs(), 1000)) }}
                @elseif ($eff === \App\Enums\AuctionStatus::Scheduled)
                    <span data-countdown-to="{{ $a->starts_at->getTimestampMs() }}" data-countdown-prefix="starts in " data-countdown-done="starting"></span>
                @elseif ($running)
                    <span data-countdown-to="{{ $a->ends_at->getTimestampMs() }}" data-countdown-done="ending…">--:--</span>
                @else
                    00:00
                @endif
            </p>
            <p class="text-xs text-slate-500">
                {{ $a->starts_at->ist()->format('d M, h:i A') }} → {{ $a->ends_at->ist()->format('h:i:s A') }} IST
                · {{ $a->extensions_used }}/{{ $a->max_extensions }} extensions{{ $a->paused_seconds ? ' · paused '.gmdate('i:s', $a->paused_seconds).' total' : '' }}
            </p>
        </div>
    </div>

    @if ($a->isPaused())
        <div class="mt-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <strong>Paused {{ $a->paused_at->ist()->format('h:i:s A') }}</strong> ({{ $a->paused_at->diffForHumans() }}). Reason: {{ $a->pause_reason }}.
            Suppliers see a paused screen and their bids are refused. Resuming gives back the same time left (at least 2 minutes).
        </div>
    @elseif ($a->cancel_reason)
        <div class="mt-4 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">{{ $a->cancel_reason }}</div>
    @endif

    <div class="mt-5 grid grid-cols-2 gap-3 md:grid-cols-5">
        @include('admin._stat', ['label' => 'Opening (best sealed)', 'value' => $inr($a->start_price)])
        @include('admin._stat', ['label' => 'Current L1', 'value' => $inr($a->current_l1), 'note' => $a->current_l1_supplier_org_id ? ($orgs[$a->current_l1_supplier_org_id]->name ?? '') : null])
        @include('admin._stat', ['label' => 'Saving', 'value' => $a->savingsPct() !== null ? $a->savingsPct().'%' : '—'])
        @include('admin._stat', ['label' => 'Live bids', 'value' => $a->bid_count])
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Bids / minute (15 min)</p>
            <div class="mt-2 flex h-10 items-end gap-0.5" role="img" aria-label="Bids per minute over the last 15 minutes">
                @foreach ($velocity as $v)
                    <span class="flex-1 rounded-sm {{ $v['n'] ? 'bg-emerald-600' : 'bg-slate-200' }}" style="height: {{ max(8, (int) round($v['n'] / $peak * 100)) }}%" title="{{ $v['t']->ist()->format('h:i A') }}: {{ $v['n'] }}"></span>
                @endforeach
            </div>
        </div>
    </div>

    <div class="mt-6 grid gap-4 xl:grid-cols-3">
        <div class="space-y-4 xl:col-span-2">
            <section class="rounded-xl border border-slate-200 bg-white">
                <h2 class="border-b border-slate-100 px-4 py-3 text-sm font-semibold">Standings</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-2">Rank</th><th class="px-4 py-2">Supplier</th><th class="px-4 py-2 text-right">Price</th><th class="px-4 py-2 text-right">Live bids</th><th class="px-4 py-2">Since</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($standings as $s)
                                <tr class="{{ $s['rank'] === 1 ? 'bg-emerald-50/60' : '' }}">
                                    <td class="px-4 py-2 font-semibold">L{{ $s['rank'] }}</td>
                                    <td class="px-4 py-2"><a href="{{ route('admin.companies.show', $s['supplier_org_id']) }}" class="hover:underline">{{ $orgs[$s['supplier_org_id']]->name ?? 'Supplier' }}</a></td>
                                    <td class="px-4 py-2 text-right font-semibold tabular-nums">{{ $inr($s['amount']) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $s['bids'] }}</td>
                                    <td class="px-4 py-2 font-mono text-xs text-slate-500">{{ $s['at']->ist()->format('h:i:s.v') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white">
                <h2 class="flex items-center justify-between border-b border-slate-100 px-4 py-3 text-sm font-semibold">Bid feed <span class="text-xs font-normal text-slate-500">accepted and refused, newest first, server time IST</span></h2>
                <div class="max-h-[32rem] overflow-auto">
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($feed as $f)
                                <tr class="align-top {{ $f['ok'] ? '' : 'bg-amber-50/50' }}">
                                    <td class="whitespace-nowrap px-4 py-2 font-mono text-xs text-slate-500">{{ $f['at']->ist()->format('h:i:s.u') }}</td>
                                    <td class="px-2 py-2"><span class="rounded px-1.5 py-0.5 text-[11px] font-bold {{ $f['ok'] ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900' }}">{{ $f['ok'] ? 'OK' : 'REFUSED' }}</span></td>
                                    <td class="px-2 py-2">{{ $orgs[$f['supplier']]->name ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-2 py-2 text-right font-semibold tabular-nums">{{ $f['ok'] ? $inr($f['amount']) : ($f['amount'] !== null && $f['amount'] !== '' ? '₹'.$f['amount'] : '') }}</td>
                                    <td class="px-4 py-2 text-xs text-slate-600">{{ $f['text'] }}<span class="ml-1 font-mono text-slate-400">{{ $f['ip'] }}</span></td>
                                </tr>
                            @empty
                                <tr><td class="px-4 py-6 text-center text-slate-500">No live bids yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <div class="space-y-4">
            <section class="rounded-xl border border-slate-200 bg-white p-4 text-sm">
                <h2 class="font-semibold">Emergency controls</h2>
                <p class="mt-1 text-xs text-slate-500">For technical problems only. Bids and the winner are never changed. Every action is logged and the buyer and suppliers are emailed with your reason.</p>

                @if ($running && ! $a->isPaused())
                    <form method="POST" action="{{ route('admin.auctions.pause', $a->id) }}" class="mt-4 space-y-2">
                        @csrf
                        <label class="block text-xs font-medium text-slate-600" for="pause_reason">Pause bidding</label>
                        <input id="pause_reason" name="reason" required minlength="5" maxlength="200" placeholder="e.g. Suppliers report the page isn't loading" class="{{ $input }}">
                        <button class="w-full rounded-lg bg-amber-500 px-3 py-2 font-semibold text-white hover:bg-amber-600">Pause now</button>
                    </form>
                @elseif ($a->isPaused())
                    <form method="POST" action="{{ route('admin.auctions.resume', $a->id) }}" class="mt-4" data-confirm="Resume bidding now?">
                        @csrf
                        <button class="w-full rounded-lg bg-emerald-700 px-3 py-2 font-semibold text-white hover:bg-emerald-800">Resume bidding</button>
                    </form>
                @endif

                @if ($running)
                    <form method="POST" action="{{ route('admin.auctions.time', $a->id) }}" class="mt-4 space-y-2 border-t border-slate-100 pt-4">
                        @csrf
                        <label class="block text-xs font-medium text-slate-600" for="time_reason">Add time</label>
                        <input id="time_reason" name="reason" required minlength="5" maxlength="200" placeholder="Reason" class="{{ $input }}">
                        <div class="flex gap-2">
                            @foreach ($addMinutes as $m)
                                <button name="minutes" value="{{ $m }}" class="flex-1 rounded-lg border border-slate-300 px-3 py-2 font-semibold hover:bg-slate-50">+{{ $m }} min</button>
                            @endforeach
                        </div>
                    </form>
                @endif

                @if ($canCancel)
                    <form method="POST" action="{{ route('admin.auctions.cancel', $a->id) }}" class="mt-4 space-y-2 border-t border-slate-100 pt-4">
                        @csrf
                        <label class="block text-xs font-medium text-red-700" for="cancel_reason">Cancel auction</label>
                        <input id="cancel_reason" name="reason" required minlength="10" maxlength="200" placeholder="Reason the buyer and suppliers will read" class="{{ $input }}">
                        <input name="confirm" required placeholder="Type CANCEL to confirm" autocomplete="off" class="{{ $input }}" aria-label="Type CANCEL to confirm">
                        <button class="w-full rounded-lg border border-red-300 px-3 py-2 font-semibold text-red-700 hover:bg-red-50">Cancel auction</button>
                        <p class="text-xs text-slate-500">The buyer keeps the sealed quotes and can schedule again. A used auction credit is returned.</p>
                    </form>
                @endif

                @unless ($running || $canCancel)
                    <p class="mt-3 text-slate-500">This auction has finished. No controls apply.</p>
                @endunless
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-4 text-sm">
                <h2 class="font-semibold">History</h2>
                <ul class="mt-2 space-y-2">
                    @forelse ($actions as $l)
                        <li>
                            <p class="font-medium">{{ ucfirst(str_replace(['_by_getl1', '_'], [' by GetL1', ' '], $l->action)) }}</p>
                            <p class="text-xs text-slate-500">{{ $l->created_at->ist()->format('d M, h:i:s A') }} · {{ $l->user?->email ?? 'system' }}{{ ! empty($l->after['reason']) ? ' · '.$l->after['reason'] : '' }}</p>
                        </li>
                    @empty
                        <li class="text-slate-500">Nothing yet.</li>
                    @endforelse
                </ul>
            </section>
        </div>
    </div>
@endsection
