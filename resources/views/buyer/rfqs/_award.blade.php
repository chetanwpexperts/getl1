@php
    $inr = fn ($v) => \App\Support\Money::inr($v);
    $input = 'block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20';
@endphp

<div id="award" class="scroll-mt-6">
    @error('award') <p class="mt-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</p> @enderror

    @if ($award)
        {{-- Current award: pending / approved / PO sent --}}
        @php
            $pending = $award->isPending();
            $sent = $award->status->value === 'po_sent';
        @endphp
        <section class="mt-6 rounded-xl border {{ $pending ? 'border-amber-300 bg-amber-50' : 'border-emerald-300 bg-emerald-50' }} p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide {{ $pending ? 'text-amber-800' : 'text-emerald-800' }}">
                        {{ $pending ? 'Waiting for approval' : ($sent ? 'Purchase order issued' : 'Approved, issuing purchase order') }}
                    </p>
                    <h2 class="mt-1 text-lg font-semibold">Awarded to {{ $award->supplier->name }} <span class="text-sm font-normal text-slate-600">(L{{ $award->rank }}{{ $award->source === 'auction' ? ', after live auction' : '' }})</span></h2>
                    <p class="mt-1 text-sm text-slate-700">
                        {{ $inr($award->total) }} before GST · <span class="font-semibold">{{ $inr($award->grand_total) }}</span> total incl. GST{{ (float) $award->freight_total > 0 ? ' and freight' : '' }}
                    </p>
                    <p class="mt-1 text-xs text-slate-600">
                        Awarded by {{ $award->awarder?->name }} on {{ $award->created_at->ist()->format('d M Y, h:i A') }} IST
                        @if (! $pending && $award->approver) · approved by {{ $award->approver->name }} @elseif (! $pending) · no approval required @endif
                    </p>
                    @if ($award->reason)<p class="mt-2 text-sm"><span class="font-medium">Reason for not choosing L1:</span> {{ $award->reason }}</p>@endif
                    @if ($award->remarks)<p class="mt-1 text-sm"><span class="font-medium">Remarks:</span> {{ $award->remarks }}</p>@endif
                    @if ($award->decision_note)<p class="mt-1 text-sm"><span class="font-medium">Approver's note:</span> {{ $award->decision_note }}</p>@endif
                </div>

                @if ($sent)
                    <div class="text-right">
                        <p class="text-sm">PO <span class="font-semibold">{{ $award->po_number }}</span> · {{ $award->po_sent_at?->ist()->format('d M Y') }}</p>
                        <a href="{{ route('buyer.awards.po', $award->id) }}" class="mt-2 inline-block rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Download PO (PDF)</a>
                        <p class="mt-2 text-xs {{ $award->supplier_accepted_at ? 'text-emerald-800' : 'text-slate-600' }}">
                            @if ($award->supplier_accepted_at)
                                ✓ Accepted by supplier on {{ $award->supplier_accepted_at->ist()->format('d M Y, h:i A') }}
                            @else
                                Waiting for the supplier to accept
                            @endif
                        </p>
                    </div>
                @elseif (! $pending)
                    <p class="text-sm text-emerald-800">The PO is being generated and emailed. This page updates on its own.</p>
                @endif
            </div>

            @if ($pending)
                @if ($canDecide)
                    <div class="mt-4 grid gap-3 border-t border-amber-200 pt-4 sm:grid-cols-2">
                        <form method="POST" action="{{ route('buyer.awards.approve', $award->id) }}" class="space-y-2" data-confirm="Approve and send the purchase order to {{ $award->supplier->name }}?">
                            @csrf
                            <input name="decision_note" maxlength="1000" placeholder="Comment (optional)" class="{{ $input }}">
                            <button class="w-full rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Approve and send PO</button>
                        </form>
                        <form method="POST" action="{{ route('buyer.awards.reject', $award->id) }}" class="space-y-2">
                            @csrf
                            <input name="decision_note" required minlength="5" maxlength="1000" placeholder="Reason for rejecting (required)" class="{{ $input }}">
                            @error('decision_note') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                            <button class="w-full rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">Reject</button>
                        </form>
                    </div>
                @else
                    <p class="mt-3 text-sm text-amber-900">
                        {{ $award->awarded_by === auth()->id() ? 'You made this award, so a colleague with approval rights must approve it.' : 'Only an approver or admin can approve this award.' }}
                        Approvers were emailed. The PO goes out automatically once approved.
                    </p>
                @endif
            @endif
        </section>

    @elseif ($candidates->isNotEmpty() && in_array($currentRole?->value, ['buyer_admin', 'buyer_user'], true))
        {{-- Award form --}}
        @php
            $l1 = $candidates->first();
            $source = $l1['source'];
        @endphp
        <section class="mt-6 rounded-xl border border-slate-200 bg-white">
            <div class="border-b border-slate-200 px-5 py-3">
                <h2 class="font-semibold">Award this RFQ</h2>
                <p class="mt-0.5 text-sm text-slate-600">
                    {{ $source === 'auction' ? 'Ranked by final auction price (before GST).' : 'Ranked by landed cost (price + GST + freight).' }}
                    L1 is selected. Awarding to anyone else needs a reason, which is kept in the audit record.
                </p>
            </div>
            <form method="POST" action="{{ route('buyer.awards.store', $rfq->id) }}" data-award-form
                  data-confirm="Award this RFQ? {{ $hasApprover ? 'It will go for approval first.' : 'The purchase order will be emailed to the supplier.' }}">
                @csrf
                <div class="divide-y divide-slate-100">
                    @foreach ($candidates as $c)
                        <label class="flex cursor-pointer flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm hover:bg-slate-50 has-[:checked]:bg-emerald-50">
                            <span class="flex items-center gap-3">
                                <input type="radio" name="supplier_org_id" value="{{ $c['supplier']->id }}" data-rank="{{ $c['rank'] }}"
                                       @checked((int) old('supplier_org_id', $l1['supplier']->id) === $c['supplier']->id)>
                                <span class="inline-flex w-9 justify-center rounded-md {{ $c['rank'] === 1 ? 'bg-emerald-700 text-white' : 'bg-slate-100 text-slate-700' }} px-1.5 py-0.5 text-xs font-semibold">L{{ $c['rank'] }}</span>
                                <span class="font-medium">{{ $c['supplier']->name }}</span>
                                @if ($c['supplier']->isVerified())<span class="text-xs text-emerald-700">✓ Verified</span>@endif
                            </span>
                            <span class="tabular-nums">
                                {{ $inr($c['basic']) }} <span class="text-xs text-slate-500">before GST</span>
                                @if ($c['landed'] !== null)<span class="ml-2 text-xs text-slate-500">{{ $inr($c['landed']) }} landed</span>@endif
                            </span>
                        </label>
                    @endforeach
                </div>
                <div class="grid gap-4 border-t border-slate-100 px-5 py-4 sm:grid-cols-2">
                    <div data-award-reason @if ((int) old('supplier_org_id', $l1['supplier']->id) === $l1['supplier']->id) hidden @endif>
                        <label for="reason" class="block text-sm font-medium text-slate-700">Why not L1? <span class="text-red-600">*</span></label>
                        <textarea id="reason" name="reason" rows="2" maxlength="1000" class="{{ $input }} mt-1" placeholder="e.g. L1 failed quality inspection last quarter">{{ old('reason') }}</textarea>
                        @error('reason') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="remarks" class="block text-sm font-medium text-slate-700">Remarks (optional, internal)</label>
                        <textarea id="remarks" name="remarks" rows="2" maxlength="1000" class="{{ $input }} mt-1">{{ old('remarks') }}</textarea>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-3 border-t border-slate-100 px-5 py-4">
                    <button class="rounded-lg bg-emerald-700 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Award</button>
                    <span class="text-xs text-slate-500">
                        @if ($hasApprover)
                            Needs approval{{ (float) $approvalLimit > 0 ? ' from '.$inr($approvalLimit) : '' }}; then the PO is emailed automatically.
                        @else
                            The PO (PDF) is generated and emailed to the supplier right away.
                        @endif
                    </span>
                </div>
            </form>
        </section>

    @elseif ($awardBlocker && ! $rfq->isCancelled() && $rfq->quotesAreUnsealed())
        <p class="mt-6 rounded-xl border border-slate-200 bg-white px-5 py-4 text-sm text-slate-600">{{ $awardBlocker }}</p>
    @endif

    @if ($rejectedAwards->isNotEmpty())
        <details class="mt-3 text-sm text-slate-600">
            <summary class="cursor-pointer">Earlier rejected awards ({{ $rejectedAwards->count() }})</summary>
            <ul class="mt-2 space-y-1">
                @foreach ($rejectedAwards as $r)
                    <li>{{ $r->supplier?->name }} · {{ $inr($r->total) }} · rejected by {{ $r->approver?->name }}: {{ $r->decision_note }}</li>
                @endforeach
            </ul>
        </details>
    @endif
</div>
