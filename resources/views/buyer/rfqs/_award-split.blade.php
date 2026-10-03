{{-- Item-wise award split across suppliers: one card, a row per supplier/PO, decided together. --}}
@php
    $inr = fn ($v) => \App\Support\Money::inr($v);
    $first = $currentAwards->first();
    $pending = $first->isPending();
    $allSent = $currentAwards->every(fn ($a) => $a->status->value === 'po_sent');
    $input = 'block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20';
@endphp

<section class="mt-6 rounded-xl border {{ $pending ? 'border-amber-300 bg-amber-50' : 'border-emerald-300 bg-emerald-50' }} p-5">
    <p class="text-xs font-semibold uppercase tracking-wide {{ $pending ? 'text-amber-800' : 'text-emerald-800' }}">
        {{ $pending ? 'Waiting for approval' : ($allSent ? 'Purchase orders issued' : 'Approved, issuing purchase orders') }}
    </p>
    <h2 class="mt-1 text-lg font-semibold">Awarded item by item to {{ $currentAwards->count() }} suppliers</h2>
    <p class="mt-1 text-sm text-slate-700">
        {{ $inr($currentAwards->sum('total')) }} before GST · <span class="font-semibold">{{ $inr($currentAwards->sum('grand_total')) }}</span> total incl. GST
    </p>
    <p class="mt-1 text-xs text-slate-600">
        Awarded by {{ $first->awarder?->name }} on {{ $first->created_at->ist()->format('d M Y, h:i A') }} IST
        @if (! $pending && $first->approver) · approved by {{ $first->approver->name }} @elseif (! $pending) · no approval required @endif
    </p>
    @if ($first->reason)<p class="mt-2 text-sm"><span class="font-medium">Reason for not choosing L1 on some items:</span> {{ $first->reason }}</p>@endif
    @if ($first->remarks)<p class="mt-1 text-sm"><span class="font-medium">Remarks:</span> {{ $first->remarks }}</p>@endif
    @if ($first->decision_note)<p class="mt-1 text-sm"><span class="font-medium">Approver's note:</span> {{ $first->decision_note }}</p>@endif

    <div class="mt-4 overflow-hidden rounded-xl border border-white/60 bg-white">
        <ul class="divide-y divide-slate-100 text-sm">
            @foreach ($currentAwards as $a)
                @php $items = collect($a->lines['items'] ?? []); @endphp
                <li class="flex flex-wrap items-start justify-between gap-3 px-4 py-3">
                    <div class="min-w-0">
                        <p class="font-medium">{{ $a->supplier->name }}</p>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{ $items->count() }} {{ \Illuminate\Support\Str::plural('item', $items->count()) }}:
                            {{ $items->map(fn ($l) => $l['name'].(isset($l['rank']) && $l['rank'] > 1 ? ' (L'.$l['rank'].')' : ''))->implode(', ') }}
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="tabular-nums">{{ $inr($a->total) }} <span class="text-xs text-slate-500">+ GST</span></p>
                        @if ($a->status->value === 'po_sent')
                            <a href="{{ route('buyer.orders.show', $a->id) }}" class="mt-1 inline-block text-xs font-semibold text-emerald-700 hover:underline">{{ $a->po_number }}</a>
                            <a href="{{ route('buyer.awards.po', $a->id) }}" class="ml-1 text-xs text-slate-500 hover:underline">PDF</a>
                            <p class="text-xs {{ $a->supplier_accepted_at ? 'text-emerald-700' : 'text-slate-500' }}">{{ $a->supplier_accepted_at ? '✓ Accepted' : 'Waiting for acceptance' }}</p>
                        @elseif (! $pending)
                            <p class="mt-1 text-xs text-slate-500">PO being generated…</p>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </div>

    @if ($pending)
        @if ($canDecide)
            <div class="mt-4 grid gap-3 border-t border-amber-200 pt-4 sm:grid-cols-2">
                <form method="POST" action="{{ route('buyer.awards.approve', $first->id) }}" class="space-y-2" data-confirm="Approve and send all {{ $currentAwards->count() }} purchase orders?">
                    @csrf
                    <input name="decision_note" maxlength="1000" placeholder="Comment (optional)" class="{{ $input }}">
                    <button class="w-full rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Approve and send {{ $currentAwards->count() }} POs</button>
                </form>
                <form method="POST" action="{{ route('buyer.awards.reject', $first->id) }}" class="space-y-2">
                    @csrf
                    <input name="decision_note" required minlength="5" maxlength="1000" placeholder="Reason for rejecting (required)" class="{{ $input }}">
                    @error('decision_note') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    <button class="w-full rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">Reject all</button>
                </form>
            </div>
            <p class="mt-2 text-xs text-amber-900">The split is decided as a whole, so every supplier gets its PO at the same time.</p>
        @else
            <p class="mt-3 text-sm text-amber-900">
                {{ $first->awarded_by === auth()->id() ? 'You made this award, so a colleague with approval rights must approve it.' : 'Only an approver or admin can approve this award.' }}
                Approvers were emailed. The POs go out automatically once approved.
            </p>
        @endif
    @endif
</section>
