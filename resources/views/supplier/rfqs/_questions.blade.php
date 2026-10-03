{{-- Questions and clarifications, supplier side. Other suppliers' names are never shown. --}}
@php
    $open = $rfq->isOpenForQuotes();
    $declined = $invite->status->value === 'declined';
    $canAsk = $open && ! $declined;
    $when = fn ($t) => $t->ist()->format('d M, h:i A');
@endphp

@if ($questions->isNotEmpty() || $canAsk)
    <section id="questions" class="scroll-mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="font-semibold">Questions &amp; clarifications</h2>
            <p class="mt-0.5 text-xs text-slate-500">Ask the buyer about specs, quantities or terms. Answers to everyone are shared without your name.</p>
        </div>

        @if ($questions->isNotEmpty())
            <ol class="divide-y divide-slate-100">
                @foreach ($questions as $q)
                    @php $mine = $q->supplier_org_id === $invite->supplier_org_id; @endphp
                    <li class="px-5 py-4 text-sm">
                        @if ($q->isAnnouncement())
                            <p class="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                <span class="rounded-full bg-sky-100 px-2 py-0.5 font-semibold text-sky-800">Clarification from the buyer</span>
                                {{ $when($q->answered_at) }}
                            </p>
                            <p class="mt-2 whitespace-pre-line text-slate-800">{{ $q->answer }}</p>
                        @else
                            <p class="text-xs text-slate-500">{{ $mine ? 'Your question' : 'A supplier asked' }} · {{ $when($q->created_at) }}</p>
                            <p class="mt-1.5 whitespace-pre-line font-medium text-slate-900">{{ $q->question }}</p>
                            @if ($q->isAnswered())
                                <div class="mt-3 rounded-xl border border-emerald-100 bg-emerald-50/60 px-4 py-3">
                                    <p class="text-xs text-emerald-900"><span class="font-semibold">Buyer's answer</span> · {{ $when($q->answered_at) }}@if ($q->visibility === 'private') · only to you @endif</p>
                                    <p class="mt-1.5 whitespace-pre-line text-slate-800">{{ $q->answer }}</p>
                                </div>
                            @else
                                <p class="mt-2 text-xs text-amber-700">Waiting for the buyer's answer. You'll get an email.</p>
                            @endif
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif

        @if ($canAsk)
            <form method="POST" action="{{ route('supplier.rfqs.questions.store', $invite->id) }}" class="space-y-2 border-t border-slate-100 px-5 py-4">
                @csrf
                <label for="question" class="block text-sm font-medium text-slate-700">Ask a question</label>
                <textarea id="question" name="question" rows="2" required minlength="5" maxlength="2000"
                          class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20"
                          placeholder="e.g. Is printing on two sides or one?">{{ old('question') }}</textarea>
                @error('question') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                <div class="flex flex-wrap items-center gap-3">
                    <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Send question</button>
                    <span class="text-xs text-slate-500">The buyer sees your company name. Other suppliers never do.</span>
                </div>
            </form>
        @endif
    </section>
@endif
