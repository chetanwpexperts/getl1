{{-- Item-wise award: a supplier per line (L1 preselected). One PO goes to each supplier chosen. --}}
@php
    $inr = fn ($v) => \App\Support\Money::inr($v);
    $source = $itemCandidates->first()['source'] ?? 'quote';
    // Each supplier's total if it took every line it quoted (for the "all to one supplier" shortcut).
    $lotTotals = [];
    foreach ($itemCandidates as $c) {
        foreach ($c['options'] as $o) {
            $id = $o['supplier']->id;
            $lotTotals[$id] ??= ['name' => $o['supplier']->name, 'total' => 0, 'lines' => 0];
            $lotTotals[$id]['total'] += $o['rate'] * (float) $c['item']->qty;
            $lotTotals[$id]['lines']++;
        }
    }
    $lines = $itemCandidates->count();
    $complete = collect($lotTotals)->filter(fn ($t) => $t['lines'] === $lines)->sortBy('total');
    $chosen = old('items', []);
@endphp

<section class="mt-8 rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="border-b border-slate-200 px-5 py-4">
        <h2 class="font-semibold">Award item by item</h2>
        <p class="mt-0.5 text-sm text-slate-600">
            {{ $source === 'auction' ? 'Ranked by final auction rate per unit (before GST).' : 'Ranked by quoted rate per unit (before GST).' }}
            The L1 for each item is selected. Each supplier you choose gets its own purchase order.
        </p>
    </div>

    <form method="POST" action="{{ route('buyer.awards.store', $rfq->id) }}" data-item-award
          data-confirm="Award these items? {{ $hasApprover ? 'It will go for approval first.' : 'Purchase orders will be emailed to the suppliers.' }}">
        @csrf
        <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-5 py-3 text-xs">
            <span class="text-slate-500">Quick choose:</span>
            <button type="button" data-all-l1 class="rounded-full border border-emerald-300 bg-emerald-50 px-3 py-1 font-medium text-emerald-800 hover:bg-emerald-100">Best rate on every item</button>
            @foreach ($complete as $id => $t)
                <button type="button" data-all-to="{{ $id }}" class="rounded-full border border-slate-300 px-3 py-1 hover:bg-slate-50">All to {{ $t['name'] }} · {{ $inr($t['total']) }}</button>
            @endforeach
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[720px] text-sm">
                <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                    <tr>
                        <th class="px-5 py-3.5">Item</th>
                        <th class="px-5 py-3.5 text-right">Qty</th>
                        <th class="px-5 py-3.5">Award to</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($itemCandidates as $c)
                        @php $item = $c['item']; @endphp
                        <tr class="align-top data-[off-l1]:bg-amber-50/60">
                            <td class="px-5 py-3.5">
                                <span class="font-medium">{{ $item->line_no }}. {{ $item->name }}</span>
                                @if ($item->spec)<span class="block max-w-sm truncate text-xs text-slate-500">{{ $item->spec }}</span>@endif
                            </td>
                            <td class="whitespace-nowrap px-5 py-3.5 text-right tabular-nums">{{ rtrim(rtrim(number_format((float) $item->qty, 3), '0'), '.') }} {{ $item->unit }}</td>
                            <td class="px-5 py-3.5">
                                @if ($c['options']->isEmpty())
                                    <span class="text-slate-500">No quotes for this item</span>
                                @else
                                    <select name="items[{{ $item->id }}]" data-item="{{ $item->id }}" aria-label="Supplier for {{ $item->name }}"
                                            class="block w-full max-w-md rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20">
                                        @foreach ($c['options'] as $o)
                                            <option value="{{ $o['supplier']->id }}" data-rank="{{ $o['rank'] }}" data-total="{{ round($o['rate'] * (float) $item->qty, 2) }}"
                                                @selected((int) ($chosen[$item->id] ?? $c['options']->first()['supplier']->id) === $o['supplier']->id)>
                                                L{{ $o['rank'] }} · {{ $o['supplier']->name }} · {{ $inr($o['rate']) }}/{{ $item->unit }} · {{ $inr($o['rate'] * (float) $item->qty) }}
                                            </option>
                                        @endforeach
                                    </select>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 bg-slate-50/60 px-5 py-4">
            <p class="text-sm">Total before GST <span class="ml-1 text-lg font-semibold tabular-nums" data-award-total>—</span></p>
            <p class="text-sm text-slate-600" data-award-pos></p>
        </div>

        <div class="grid gap-4 border-t border-slate-100 px-5 py-4 sm:grid-cols-2">
            <div data-award-reason hidden>
                <label for="reason" class="block text-sm font-medium text-slate-700">Why not L1 for the highlighted items? <span class="text-red-600">*</span></label>
                <textarea id="reason" name="reason" rows="2" maxlength="1000" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20"
                          placeholder="e.g. Keep all fasteners with one supplier for a single delivery">{{ old('reason') }}</textarea>
                @error('reason') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="remarks" class="block text-sm font-medium text-slate-700">Remarks (optional, internal)</label>
                <textarea id="remarks" name="remarks" rows="2" maxlength="1000" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20">{{ old('remarks') }}</textarea>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3 border-t border-slate-100 px-5 py-4">
            <button class="rounded-lg bg-emerald-700 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Award</button>
            <span class="text-xs text-slate-500">
                @if ($hasRules)
                    Approval as per your <a href="{{ route('buyer.approval-rules.index') }}" class="underline">approval rules</a>, on the combined amount; then the POs are emailed automatically.
                @elseif ($hasApprover)
                    Needs approval{{ (float) $approvalLimit > 0 ? ' from '.$inr($approvalLimit) : '' }} on the combined amount; then the POs are emailed automatically.
                @else
                    The POs (PDF) are generated and emailed to the suppliers right away.
                @endif
            </span>
        </div>
    </form>
</section>
