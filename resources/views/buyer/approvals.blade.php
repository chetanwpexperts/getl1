@extends('layouts.app')

@section('title', 'Approvals')

@section('content')
    <x-page-header title="Approvals" subtitle="Awards waiting for a decision. Approving sends the purchase order to the supplier automatically." />

    <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full min-w-[720px] text-sm">
            <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                <tr><th class="px-5 py-3.5">RFQ</th><th class="px-5 py-3.5">Supplier</th><th class="px-5 py-3.5 text-right">Amount</th><th class="px-5 py-3.5">Awarded by</th><th class="px-5 py-3.5">Waiting for</th><th class="px-5 py-3.5"></th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($pending as $a)
                    <tr>
                        <td class="px-5 py-3.5"><span class="font-medium">{{ $a->rfq?->title }}</span><span class="block text-xs text-slate-500">{{ $a->rfq?->ref_no }}</span></td>
                        <td class="px-5 py-3.5">{{ $a->supplier?->name }} <span class="text-xs text-slate-500">L{{ $a->rank }}</span>
                            @if ($a->reason)<span class="block text-xs text-amber-800">Not L1: {{ \Illuminate\Support\Str::limit($a->reason, 80) }}</span>@endif
                        </td>
                        <td class="px-5 py-3.5 text-right tabular-nums">{{ \App\Support\Money::inr($a->total) }}<span class="block text-xs text-slate-500">{{ \App\Support\Money::inr($a->grand_total) }} incl. GST</span></td>
                        <td class="px-5 py-3.5">{{ $a->awarder?->name }}<span class="block text-xs text-slate-500">{{ $a->created_at->ist()->format('d M, h:i A') }}</span></td>
                        @php $lvl = \App\Services\ApprovalFlow::current($a); @endphp
                        <td class="px-5 py-3.5">{{ $lvl?->name ?? 'Approval' }}<span class="block text-xs text-slate-500">{{ $mine->contains($a) ? 'You' : ($lvl?->approver?->name ?? 'Any approver or admin') }}</span></td>
                        <td class="px-5 py-3.5 text-right">
                            <a href="{{ route('buyer.rfqs.show', $a->rfq_id) }}#award" class="rounded-lg {{ $mine->contains($a) ? 'bg-emerald-700 text-white hover:bg-emerald-800' : 'border border-slate-300 text-slate-700 hover:bg-slate-50' }} px-3 py-1.5 text-xs font-semibold">
                                {{ $mine->contains($a) ? 'Review' : 'View' }}
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-slate-600">Nothing waiting for approval.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
