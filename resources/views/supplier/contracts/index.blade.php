@extends('layouts.app')

@section('title', 'Rate contracts')

@section('content')
    <x-page-header title="Rate contracts" subtitle="Rates your buyers have agreed with you for a period." />

    <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full min-w-[720px] text-sm">
            <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                <tr><th class="px-5 py-3.5">Contract</th><th class="px-5 py-3.5">Buyer</th><th class="px-5 py-3.5">Valid</th><th class="px-5 py-3.5">Status</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($contracts as $rc)
                    <tr class="hover:bg-slate-50">
                        <td class="px-5 py-3.5"><a href="{{ route('supplier.contracts.show', $rc->id) }}" class="font-medium hover:underline">{{ $rc->title }}</a><span class="block text-xs text-slate-500">{{ $rc->rc_number }} · {{ count($rc->items) }} {{ \Illuminate\Support\Str::plural('item', count($rc->items)) }}</span></td>
                        <td class="px-5 py-3.5">{{ $rc->buyer?->name }}<span class="block text-xs text-slate-500">{{ $rc->buyer?->city }}</span></td>
                        <td class="px-5 py-3.5">{{ $rc->valid_from->format('d M Y') }} – {{ $rc->valid_to->format('d M Y') }}</td>
                        <td class="px-5 py-3.5">
                            @if (in_array($rc->state(), ['active', 'upcoming'], true) && ! $rc->supplier_accepted_at)
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900">Please confirm</span>
                            @else
                                @include('buyer.prices._contract-state', ['rc' => $rc])
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-12 text-center text-slate-600">No rate contracts yet. When a buyer locks in your rates for a period, it appears here.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $contracts->links() }}</div>
@endsection
