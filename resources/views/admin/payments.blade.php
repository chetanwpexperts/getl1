@extends('layouts.admin')

@section('title', 'Payments')

@section('content')
    @php $kinds = ['subscription' => 'Plans', 'auction_credits' => 'Auction credits', 'ai_credits' => 'AI packs']; @endphp
    <h1 class="text-2xl font-semibold">Payments</h1>
    <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
        @foreach ($kinds as $k => $label)
            @include('admin._stat', ['label' => $label.' (all time)', 'value' => \App\Support\Money::inr((float) ($totals[$k]->amount ?? 0), 0), 'note' => ($totals[$k]->n ?? 0).' payments'])
        @endforeach
    </div>

    <div class="mt-5 flex gap-2 text-sm">
        @foreach (['paid' => 'Paid', 'created' => 'Started, not paid', 'failed' => 'Failed'] as $k => $label)
            <a href="{{ route('admin.payments', ['status' => $k]) }}" class="rounded-lg border px-3 py-1.5 {{ $status === $k ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white hover:bg-slate-50' }}">{{ $label }}</a>
        @endforeach
    </div>

    <div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr><th class="px-4 py-2.5">Date</th><th class="px-4 py-2.5">Company</th><th class="px-4 py-2.5">For</th><th class="px-4 py-2.5 text-right">Total</th><th class="px-4 py-2.5">Invoice</th><th class="px-4 py-2.5">Razorpay</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($payments as $p)
                    <tr>
                        <td class="whitespace-nowrap px-4 py-2.5 text-slate-600">{{ ($p->paid_at ?? $p->created_at)->ist()->format('d M Y, h:i A') }}</td>
                        <td class="px-4 py-2.5">@if ($p->organization)<a href="{{ route('admin.companies.show', $p->organization_id) }}" class="hover:underline">{{ $p->organization->name }}</a>@endif</td>
                        <td class="px-4 py-2.5">{{ $p->description() }}@if ($p->failure_reason)<span class="block text-xs text-red-600">{{ $p->failure_reason }}</span>@endif</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ \App\Support\Money::inr($p->total) }}</td>
                        <td class="px-4 py-2.5">@if ($p->invoice_number)<a href="{{ route('admin.payments.invoice', $p->id) }}" class="text-emerald-700 underline">{{ $p->invoice_number }}</a>@else — @endif</td>
                        <td class="px-4 py-2.5 font-mono text-xs text-slate-500">{{ $p->razorpay_payment_id ?? $p->razorpay_order_id ?? $p->razorpay_subscription_id }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">No payments here.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $payments->links() }}</div>
@endsection
