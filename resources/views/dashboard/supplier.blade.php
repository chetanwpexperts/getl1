@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    @php
        $hour = (int) now()->setTimezone('Asia/Kolkata')->format('G');
        $greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
        $first = \Illuminate\Support\Str::before(trim((string) auth()->user()->name), ' ');
    @endphp

    <x-page-header :title="$greeting.', '.$first" :subtitle="$currentOrg->name.($currentOrg->city ? ' · '.$currentOrg->city : '')">
        @if ($verified)
            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3.5 py-1.5 text-sm font-medium text-emerald-800 ring-1 ring-emerald-200">✓ Verified business</span>
        @else
            <a href="{{ route('supplier.documents.index') }}" class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-3.5 py-1.5 text-sm font-medium text-amber-900 ring-1 ring-amber-200 hover:bg-amber-100">Not verified · upload documents</a>
        @endif
    </x-page-header>

    <x-auction-cards :auctions="$activeAuctions" :org="$currentOrg" />

    <div class="mt-2 grid gap-5 sm:grid-cols-3">
        <x-stat-card label="New invitations" :value="$pendingInvites->count()" icon="bell" tone="sky" hint="Waiting for your reply" :href="route('supplier.rfqs.index')" />
        <x-stat-card label="RFQs you're in" :value="$acceptedCount" icon="rfqs" tone="emerald" hint="Accepted and open" :href="route('supplier.rfqs.index')" />
        <x-stat-card label="Purchase orders" :value="$ordersWon" icon="orders" tone="violet" hint="Orders you've won" :href="route('supplier.orders.index')" />
    </div>

    <div class="mt-8 grid gap-8 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                    <h2 class="font-semibold">Invitations</h2>
                    <a href="{{ route('supplier.rfqs.index') }}" class="text-sm font-medium text-emerald-700 hover:underline">All RFQs</a>
                </div>
                @forelse ($pendingInvites as $invite)
                    <a href="{{ route('supplier.rfqs.show', $invite->id) }}" class="flex items-center gap-4 border-b border-slate-100 px-6 py-3.5 text-sm last:border-0 hover:bg-slate-50">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-sky-50 text-sky-700"><x-icon name="rfqs" class="size-5" /></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium text-slate-900">{{ $invite->rfq->title }}</span>
                            <span class="block truncate text-xs text-slate-500">from {{ $invite->rfq->organization->name }}</span>
                        </span>
                        @if ($invite->rfq->quote_deadline)
                            <span class="hidden whitespace-nowrap text-xs text-slate-500 sm:block">Quote by {{ $invite->rfq->quote_deadline->ist()->format('d M, h:i A') }}</span>
                        @endif
                        <x-icon name="right" class="size-4 text-slate-400" />
                    </a>
                @empty
                    <div class="px-6 py-10 text-center">
                        <span class="mx-auto flex size-12 items-center justify-center rounded-full bg-slate-100 text-slate-500"><x-icon name="bell" class="size-6" /></span>
                        <p class="mt-3 font-medium">No new invitations</p>
                        <p class="mx-auto mt-1 max-w-sm text-sm text-slate-500">Buyers invite you by WhatsApp or email. New requests show up here.</p>
                    </div>
                @endforelse
            </section>
        </div>

        <aside class="space-y-6">
            <x-setup-checklist :steps="$setup" />

            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="font-semibold">How bidding works</h2>
                <ol class="mt-3 space-y-2.5 text-sm text-slate-600">
                    <li class="flex gap-3"><span class="font-semibold text-emerald-700">1</span> Accept the invitation and send your sealed quote.</li>
                    <li class="flex gap-3"><span class="font-semibold text-emerald-700">2</span> Join the live auction at the set time and lower your price.</li>
                    <li class="flex gap-3"><span class="font-semibold text-emerald-700">3</span> You only see your own rank (L1, L2…), never other names.</li>
                </ol>
                <a href="{{ route('supplier.auctions.practice') }}" class="mt-4 flex items-center justify-center gap-2 rounded-lg border border-emerald-600 px-4 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-50">Try a practice auction</a>
                <p class="mt-3 text-xs text-slate-500">GetL1 is always free for suppliers.</p>
            </section>
        </aside>
    </div>
@endsection
