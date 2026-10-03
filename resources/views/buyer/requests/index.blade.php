@extends('layouts.app')

@section('title', $isRequester ? 'My requests' : 'Purchase requests')

@section('content')
    @php $inr = fn ($v) => \App\Support\Money::inr($v); @endphp

    <x-page-header :title="$isRequester ? 'My requests' : 'Purchase requests'"
                   :subtitle="$isRequester ? 'Ask for material and follow it until it arrives.' : 'Requests from your team. Approve them, then turn approved ones into an RFQ.'">
        <a href="{{ route('buyer.requests.create') }}" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800">
            <x-icon name="plus" class="size-4" /> New request
        </a>
    </x-page-header>

    @if (session('status'))
        <p class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ session('status') }}</p>
    @endif

    @if (count($tabs) > 1)
        <nav class="mb-4 flex flex-wrap gap-1 border-b border-slate-200 text-sm" aria-label="Request filter">
            @foreach ($tabs as $key => $label)
                <a href="{{ route('buyer.requests.index', ['tab' => $key]) }}"
                   class="-mb-px border-b-2 px-4 py-2.5 font-medium {{ $tab === $key ? 'border-emerald-700 text-emerald-800' : 'border-transparent text-slate-500 hover:text-slate-800' }}">
                    {{ $label }}@if (($counts[$key] ?? 0) > 0) <span class="ml-1 rounded-full bg-slate-100 px-1.5 py-0.5 text-xs text-slate-700">{{ $counts[$key] }}</span>@endif
                </a>
            @endforeach
        </nav>
    @endif

    @php $selectable = $tab === 'ready' && $canBuy && $requests->isNotEmpty(); @endphp
    <form method="POST" action="{{ route('buyer.requests.convert') }}" data-pr-convert>
        @csrf
        @if ($selectable)
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50/60 px-4 py-3 text-sm">
                <p class="text-emerald-900">Tick the requests to buy together. The same items are combined into one line.</p>
                <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-50" data-pr-convert-btn disabled>Create RFQ from selected</button>
            </div>
            @error('ids') <p class="mb-3 text-sm text-red-600">{{ $message }}</p> @enderror
        @endif

        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full min-w-[760px] text-sm">
                <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                    <tr>
                        @if ($selectable)<th class="w-10 px-5 py-3.5"><span class="sr-only">Select</span></th>@endif
                        <th class="px-5 py-3.5">Request</th>
                        @unless ($isRequester)<th class="px-5 py-3.5">Requested by</th>@endunless
                        <th class="px-5 py-3.5">Needed by</th>
                        <th class="px-5 py-3.5 text-right">Items</th>
                        <th class="px-5 py-3.5">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($requests as $r)
                        <tr class="hover:bg-slate-50">
                            @if ($selectable)
                                <td class="px-5 py-3.5"><input type="checkbox" name="ids[]" value="{{ $r->id }}" aria-label="Select {{ $r->pr_number }}" class="size-4 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600"></td>
                            @endif
                            <td class="px-5 py-3.5">
                                <a href="{{ route('buyer.requests.show', $r->id) }}" class="font-medium hover:underline">{{ $r->title }}</a>
                                <span class="block text-xs text-slate-500">{{ $r->pr_number }}{{ $r->department ? ' · '.$r->department : '' }} · {{ $r->created_at->ist()->format('d M Y') }}</span>
                            </td>
                            @unless ($isRequester)<td class="px-5 py-3.5">{{ $r->requester?->name }}</td>@endunless
                            <td class="px-5 py-3.5 {{ $r->needed_by && $r->isOpen() && $r->needed_by->lt(today()->addDays(3)) ? 'font-medium text-amber-800' : '' }}">{{ $r->needed_by?->format('d M Y') ?? '—' }}</td>
                            <td class="px-5 py-3.5 text-right tabular-nums">
                                {{ count($r->items) }}
                                @if ($r->estimated_total && ! $isRequester)<span class="block text-xs text-slate-500">≈ {{ $inr($r->estimated_total) }}</span>@endif
                            </td>
                            <td class="px-5 py-3.5">@include('buyer.requests._status', ['progress' => $r->progress()])</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-12 text-center text-slate-600">
                            {{ ['mine' => 'You haven’t raised any requests yet. Use “New request” to ask for material.', 'approve' => 'Nothing waiting for your approval.', 'ready' => 'No approved requests waiting. They appear here once approved.', 'all' => 'No purchase requests yet.'][$tab] }}
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </form>
    <div class="mt-4">{{ $requests->links() }}</div>
@endsection
