@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    @php
        $sub = $liveSub;
        $hour = (int) now()->setTimezone('Asia/Kolkata')->format('G');
        $greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
        $first = \Illuminate\Support\Str::before(trim((string) auth()->user()->name), ' ');
        $saved = $savings['vs_last_count'] ? max(0, $savings['vs_last']) : max(0, $savings['vs_sealed']);
        $trialDays = $sub?->status->value === 'trialing' && $sub->trial_ends_at ? max(0, (int) now()->diffInDays($sub->trial_ends_at, false)) : null;
        $canBuy = in_array($currentRole?->value, ['buyer_admin', 'buyer_user'], true);
    @endphp

    <x-page-header :title="$greeting.', '.$first" :subtitle="$currentOrg->name.($currentOrg->city ? ' · '.$currentOrg->city : '')">
        @if ($plan)
            <a href="{{ route('buyer.billing.index') }}" class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3.5 py-1.5 text-sm shadow-sm hover:border-slate-300">
                <span class="size-2 rounded-full {{ $trialDays !== null ? 'bg-amber-500' : 'bg-emerald-500' }}"></span>
                <span class="font-medium">{{ $plan->name }}</span>
                @if ($trialDays !== null)<span class="text-slate-500">· trial, {{ $trialDays }} {{ \Illuminate\Support\Str::plural('day', $trialDays) }} left</span>@endif
            </a>
        @endif
    </x-page-header>

    <x-auction-cards :auctions="$activeAuctions" :org="$currentOrg" />

    <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-card label="Open RFQs" :value="$openRfqs" icon="rfqs" tone="sky" hint="Collecting quotes or in auction" :href="route('buyer.rfqs.index')" />
        <x-stat-card label="Live auctions" :value="$liveAuctions" icon="live" tone="emerald" :hint="$auctionsThisMonth.' scheduled this month'" :href="route('buyer.rfqs.index')" />
        <x-stat-card label="Saved this year" :value="\App\Support\Money::inr($saved, 0)" icon="savings" tone="violet"
                     :hint="$savings['awards'] ? $savings['awards'].' '.\Illuminate\Support\Str::plural('order', $savings['awards']).' this financial year' : 'Shows after your first award'" :href="route('buyer.reports.savings')" />
        <x-stat-card label="Active suppliers" :value="$suppliersCount" icon="suppliers" tone="amber" hint="In your supplier list" :href="route('buyer.suppliers.index')" />
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-setup-checklist :steps="$setup" />

            <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                    <h2 class="font-semibold">Recent RFQs</h2>
                    @if ($recentRfqs->isNotEmpty())<a href="{{ route('buyer.rfqs.index') }}" class="text-sm font-medium text-emerald-700 hover:underline">View all</a>@endif
                </div>
                @if ($recentRfqs->isEmpty())
                    <div class="px-6 py-10 text-center">
                        <span class="mx-auto flex size-12 items-center justify-center rounded-full bg-slate-100 text-slate-500"><x-icon name="rfqs" class="size-6" /></span>
                        <p class="mt-3 font-medium">No RFQs yet</p>
                        <p class="mx-auto mt-1 max-w-sm text-sm text-slate-500">Create a requirement, invite your suppliers to quote, then run a short live auction.</p>
                        @if ($canBuy)
                            <a href="{{ route('buyer.rfqs.create') }}" class="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800"><x-icon name="plus" class="size-4" /> New RFQ</a>
                        @endif
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="text-left text-xs uppercase tracking-wide text-slate-400">
                                <tr><th class="px-6 py-2.5 font-medium">RFQ</th><th class="px-3 py-2.5 font-medium">Status</th><th class="px-6 py-2.5 text-right font-medium">Created</th></tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($recentRfqs as $rfq)
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-6 py-3">
                                            <a href="{{ route('buyer.rfqs.show', $rfq->id) }}" class="block min-w-0">
                                                <span class="block truncate font-medium text-slate-900">{{ $rfq->title }}</span>
                                                <span class="block text-xs text-slate-500">{{ $rfq->ref_no }}</span>
                                            </a>
                                        </td>
                                        <td class="px-3 py-3"><x-status-badge :status="$rfq->displayStatus()" /></td>
                                        <td class="whitespace-nowrap px-6 py-3 text-right text-slate-500">{{ $rfq->created_at->ist()->format('d M') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </div>

        <aside class="space-y-6">
            @php $attention = $waitingApprovals + $closingSoon->count(); @endphp
            <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-100 px-6 py-4 font-semibold">Needs your attention</h2>
                @if ($attention === 0)
                    <p class="px-6 py-5 text-sm text-slate-500">You're all caught up.</p>
                @else
                    <ul class="divide-y divide-slate-100 text-sm">
                        @if ($waitingApprovals > 0)
                            <li>
                                <a href="{{ route('buyer.approvals.index') }}" class="flex items-center gap-3 px-6 py-3 hover:bg-slate-50">
                                    <span class="flex size-8 items-center justify-center rounded-lg bg-amber-50 text-amber-700"><x-icon name="approvals" class="size-5" /></span>
                                    <span class="flex-1"><span class="font-medium">{{ $waitingApprovals }} {{ \Illuminate\Support\Str::plural('award', $waitingApprovals) }}</span> waiting for approval</span>
                                    <x-icon name="right" class="size-4 text-slate-400" />
                                </a>
                            </li>
                        @endif
                        @foreach ($closingSoon as $rfq)
                            <li>
                                <a href="{{ route('buyer.rfqs.show', $rfq->id) }}" class="flex items-center gap-3 px-6 py-3 hover:bg-slate-50">
                                    <span class="flex size-8 items-center justify-center rounded-lg bg-sky-50 text-sky-700"><x-icon name="rfqs" class="size-5" /></span>
                                    <span class="min-w-0 flex-1"><span class="block truncate font-medium">{{ $rfq->title }}</span><span class="block text-xs text-slate-500">Quotes close {{ $rfq->quote_deadline->ist()->format('d M, h:i A') }}</span></span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex items-center justify-between">
                    <h2 class="font-semibold">This month</h2>
                    <a href="{{ route('buyer.billing.index') }}" class="text-sm font-medium text-emerald-700 hover:underline">Plan &amp; billing</a>
                </div>
                @php $limit = $allowance['limit']; $used = $allowance['used']; @endphp
                <div class="mt-4">
                    <div class="flex items-baseline justify-between text-sm">
                        <span class="text-slate-600">Live auctions used</span>
                        <span class="font-semibold tabular-nums">{{ $used }}{{ $limit !== null ? ' of '.$limit : '' }}</span>
                    </div>
                    @if ($limit)
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full {{ $used >= $limit ? 'bg-amber-500' : 'bg-emerald-600' }}" style="width: {{ min(100, (int) round($used / $limit * 100)) }}%"></div>
                        </div>
                    @endif
                    <p class="mt-2 text-xs text-slate-500">
                        {{ $limit === null ? 'Unlimited on your plan.' : ($allowance['left'] > 0 ? $allowance['left'].' left this month.' : 'Monthly auctions used up.') }}
                        @if ($allowance['credits'] > 0) {{ $allowance['credits'] }} extra {{ \Illuminate\Support\Str::plural('credit', $allowance['credits']) }} available.@endif
                    </p>
                </div>
            </section>

            @if ($canBuy)
                <section class="rounded-2xl border border-slate-200 bg-white p-2 shadow-sm">
                    <p class="px-4 pb-1 pt-3 text-xs font-semibold uppercase tracking-wider text-slate-400">Quick actions</p>
                    @foreach ([
                        [route('buyer.rfqs.create'), 'plus', 'Create an RFQ'],
                        [route('buyer.suppliers.create'), 'suppliers', 'Add a supplier'],
                        [route('buyer.suppliers.import'), 'documents', 'Import suppliers from Excel'],
                    ] as [$href, $icon, $label])
                        <a href="{{ $href }}" class="flex items-center gap-3 rounded-lg px-4 py-2.5 text-sm text-slate-700 hover:bg-slate-50">
                            <x-icon :name="$icon" class="size-5 text-slate-400" /> {{ $label }}
                        </a>
                    @endforeach
                </section>
            @endif
        </aside>
    </div>
@endsection
