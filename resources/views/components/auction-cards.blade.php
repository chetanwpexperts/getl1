@props(['auctions', 'org'])
{{-- Running and today's auctions, top of the dashboard. --}}
@foreach ($auctions as $a)
    @php
        $isLive = \App\Services\Auction\Standings::effectiveStatus($a) === \App\Enums\AuctionStatus::Live;
    @endphp
    <div class="mt-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border p-5 {{ $isLive ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-emerald-200 bg-emerald-50 text-emerald-900' }}">
        <div>
            <p class="font-semibold">
                {{ $isLive ? 'Live auction running now' : 'Live auction '.$a->starts_at->ist()->format('d M, h:i A').' IST' }}
                · {{ $a->rfq?->title }} <span class="font-normal opacity-80">({{ $a->rfq?->ref_no }})</span>
            </p>
            <p class="mt-0.5 text-sm {{ $isLive ? 'text-emerald-50' : '' }}">
                @if ($isLive)
                    Ends in <span class="font-semibold tabular-nums" data-countdown-to="{{ $a->ends_at->getTimestampMs() }}" data-countdown-done="a moment"></span>
                @else
                    Starts in <span class="font-semibold tabular-nums" data-countdown-to="{{ $a->starts_at->getTimestampMs() }}" data-countdown-done="a moment"></span>
                @endif
            </p>
        </div>
        <a href="{{ \App\Services\Auction\ActiveAuctions::url($org, $a) }}"
           class="rounded-lg px-5 py-2.5 text-sm font-semibold {{ $isLive ? 'bg-white text-emerald-800 hover:bg-emerald-50' : 'bg-emerald-700 text-white hover:bg-emerald-800' }}">
            @if ($org->isBuyer())
                {{ $isLive ? 'Watch live' : 'Open auction console' }}
            @else
                {{ $isLive ? 'Join auction now' : 'Open auction room' }}
            @endif
        </a>
    </div>
@endforeach
