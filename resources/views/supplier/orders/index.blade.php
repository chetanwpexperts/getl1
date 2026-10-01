@extends('layouts.app')

@section('title', 'Orders')

@section('content')
    <x-page-header title="Purchase orders" subtitle="Orders you've won. Accept each one so the buyer knows you've received it." />

    <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full min-w-[720px] text-sm">
            <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                <tr><th class="px-5 py-3.5">PO</th><th class="px-5 py-3.5">Buyer</th><th class="px-5 py-3.5 text-right">Value</th><th class="px-5 py-3.5">Status</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($orders as $o)
                    <tr class="hover:bg-slate-50">
                        <td class="px-5 py-3.5">
                            <a href="{{ route('supplier.orders.show', $o->id) }}" class="font-medium hover:underline">{{ $o->po_number }}</a>
                            <span class="block text-xs text-slate-500">{{ $o->rfq?->title }} · {{ $o->po_sent_at?->ist()->format('d M Y') }}</span>
                        </td>
                        <td class="px-5 py-3.5">{{ $o->rfq?->organization?->name }}</td>
                        <td class="px-5 py-3.5 text-right tabular-nums">{{ \App\Support\Money::inr($o->grand_total) }}</td>
                        <td class="px-5 py-3.5">
                            @if ($o->supplier_accepted_at)
                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800">Accepted</span>
                            @else
                                <a href="{{ route('supplier.orders.show', $o->id) }}" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900 hover:bg-amber-200">Accept now</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-10 text-center text-slate-600">No orders yet. When a buyer awards you an RFQ, the purchase order appears here.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $orders->links() }}</div>
@endsection
