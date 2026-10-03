@extends('layouts.app')

@section('title', $pr->pr_number)

@section('content')
    @php
        $inr = fn ($v) => \App\Support\Money::inr($v);
        $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
        $input = 'block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20';
        $steps = [
            ['Raised', true, $pr->requester?->name.' · '.$pr->created_at->ist()->format('d M Y, h:i A')],
            [$pr->status === 'rejected' ? 'Rejected' : 'Approved', $pr->decided_at !== null, $pr->decided_at ? $pr->decider?->name.' · '.$pr->decided_at->ist()->format('d M Y, h:i A') : 'Waiting for an approver'],
            ['Quotes from suppliers', in_array($progress['key'], ['sourcing', 'ordered', 'part_received', 'received'], true), $pr->converted_at ? 'Started '.$pr->converted_at->ist()->format('d M Y') : 'Not started yet'],
            ['Ordered', in_array($progress['key'], ['ordered', 'part_received', 'received'], true), $progress['pos'] ? implode(', ', $progress['pos']) : '—'],
            ['Received', $progress['key'] === 'received', $progress['key'] === 'part_received' ? 'Partly received' : '—'],
        ];
        if (in_array($pr->status, ['rejected', 'cancelled'], true)) {
            $steps = array_slice($steps, 0, $pr->status === 'rejected' ? 2 : 1);
        }
    @endphp

    <x-page-header :title="$pr->title" :subtitle="$pr->pr_number.($pr->department ? ' · '.$pr->department : '')">
        <a href="{{ route('buyer.requests.index') }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium shadow-sm hover:bg-slate-50">All requests</a>
    </x-page-header>

    @if ($errors->any())
        <p class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first() }}</p>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
                    <h2 class="font-semibold">Items</h2>
                    @include('buyer.requests._status', ['progress' => $progress])
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[560px] text-sm">
                        <thead class="text-left text-xs uppercase tracking-wide text-slate-400">
                            <tr><th class="px-5 py-3">Item</th><th class="px-5 py-3 text-right">Quantity</th><th class="px-5 py-3 text-right">Approx. rate</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($pr->items as $i)
                                <tr>
                                    <td class="px-5 py-3"><span class="font-medium">{{ $i['name'] }}</span>@if (! empty($i['spec']))<span class="block text-xs text-slate-500">{{ $i['spec'] }}</span>@endif</td>
                                    <td class="px-5 py-3 text-right tabular-nums">{{ $qty($i['qty']) }} {{ $i['unit'] }}</td>
                                    <td class="px-5 py-3 text-right tabular-nums text-slate-600">{{ $i['est_rate'] !== null ? $inr($i['est_rate']) : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        @if ($pr->estimated_total)
                            <tfoot><tr class="border-t border-slate-100"><td colspan="2" class="px-5 py-3 text-right text-slate-600">Approximate value</td><td class="px-5 py-3 text-right font-medium tabular-nums">{{ $inr($pr->estimated_total) }}</td></tr></tfoot>
                        @endif
                    </table>
                </div>
                <dl class="grid gap-4 border-t border-slate-100 px-5 py-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-xs text-slate-500">Needed by</dt><dd class="mt-0.5 font-medium">{{ $pr->needed_by?->format('d M Y') ?? 'No date given' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Requested by</dt><dd class="mt-0.5 font-medium">{{ $pr->requester?->name }}</dd></div>
                    @if ($pr->notes)<div class="sm:col-span-2"><dt class="text-xs text-slate-500">Notes</dt><dd class="mt-0.5 whitespace-pre-line">{{ $pr->notes }}</dd></div>@endif
                    @if ($pr->decision_note)<div class="sm:col-span-2"><dt class="text-xs text-slate-500">{{ $pr->status === 'cancelled' ? 'Cancellation note' : 'Approver’s note' }}</dt><dd class="mt-0.5">{{ $pr->decision_note }}</dd></div>@endif
                </dl>
            </section>

            @if ($canDecide)
                <section class="rounded-2xl border border-amber-300 bg-amber-50 p-5">
                    <h2 class="font-semibold">Your decision</h2>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <form method="POST" action="{{ route('buyer.requests.approve', $pr->id) }}" class="space-y-2">
                            @csrf
                            <input name="decision_note" maxlength="1000" placeholder="Comment (optional)" class="{{ $input }}">
                            <button class="w-full rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Approve</button>
                        </form>
                        <form method="POST" action="{{ route('buyer.requests.reject', $pr->id) }}" class="space-y-2">
                            @csrf
                            <input name="decision_note" required minlength="5" maxlength="1000" placeholder="Reason for rejecting (required)" class="{{ $input }}">
                            <button class="w-full rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">Reject</button>
                        </form>
                    </div>
                </section>
            @elseif ($pr->status === 'pending' && $pr->requested_by === auth()->id())
                <p class="rounded-xl border border-slate-200 bg-white px-5 py-4 text-sm text-slate-600">Waiting for an approver. You’ll get an alert and an email once it’s decided.</p>
            @endif

            @if ($canBuy)
                <form method="POST" action="{{ route('buyer.requests.convert') }}" class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-emerald-300 bg-emerald-50 p-5">
                    @csrf
                    <input type="hidden" name="ids[]" value="{{ $pr->id }}">
                    <p class="text-sm text-emerald-900">Approved and ready. To combine it with other requests, tick them together on the <a href="{{ route('buyer.requests.index', ['tab' => 'ready']) }}" class="font-medium underline">Ready to buy</a> list.</p>
                    <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Create RFQ</button>
                </form>
            @endif
        </div>

        <aside class="space-y-6">
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="font-semibold">Progress</h2>
                <ol class="mt-4 space-y-4">
                    @foreach ($steps as [$label, $done, $detail])
                        <li class="flex gap-3">
                            <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full text-[11px] font-bold {{ $done ? ($label === 'Rejected' ? 'bg-red-600 text-white' : 'bg-emerald-600 text-white') : 'border border-slate-300 bg-white text-slate-400' }}">{{ $done ? ($label === 'Rejected' ? '×' : '✓') : '' }}</span>
                            <span class="min-w-0 text-sm">
                                <span class="block font-medium {{ $done ? '' : 'text-slate-500' }}">{{ $label }}</span>
                                <span class="block text-xs text-slate-500">{{ $detail }}</span>
                            </span>
                        </li>
                    @endforeach
                    @if ($pr->status === 'cancelled')
                        <li class="flex gap-3"><span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-slate-500 text-[11px] font-bold text-white">×</span><span class="text-sm font-medium">Cancelled</span></li>
                    @endif
                </ol>
                @if ($pr->rfq_id && ! $isRequester)
                    <a href="{{ route('buyer.rfqs.show', $pr->rfq_id) }}" class="mt-4 inline-block text-sm font-medium text-emerald-700 hover:underline">Open {{ $pr->rfq?->ref_no ?? 'the RFQ' }} →</a>
                @endif
            </section>

            @if ($canTakeBack)
                <form method="POST" action="{{ route('buyer.requests.take-back', $pr->id) }}" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" data-confirm="Take {{ $pr->pr_number }} out of {{ $pr->rfq?->ref_no }}? It goes back to Ready to buy.">
                    @csrf
                    <h2 class="text-sm font-semibold">RFQ not going ahead?</h2>
                    <p class="mt-1 text-xs text-slate-500">Take this request out of {{ $pr->rfq?->ref_no }} and put it back on Ready to buy. Possible until its items are awarded.</p>
                    <button class="mt-3 w-full rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium hover:bg-slate-50">Take back from RFQ</button>
                </form>
            @endif

            @if ($canCancel)
                <form method="POST" action="{{ route('buyer.requests.cancel', $pr->id) }}" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" data-confirm="Cancel {{ $pr->pr_number }}?">
                    @csrf
                    <h2 class="text-sm font-semibold">No longer needed?</h2>
                    <input name="reason" maxlength="1000" placeholder="Reason (optional)" class="{{ $input }} mt-3">
                    <button class="mt-2 w-full rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium hover:bg-slate-50">Cancel request</button>
                </form>
            @endif
        </aside>
    </div>
@endsection
