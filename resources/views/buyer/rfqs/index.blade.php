@extends('layouts.app')

@section('title', 'RFQs & auctions')

@section('content')
    @php $canManage = in_array($currentRole?->value, ['buyer_admin', 'buyer_user'], true); @endphp

    <x-live-page :url="route('buyer.rfqs.live')" :live="$live" />

    <x-page-header title="RFQs & auctions" subtitle="Your purchase requirements. Suppliers quote sealed until the deadline, then bid live.">
        @if ($canManage)
            <a href="{{ route('buyer.rfqs.create') }}" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800"><x-icon name="plus" class="size-4" /> New RFQ</a>
        @endif
    </x-page-header>

    <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
        {{-- Status tabs and search --}}
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 sm:px-6">
            <nav class="-mb-px flex gap-6 overflow-x-auto text-sm font-medium [scrollbar-width:none]" aria-label="Filter by status">
                @foreach (['' => 'All'] + collect($tabs)->map(fn ($t) => $t[0])->all() as $key => $label)
                    @php $on = ($status ?? '') === $key; $n = $counts[$key ?: 'all'] ?? 0; @endphp
                    <a href="{{ route('buyer.rfqs.index', array_filter(['status' => $key, 'q' => $search])) }}" @if ($on) aria-current="page" @endif
                       class="flex shrink-0 items-center gap-2 border-b-2 py-3.5 {{ $on ? 'border-emerald-700 text-emerald-800' : 'border-transparent text-slate-500 hover:text-slate-800' }}">
                        {{ $label }}
                        <span class="rounded-full px-1.5 py-0.5 text-[11px] font-semibold tabular-nums {{ $on ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-500' }}">{{ $n }}</span>
                    </a>
                @endforeach
            </nav>
            <form method="GET" action="{{ route('buyer.rfqs.index') }}" class="w-full py-3 sm:w-64" role="search">
                @if ($status)<input type="hidden" name="status" value="{{ $status }}">@endif
                <label for="q" class="sr-only">Search RFQs</label>
                <input id="q" name="q" value="{{ $search }}" type="search" placeholder="Search title or RFQ number"
                       class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20">
            </form>
        </div>

        @if ($rfqs->isEmpty())
            <div class="px-6 py-14 text-center">
                <span class="mx-auto flex size-12 items-center justify-center rounded-full bg-slate-100 text-slate-500"><x-icon name="rfqs" class="size-6" /></span>
                @if ($search !== '' || $status)
                    <p class="mt-3 font-medium">No RFQs match</p>
                    <p class="mt-1 text-sm text-slate-500"><a href="{{ route('buyer.rfqs.index') }}" class="font-medium text-emerald-700 hover:underline">Clear the filter</a> to see everything.</p>
                @else
                    <p class="mt-3 font-medium">No RFQs yet</p>
                    <p class="mx-auto mt-1 max-w-sm text-sm text-slate-500">Create a requirement, invite your suppliers to quote, then run a short live auction.</p>
                    @if ($canManage)
                        <a href="{{ route('buyer.rfqs.create') }}" class="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800"><x-icon name="plus" class="size-4" /> Create your first RFQ</a>
                    @endif
                @endif
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-slate-400">
                        <tr>
                            <th class="px-6 py-3 font-medium">RFQ</th>
                            <th class="px-3 py-3 text-right font-medium">Items</th>
                            <th class="px-3 py-3 text-right font-medium">Suppliers</th>
                            <th class="px-3 py-3 font-medium">Quotes close</th>
                            <th class="px-6 py-3 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($rfqs as $rfq)
                            @php $deadline = $rfq->quote_deadline; @endphp
                            <tr class="group relative hover:bg-slate-50">
                                <td class="px-6 py-3.5">
                                    <a href="{{ route('buyer.rfqs.show', $rfq->id) }}" class="font-medium text-slate-900 after:absolute after:inset-0 group-hover:text-emerald-800">{{ $rfq->title }}</a>
                                    <span class="block text-xs text-slate-500">{{ $rfq->ref_no }} · created {{ $rfq->created_at->ist()->format('d M') }}</span>
                                </td>
                                <td class="px-3 py-3.5 text-right tabular-nums text-slate-600">{{ $rfq->items_count }}</td>
                                <td class="px-3 py-3.5 text-right tabular-nums text-slate-600">{{ $rfq->invites_count }}</td>
                                <td class="whitespace-nowrap px-3 py-3.5">
                                    @if ($deadline)
                                        <span class="text-slate-700">{{ $deadline->ist()->format('d M, h:i A') }}</span>
                                        @if ($deadline->isFuture() && $deadline->lt(now()->addDays(2)))
                                            <span class="block text-xs font-medium text-amber-700">in {{ $deadline->diffForHumans(now(), \Carbon\CarbonInterface::DIFF_ABSOLUTE, true) }}</span>
                                        @endif
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                                <td class="px-6 py-3.5"><x-status-badge :status="$rfq->displayStatus()" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($rfqs->hasPages())
                <div class="border-t border-slate-100 px-6 py-3">{{ $rfqs->links() }}</div>
            @endif
        @endif
    </section>
@endsection
