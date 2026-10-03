{{-- Supplier questions and buyer clarifications. Buyers see who asked; suppliers never see each other. --}}
@php
    $open = $rfq->isOpenForQuotes();
    $waiting = $questions->filter(fn ($q) => ! $q->isAnnouncement() && ! $q->isAnswered())->count();
    $field = 'block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20';
    $when = fn ($t) => $t->ist()->format('d M, h:i A');
@endphp

@if (! $rfq->isDraft() && ($questions->isNotEmpty() || ($open && $canManage)))
    <section id="questions" class="mt-8 scroll-mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
            <div>
                <h2 class="flex items-center gap-2 font-semibold">
                    Questions &amp; clarifications
                    @if ($waiting)
                        <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">{{ $waiting }} waiting</span>
                    @endif
                </h2>
                <p class="mt-0.5 text-xs text-slate-500">Answer to everyone and other suppliers see the question and answer, never who asked.</p>
            </div>
        </div>

        @if ($questions->isEmpty())
            <p class="px-5 py-6 text-sm text-slate-600">No questions yet. Suppliers can ask from their RFQ page until quotes close.</p>
        @else
            <ol class="divide-y divide-slate-100">
                @foreach ($questions as $q)
                    <li class="px-5 py-4 text-sm">
                        @if ($q->isAnnouncement())
                            <p class="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                <span class="rounded-full bg-sky-100 px-2 py-0.5 font-semibold text-sky-800">Clarification to all suppliers</span>
                                {{ $q->answerer?->name }} · {{ $when($q->answered_at) }}
                            </p>
                            <p class="mt-2 whitespace-pre-line text-slate-800">{{ $q->answer }}</p>
                        @else
                            <p class="text-xs text-slate-500"><span class="font-semibold text-slate-700">{{ $q->supplier?->name ?? 'Supplier' }}</span>@if ($q->asker) ({{ $q->asker->name }})@endif · {{ $when($q->created_at) }}</p>
                            <p class="mt-1.5 whitespace-pre-line font-medium text-slate-900">{{ $q->question }}</p>

                            @if ($q->isAnswered())
                                <div class="mt-3 rounded-xl border border-emerald-100 bg-emerald-50/60 px-4 py-3">
                                    <p class="flex flex-wrap items-center gap-2 text-xs text-emerald-900">
                                        <span class="font-semibold">Answer</span>
                                        <span class="rounded-full bg-white px-2 py-0.5 ring-1 ring-emerald-200">{{ $q->visibility === 'private' ? 'Only to '.($q->supplier?->name ?? 'this supplier') : 'Sent to every supplier' }}</span>
                                        {{ $q->answerer?->name }} · {{ $when($q->answered_at) }}
                                    </p>
                                    <p class="mt-1.5 whitespace-pre-line text-slate-800">{{ $q->answer }}</p>
                                </div>
                            @elseif ($open && $canManage)
                                <form method="POST" action="{{ route('buyer.rfqs.questions.answer', [$rfq->id, $q->id]) }}" class="mt-3 space-y-2">
                                    @csrf
                                    <textarea name="answer" rows="2" required minlength="5" maxlength="2000" class="{{ $field }}" placeholder="Your answer" aria-label="Answer to {{ $q->supplier?->name }}">{{ (int) old('question_id') === $q->id ? old('answer') : '' }}</textarea>
                                    <input type="hidden" name="question_id" value="{{ $q->id }}">
                                    @if ((int) old('question_id') === $q->id) @error('answer') <p class="text-sm text-red-600">{{ $message }}</p> @enderror @endif
                                    <div class="flex flex-wrap items-center gap-x-5 gap-y-2">
                                        <label class="flex items-center gap-2"><input type="radio" name="visibility" value="all" checked> Answer everyone <span class="text-xs text-slate-500">(recommended: same information for all)</span></label>
                                        <label class="flex items-center gap-2"><input type="radio" name="visibility" value="private"> Only {{ $q->supplier?->name ?? 'this supplier' }}</label>
                                        <button class="ml-auto rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Send answer</button>
                                    </div>
                                </form>
                            @else
                                <p class="mt-2 text-xs text-slate-500">{{ $open ? 'Waiting for an answer.' : 'Not answered before quotes closed.' }}</p>
                            @endif
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif

        @if ($open && $canManage)
            <details class="border-t border-slate-100 px-5 py-4 text-sm" @if ($errors->has('text')) open @endif>
                <summary class="cursor-pointer font-medium text-emerald-700">Send a clarification to all suppliers</summary>
                <form method="POST" action="{{ route('buyer.rfqs.clarifications.store', $rfq->id) }}" class="mt-3 space-y-2" data-confirm="Email this clarification to every supplier on this RFQ?">
                    @csrf
                    <textarea name="text" rows="3" required minlength="5" maxlength="2000" class="{{ $field }}" placeholder="e.g. Please quote for boxes with 3 colour printing; the drawing is attached.">{{ old('text') }}</textarea>
                    @error('text') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    <div class="flex flex-wrap items-center gap-3">
                        <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Send to all suppliers</button>
                        <span class="text-xs text-slate-500">Changing quantities or items? Cancel and re-issue the RFQ instead, so every quote is on the same basis.</span>
                    </div>
                </form>
            </details>
        @endif
    </section>
@endif
