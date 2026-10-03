@extends('layouts.app')

@section('title', 'Payments')

@section('content')
    @php
        $inr = fn ($v) => \App\Support\Money::inr($v);
        $tabs = \App\Http\Controllers\Buyer\PaymentController::TABS;
    @endphp

    <x-page-header title="Payments" subtitle="Supplier invoices across all purchase orders, with MSME due dates worked out for you." />

    @if ($stats['msme_overdue_n'] > 0)
        <div class="mb-6 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-900">
            <p class="font-semibold">{{ $stats['msme_overdue_n'] }} MSME {{ $stats['msme_overdue_n'] == 1 ? 'invoice is' : 'invoices are' }} overdue ({{ $inr($stats['msme_overdue']) }})</p>
            <p class="mt-1">MSME suppliers must be paid within 45 days of accepting the goods. Paying later also means the expense can't be deducted this year (Income Tax Act section 43B(h)). Please check with your CA.</p>
        </div>
    @endif

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-stat-card label="Outstanding" icon="billing" :value="$inr($stats['outstanding'])" hint="Approved or waiting for review" />
        <x-stat-card label="Due in the next 7 days" icon="clock" tone="amber" :value="$inr($stats['due_week'])" />
        <x-stat-card label="MSME overdue" icon="alert" :tone="$stats['msme_overdue'] > 0 ? 'amber' : 'emerald'" :value="$inr($stats['msme_overdue'])" hint="45-day rule (43B(h))" />
    </div>

    <nav class="mb-4 flex flex-wrap gap-1 border-b border-slate-200 text-sm" aria-label="Invoice status">
        @foreach ($tabs as $key => $label)
            <a href="{{ route('buyer.payments.index', ['tab' => $key]) }}"
               class="-mb-px border-b-2 px-4 py-2.5 font-medium {{ $tab === $key ? 'border-emerald-700 text-emerald-800' : 'border-transparent text-slate-500 hover:text-slate-800' }}">
                {{ $label }}@if (isset($counts[$key]) && $counts[$key] > 0) <span class="ml-1 rounded-full bg-slate-100 px-1.5 py-0.5 text-xs text-slate-700">{{ $counts[$key] }}</span>@endif
            </a>
        @endforeach
    </nav>

    <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full min-w-[820px] text-sm">
            <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                <tr>
                    <th class="px-5 py-3.5">Supplier</th><th class="px-5 py-3.5">Invoice</th><th class="px-5 py-3.5">PO</th>
                    <th class="px-5 py-3.5 text-right">Amount</th><th class="px-5 py-3.5">{{ $tab === 'paid' ? 'Paid' : 'Pay by' }}</th><th class="px-5 py-3.5"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($invoices as $inv)
                    @php $days = $inv->daysLeft(); @endphp
                    <tr class="hover:bg-slate-50 {{ $inv->isOverdue() && $inv->is_msme ? 'bg-red-50/50' : '' }}">
                        <td class="px-5 py-3.5">
                            {{ $inv->supplier?->name }}
                            @if ($inv->is_msme)<span class="ml-1 rounded-full bg-violet-100 px-1.5 py-0.5 text-[11px] font-medium text-violet-800">MSME</span>@endif
                        </td>
                        <td class="px-5 py-3.5">{{ $inv->invoice_number }}<span class="block text-xs text-slate-500">{{ $inv->invoice_date->format('d M Y') }}</span></td>
                        <td class="px-5 py-3.5"><a href="{{ route('buyer.orders.show', $inv->award_id) }}#invoices" class="hover:underline">{{ $inv->award?->po_number }}</a></td>
                        <td class="px-5 py-3.5 text-right font-medium tabular-nums">{{ $inr($inv->total_amount) }}</td>
                        <td class="px-5 py-3.5">
                            @if ($tab === 'paid')
                                {{ $inv->paid_on?->format('d M Y') }}
                                @if ($inv->due_date && $inv->paid_on && $inv->paid_on->gt($inv->due_date))<span class="block text-xs text-red-700">after due date</span>@endif
                            @elseif ($inv->due_date)
                                {{ $inv->due_date->format('d M Y') }}
                                <span class="block text-xs {{ $days < 0 ? 'font-semibold text-red-700' : ($days <= 7 ? 'text-amber-800' : 'text-slate-500') }}">{{ $days < 0 ? abs($days).' days overdue' : ($days === 0 ? 'Due today' : $days.' days left') }}</span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-right"><a href="{{ route('buyer.orders.show', $inv->award_id) }}#invoices" class="text-sm font-medium text-emerald-700 hover:underline">{{ $tab === 'review' ? 'Review' : 'Open' }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-12 text-center text-slate-600">
                        {{ ['due' => 'Nothing to pay right now. Approved invoices appear here with their due dates.', 'review' => 'No invoices waiting for review.', 'paid' => 'No payments recorded yet.', 'disputed' => 'No disputed invoices.'][$tab] }}
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $invoices->links() }}</div>

    <p class="mt-6 text-xs text-slate-500">
        Due dates: MSME suppliers (Udyam registered, or marked MSME in your supplier list) within the agreed credit period but never more than 45 days from accepting the goods; 15 days if no credit period was agreed. Others: the agreed credit period from the invoice date.
    </p>
@endsection
