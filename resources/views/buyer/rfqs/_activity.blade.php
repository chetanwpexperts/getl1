@php
    // Names for entries whose subject is a supplier's record.
    $names = [];
    foreach (\App\Models\Quote::with('supplier:id,name')->where('rfq_id', $rfq->id)->get() as $q) {
        $names['quote:'.$q->id] = $q->supplier?->name;
    }
    foreach ($rfq->invites as $inv) {
        $names['rfq_invite:'.$inv->id] = $inv->supplier?->name ?? $inv->listEntry?->displayName();
    }
    foreach (\App\Models\Award::with('supplier:id,name')->where('rfq_id', $rfq->id)->get() as $aw) {
        $names['award:'.$aw->id] = $aw->supplier?->name;
    }
    $auctionIds = \App\Models\Auction::where('rfq_id', $rfq->id)->where('status', '!=', 'cancelled')->pluck('id');
@endphp

@if ($activity->isNotEmpty())
    <section id="activity" class="mt-6 rounded-xl border border-slate-200 bg-white">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-5 py-3">
            <div>
                <h2 class="font-semibold">Activity</h2>
                <p class="text-xs text-slate-500">Permanent record of every step: who, when (IST) and from which IP address. Entries can't be edited or deleted.</p>
            </div>
            @foreach ($auctionIds as $aid)
                <a href="{{ route('buyer.auctions.bids', $aid) }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">Download bid log (CSV){{ $auctionIds->count() > 1 ? ' #'.$aid : '' }}</a>
            @endforeach
        </div>
        <ol class="divide-y divide-slate-100 text-sm">
            @foreach ($activity as $log)
                <li class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1 px-5 py-2.5">
                    <span class="min-w-0 flex-1">
                        {{ \App\Support\ActivityText::describe($log, $names) }}
                        <span class="block text-xs text-slate-500">{{ $log->user?->name ?? 'GetL1 (automatic)' }}@if ($log->user && $log->ip) · {{ $log->ip }}@endif</span>
                    </span>
                    <time class="whitespace-nowrap text-xs text-slate-500" datetime="{{ $log->created_at->toIso8601String() }}">{{ $log->created_at->ist()->format('d M Y, h:i:s A') }}</time>
                </li>
            @endforeach
        </ol>
    </section>
@endif
