@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    @php $sub = $currentOrg->subscription; @endphp

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">Dashboard</h1>
            <p class="mt-1 text-sm text-slate-600">{{ $currentOrg->name }}, {{ $currentOrg->city }}</p>
        </div>
        @if ($sub)
            <div class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm">
                <span class="font-medium">{{ $sub->plan->name }}</span>
                @if ($sub->status->value === 'trialing' && $sub->trial_ends_at)
                    <span class="text-slate-600">· trial ends {{ $sub->trial_ends_at->ist()->format('d M Y') }}
                        ({{ max(0, (int) now()->diffInDays($sub->trial_ends_at, false)) }} days left)</span>
                @endif
            </div>
        @endif
    </div>

    <x-auction-cards :auctions="$activeAuctions" :org="$currentOrg" />

    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['Open RFQs', $openRfqs],
            ['Live auctions', $liveAuctions],
            ['Auctions this month', $auctionsThisMonth],
            ['Suppliers in your list', $suppliersCount],
        ] as [$label, $value])
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <p class="text-sm text-slate-600">{{ $label }}</p>
                <p class="mt-1 text-3xl font-semibold tabular-nums">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    @if ($recentRfqs->isEmpty())
        <div class="mt-8 rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center">
            <h2 class="text-lg font-semibold">Get ready for your first auction</h2>
            <p class="mx-auto mt-2 max-w-md text-sm text-slate-600">
                Add the suppliers you buy from. You'll invite them to quote and bid when you create a requirement.
            </p>
            <a href="{{ route('buyer.suppliers.index') }}" class="mt-4 inline-block rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Manage suppliers</a>
        </div>
    @else
        <div class="mt-8 rounded-xl border border-slate-200 bg-white">
            <h2 class="border-b border-slate-200 px-5 py-3 font-semibold">Recent RFQs</h2>
            <ul class="divide-y divide-slate-100">
                @foreach ($recentRfqs as $rfq)
                    <li class="flex items-center justify-between px-5 py-3 text-sm">
                        <a href="{{ route('buyer.rfqs.show', $rfq->id) }}" class="hover:underline"><span class="text-slate-500">{{ $rfq->ref_no }}</span> · {{ $rfq->title }}</a>
                        <x-status-badge :status="$rfq->displayStatus()" />
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
@endsection
