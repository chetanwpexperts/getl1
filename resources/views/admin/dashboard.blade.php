@extends('layouts.admin')

@section('title', 'Overview')

@section('content')
    @php $inr = fn ($v) => \App\Support\Money::inr($v, 0); @endphp
    <h1 class="text-2xl font-semibold">Overview</h1>
    <p class="mt-1 text-sm text-slate-600">{{ now()->ist()->format('l, d M Y · h:i A') }} IST. Month figures are from the 1st.</p>

    <div class="mt-6 grid grid-cols-2 gap-3 md:grid-cols-4">
        @include('admin._stat', ['label' => 'MRR', 'value' => $inr($stats['mrr']), 'note' => $stats['paid'].' paid '.\Illuminate\Support\Str::plural('company', $stats['paid'])])
        @include('admin._stat', ['label' => 'Revenue this month', 'value' => $inr($stats['revenue_month']), 'note' => 'Incl. credits and AI packs'])
        @include('admin._stat', ['label' => 'Buyers', 'value' => number_format($stats['buyers']), 'note' => $stats['trials'].' on trial'])
        @include('admin._stat', ['label' => 'Suppliers', 'value' => number_format($stats['suppliers']), 'note' => $stats['new_week'].' new companies this week'])
        @include('admin._stat', ['label' => 'RFQs this month', 'value' => number_format($stats['rfqs_month'])])
        @include('admin._stat', ['label' => 'Auctions this month', 'value' => number_format($stats['auctions_month']), 'note' => $stats['live_now'] ? $stats['live_now'].' live now' : null])
        @include('admin._stat', ['label' => 'PO value this month', 'value' => $inr($stats['po_value_month'])])
        @include('admin._stat', ['label' => 'AI reads this month', 'value' => number_format($stats['ai_reads_month']), 'note' => 'Cost '.\App\Support\Money::inr($stats['ai_cost_month'])])
    </div>

    @if ($stats['kyc_pending'] || $stats['leads_new'] || $stats['past_due'])
        <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <p class="font-semibold">Needs attention</p>
            <ul class="mt-1 space-y-0.5">
                @if ($stats['kyc_pending'])<li><a href="{{ route('admin.kyc.index') }}" class="underline">{{ $stats['kyc_pending'] }} KYC {{ \Illuminate\Support\Str::plural('document', $stats['kyc_pending']) }} to review</a></li>@endif
                @if ($stats['leads_new'])<li><a href="{{ route('admin.leads', ['status' => 'new']) }}" class="underline">{{ $stats['leads_new'] }} new {{ \Illuminate\Support\Str::plural('lead', $stats['leads_new']) }} to contact</a></li>@endif
                @if ($stats['past_due'])<li>{{ $stats['past_due'] }} {{ \Illuminate\Support\Str::plural('subscription', $stats['past_due']) }} with a failed renewal</li>@endif
            </ul>
        </div>
    @endif

    <div class="mt-6 grid gap-4 lg:grid-cols-2">
        <section class="rounded-xl border border-slate-200 bg-white">
            <h2 class="border-b border-slate-100 px-4 py-3 text-sm font-semibold">Newest companies</h2>
            <ul class="divide-y divide-slate-100 text-sm">
                @forelse ($recentOrgs as $o)
                    <li class="flex items-center justify-between gap-3 px-4 py-2.5">
                        <a href="{{ route('admin.companies.show', $o->id) }}" class="truncate font-medium hover:underline">{{ $o->name }}</a>
                        <span class="shrink-0 text-xs text-slate-500">{{ $o->type->label() }} · {{ $o->city }} · {{ $o->created_at->ist()->format('d M') }}</span>
                    </li>
                @empty
                    <li class="px-4 py-6 text-center text-slate-500">No companies yet.</li>
                @endforelse
            </ul>
        </section>
        <section class="rounded-xl border border-slate-200 bg-white">
            <h2 class="border-b border-slate-100 px-4 py-3 text-sm font-semibold">Latest payments</h2>
            <ul class="divide-y divide-slate-100 text-sm">
                @forelse ($recentPayments as $p)
                    <li class="flex items-center justify-between gap-3 px-4 py-2.5">
                        <span class="truncate"><span class="font-medium">{{ $p->organization?->name }}</span> <span class="text-slate-500">· {{ $p->description() }}</span></span>
                        <span class="shrink-0 tabular-nums">{{ \App\Support\Money::inr($p->total) }}</span>
                    </li>
                @empty
                    <li class="px-4 py-6 text-center text-slate-500">No payments yet.</li>
                @endforelse
            </ul>
        </section>
    </div>

    @if ($plans->isNotEmpty())
        <p class="mt-5 text-sm text-slate-600">Paid plans: @foreach ($plans as $name => $n){{ $name }} {{ $n }}@if (! $loop->last) · @endif @endforeach</p>
    @endif
@endsection
