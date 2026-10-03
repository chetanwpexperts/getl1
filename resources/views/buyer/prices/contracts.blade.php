@extends('layouts.app')

@section('title', 'Rate contracts')

@section('content')
    <x-page-header title="Prices" subtitle="What you have paid for every item, from each purchase order, and the rates agreed with suppliers.">
        @if ($canEdit)
            <a href="{{ route('buyer.contracts.create') }}" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800">
                <x-icon name="plus" class="size-4" /> New rate contract
            </a>
        @endif
    </x-page-header>
    @include('buyer.prices._tabs')


    <div class="mb-4 flex gap-2 text-sm">
        @foreach (['active' => 'Current', 'ended' => 'Ended'] as $k => $l)
            <a href="{{ route('buyer.contracts.index', ['state' => $k]) }}" class="rounded-full px-3 py-1 {{ $state === $k ? 'bg-slate-900 text-white' : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">{{ $l }}</a>
        @endforeach
    </div>

    <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full min-w-[760px] text-sm">
            <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                <tr><th class="px-5 py-3.5">Contract</th><th class="px-5 py-3.5">Supplier</th><th class="px-5 py-3.5">Valid</th><th class="px-5 py-3.5 text-right">Items</th><th class="px-5 py-3.5">Status</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($contracts as $rc)
                    <tr class="hover:bg-slate-50">
                        <td class="px-5 py-3.5"><a href="{{ route('buyer.contracts.show', $rc->id) }}" class="font-medium hover:underline">{{ $rc->title }}</a><span class="block text-xs text-slate-500">{{ $rc->rc_number }}</span></td>
                        <td class="px-5 py-3.5">{{ $rc->supplier?->name }}
                            <span class="block text-xs {{ $rc->supplier_accepted_at ? 'text-emerald-700' : 'text-amber-800' }}">{{ $rc->supplier_accepted_at ? '✓ Confirmed' : 'Not confirmed yet' }}</span></td>
                        <td class="px-5 py-3.5">{{ $rc->valid_from->format('d M Y') }} – {{ $rc->valid_to->format('d M Y') }}
                            @if ($rc->state() === 'active' && $rc->daysLeft() <= 30)<span class="block text-xs font-medium text-amber-800">{{ $rc->daysLeft() === 0 ? 'Ends today' : 'Ends in '.$rc->daysLeft().' '.\Illuminate\Support\Str::plural('day', $rc->daysLeft()) }}</span>@endif</td>
                        <td class="px-5 py-3.5 text-right tabular-nums">{{ count($rc->items) }}</td>
                        <td class="px-5 py-3.5">@include('buyer.prices._contract-state', ['rc' => $rc])</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-12 text-center text-slate-600">
                        {{ $state === 'active' ? 'No rate contracts yet. Open a purchase order and choose "Make rate contract" to lock in its rates for the months ahead.' : 'No ended rate contracts.' }}
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $contracts->links() }}</div>
@endsection
