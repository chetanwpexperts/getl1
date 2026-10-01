@extends('layouts.app')

@section('title', 'RFQs & auctions')

@section('content')
    <x-live-page :url="route('supplier.rfqs.live')" :live="$live" />
    <x-page-header title="RFQs & auctions" subtitle="Requests you've been invited to quote on. Your prices stay sealed until each deadline." />

    {{-- Upcoming and running auctions, impossible to miss --}}
    @php
        $activeAuctions = $auctions->filter(fn ($a) => in_array(\App\Services\Auction\Standings::effectiveStatus($a)->value, ['scheduled', 'live'], true))
            ->sortBy('starts_at');
    @endphp
    @foreach ($activeAuctions as $a)
        @php
            $isLive = \App\Services\Auction\Standings::effectiveStatus($a)->value === 'live';
            $aRfq = $invites->firstWhere('rfq_id', $a->rfq_id)?->rfq;
        @endphp
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl border p-5 {{ $isLive ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-emerald-200 bg-emerald-50 text-emerald-900' }}">
            <div>
                <p class="font-semibold">
                    @if ($isLive) Live auction running now @else Live auction {{ $a->starts_at->ist()->format('d M, h:i A') }} IST @endif
                    @if ($aRfq) · {{ $aRfq->title }} @endif
                </p>
                <p class="mt-0.5 text-sm {{ $isLive ? 'text-emerald-50' : '' }}">
                    @if ($isLive) Ends in <span class="font-semibold tabular-nums" data-countdown-to="{{ $a->ends_at->getTimestampMs() }}"></span>
                    @else Starts in <span class="font-semibold tabular-nums" data-countdown-to="{{ $a->starts_at->getTimestampMs() }}"></span>. You start at your sealed quote.
                    @endif
                </p>
            </div>
            <a href="{{ route('supplier.auctions.show', $a->id) }}"
               class="rounded-lg px-5 py-2.5 text-sm font-semibold {{ $isLive ? 'bg-white text-emerald-800 hover:bg-emerald-50' : 'bg-emerald-700 text-white hover:bg-emerald-800' }}">
                {{ $isLive ? 'Join auction now' : 'Open auction room' }}
            </a>
        </div>
    @endforeach

    <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full min-w-[640px] text-sm">
            <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                <tr><th class="px-6 py-3 font-medium">RFQ</th><th class="px-3 py-3 font-medium">Buyer</th><th class="px-3 py-3 font-medium">Quotes close</th><th class="px-6 py-3 font-medium">Status</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($invites as $inv)
                    @php
                        $rfq = $inv->rfq;
                        $myAuction = $auctions[$rfq->id] ?? null;
                        $state = match (true) {
                            $rfq->isCancelled() => 'cancelled',
                            $myAuction !== null => ['scheduled' => 'scheduled', 'live' => 'live'][\App\Services\Auction\Standings::effectiveStatus($myAuction)->value] ?? 'evaluating',
                            $inv->status->value === 'declined' => 'declined',
                            in_array($rfq->id, $quotedRfqIds) => 'quoted',
                            ! $rfq->isOpenForQuotes() => 'closed',
                            default => $inv->status->value,
                        };
                        $auctionLabel = match ($state) {
                            'live' => 'Join live auction →',
                            'scheduled' => $myAuction ? 'Auction '.$myAuction->starts_at->ist()->format('d M, h:i A').' IST →' : '',
                            default => 'View auction result →',
                        };
                    @endphp
                    <tr class="hover:bg-slate-50">
                        <td class="px-6 py-3.5">
                            <a href="{{ route('supplier.rfqs.show', $inv->id) }}" class="font-medium text-slate-900 hover:text-emerald-800">{{ $rfq->title }}</a>
                            <span class="block text-xs text-slate-500">{{ $rfq->ref_no }}</span>
                            @if ($myAuction)
                                <a href="{{ route('supplier.auctions.show', $myAuction->id) }}" class="mt-1 inline-block text-xs font-semibold text-emerald-700 hover:underline">{{ $auctionLabel }}</a>
                            @endif
                        </td>
                        <td class="px-3 py-3.5">{{ $rfq->organization->name }}<span class="block text-xs text-slate-500">{{ $rfq->organization->city }}</span></td>
                        <td class="whitespace-nowrap px-3 py-3.5 text-slate-700">{{ $rfq->quote_deadline?->ist()->format('d M, h:i A') }}</td>
                        <td class="px-6 py-3.5"><x-status-badge :status="$state" /></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-6 py-14 text-center">
                        <span class="mx-auto flex size-12 items-center justify-center rounded-full bg-slate-100 text-slate-500"><x-icon name="bell" class="size-6" /></span>
                        <p class="mt-3 font-medium text-slate-900">No invitations yet</p>
                        <p class="mt-1 text-sm text-slate-500">Buyers invite you by email or WhatsApp. Requests show up here.</p>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $invites->links() }}</div>
@endsection
