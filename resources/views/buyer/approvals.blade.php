@extends('layouts.app')

@section('title', 'Approvals')

@section('content')
    <h1 class="text-2xl font-semibold tracking-tight">Approvals</h1>
    <p class="mt-1 text-sm text-slate-600">Awards waiting for a decision. Approving sends the purchase order to the supplier automatically.</p>

    <div class="mt-6 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full min-w-[720px] text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr><th class="px-4 py-3">RFQ</th><th class="px-4 py-3">Supplier</th><th class="px-4 py-3 text-right">Amount</th><th class="px-4 py-3">Awarded by</th><th class="px-4 py-3"></th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($pending as $a)
                    <tr>
                        <td class="px-4 py-3"><span class="font-medium">{{ $a->rfq?->title }}</span><span class="block text-xs text-slate-500">{{ $a->rfq?->ref_no }}</span></td>
                        <td class="px-4 py-3">{{ $a->supplier?->name }} <span class="text-xs text-slate-500">L{{ $a->rank }}</span>
                            @if ($a->reason)<span class="block text-xs text-amber-800">Not L1: {{ \Illuminate\Support\Str::limit($a->reason, 80) }}</span>@endif
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ \App\Support\Money::inr($a->total) }}<span class="block text-xs text-slate-500">{{ \App\Support\Money::inr($a->grand_total) }} incl. GST</span></td>
                        <td class="px-4 py-3">{{ $a->awarder?->name }}<span class="block text-xs text-slate-500">{{ $a->created_at->ist()->format('d M, h:i A') }}</span></td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('buyer.rfqs.show', $a->rfq_id) }}#award" class="rounded-lg {{ $mine->contains($a) ? 'bg-emerald-700 text-white hover:bg-emerald-800' : 'border border-slate-300 text-slate-700 hover:bg-slate-50' }} px-3 py-1.5 text-xs font-semibold">
                                {{ $mine->contains($a) ? 'Review' : 'View' }}
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-slate-600">Nothing waiting for approval.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
