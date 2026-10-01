@php
    $eff = \App\Services\Auction\Standings::effectiveStatus($a)->value;
    [$label, $cls] = $a->isPaused() ? ['Paused', 'bg-amber-100 text-amber-900 ring-amber-300']
        : (['live' => ['Live', 'bg-red-50 text-red-700 ring-red-200'], 'scheduled' => ['Scheduled', 'bg-sky-50 text-sky-800 ring-sky-200'],
            'closed' => ['Closed', 'bg-slate-100 text-slate-700 ring-slate-200'], 'cancelled' => ['Cancelled', 'bg-slate-100 text-slate-500 ring-slate-200']][$eff]
            ?? [ucfirst($eff), 'bg-slate-100 text-slate-700 ring-slate-200']);
@endphp
<span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 {{ $cls }}">
    @if ($label === 'Live')<span class="relative flex size-1.5"><span class="absolute inline-flex size-full animate-ping rounded-full bg-red-400 opacity-75"></span><span class="relative inline-flex size-1.5 rounded-full bg-red-600"></span></span>@endif
    {{ $label }}
</span>
