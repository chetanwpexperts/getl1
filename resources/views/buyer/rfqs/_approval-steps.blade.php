{{-- Approval levels of an award, in order, with who decided and when. --}}
@if ($approvalSteps->count() > 0)
    <ol class="mt-4 space-y-2 border-t border-amber-200/70 pt-3 text-sm">
        @foreach ($approvalSteps as $st)
            @php
                [$mark, $cls] = match ($st->status) {
                    'approved' => ['✓', 'bg-emerald-600 text-white'],
                    'rejected' => ['×', 'bg-red-600 text-white'],
                    'cancelled' => ['–', 'bg-slate-200 text-slate-500'],
                    default => [$loop->iteration, $st->id === $approvalSteps->firstWhere('status', 'pending')?->id ? 'bg-amber-500 text-white' : 'border border-slate-300 bg-white text-slate-500'],
                };
            @endphp
            <li class="flex gap-3">
                <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full text-[11px] font-bold {{ $cls }}">{{ $mark }}</span>
                <span class="min-w-0">
                    <span class="font-medium">{{ $st->name }}</span>
                    <span class="text-slate-500">· {{ $st->why }}</span>
                    <span class="block text-xs text-slate-600">
                        @if ($st->status === 'approved') Approved by {{ $st->decider?->name }} · {{ $st->decided_at?->ist()->format('d M, h:i A') }}{{ $st->note ? ': '.$st->note : '' }}
                        @elseif ($st->status === 'rejected') Rejected by {{ $st->decider?->name }} · {{ $st->decided_at?->ist()->format('d M, h:i A') }}
                        @elseif ($st->status === 'cancelled') Not needed after the rejection
                        @else Waiting for {{ $st->approver?->name ?? 'any approver or admin' }}
                        @endif
                    </span>
                </span>
            </li>
        @endforeach
    </ol>
@endif
