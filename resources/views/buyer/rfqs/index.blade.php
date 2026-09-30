@extends('layouts.app')

@section('title', 'RFQs')

@section('content')
    @php $canManage = in_array($currentRole?->value, ['buyer_admin', 'buyer_user'], true); @endphp

    <x-live-page :url="route('buyer.rfqs.live')" :live="$live" />
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">RFQs</h1>
            <p class="mt-1 text-sm text-slate-600">Your purchase requirements. Suppliers quote sealed until the deadline.</p>
        </div>
        @if ($canManage)
            <a href="{{ route('buyer.rfqs.create') }}" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">New RFQ</a>
        @endif
    </div>

    <div class="mt-5 flex flex-wrap gap-2 text-sm">
        @foreach ([null => 'All', 'draft' => 'Drafts', 'published' => 'Published', 'cancelled' => 'Cancelled'] as $key => $label)
            <a href="{{ route('buyer.rfqs.index', $key ? ['status' => $key] : []) }}"
               class="rounded-lg border px-3 py-1.5 {{ $status === ($key ?: null) ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white hover:bg-slate-50' }}">{{ $label }}</a>
        @endforeach
    </div>

    <div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="w-full min-w-[680px] text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr><th class="px-4 py-3">RFQ</th><th class="px-4 py-3">Items</th><th class="px-4 py-3">Suppliers</th><th class="px-4 py-3">Quote deadline</th><th class="px-4 py-3">Status</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($rfqs as $rfq)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3">
                            <a href="{{ route('buyer.rfqs.show', $rfq->id) }}" class="font-medium text-slate-900 hover:underline">{{ $rfq->title }}</a>
                            <span class="block text-xs text-slate-500">{{ $rfq->ref_no }}</span>
                        </td>
                        <td class="px-4 py-3 tabular-nums">{{ $rfq->items_count }}</td>
                        <td class="px-4 py-3 tabular-nums">{{ $rfq->invites_count }}</td>
                        <td class="px-4 py-3">{{ $rfq->quote_deadline ? $rfq->quote_deadline->ist()->format('d M Y, h:i A') : '—' }}</td>
                        <td class="px-4 py-3"><x-status-badge :status="$rfq->displayStatus()" /></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-slate-600">
                        No RFQs yet.
                        @if ($canManage)<a href="{{ route('buyer.rfqs.create') }}" class="font-medium text-emerald-700 hover:underline">Create your first RFQ</a>.@endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $rfqs->links() }}</div>
@endsection
