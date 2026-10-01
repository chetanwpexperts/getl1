@php
    $usable = $ai['configured'] && $ai['enabled'] && $ai['left'] !== 0;
    $open = $errors->has('ai_text') || $errors->has('ai_file');
@endphp
<details class="group mt-6 rounded-xl border border-emerald-200 bg-gradient-to-br from-emerald-50 to-white" @if ($open || ! old('title')) open @endif>
    <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-2 px-5 py-4">
        <span>
            <span class="font-semibold text-emerald-900">✨ Create with AI</span>
            <span class="block text-sm text-emerald-900/80">Paste a WhatsApp message or email, or upload an Excel, PDF or photo of your list. AI fills the form; you check it.</span>
        </span>
        <span class="text-xs text-emerald-800 group-open:hidden">Show</span>
    </summary>

    <div class="border-t border-emerald-100 px-5 py-4">
        @if (! $ai['configured'])
            <p class="text-sm text-slate-600">AI reading is being set up. You can fill the form below in the meantime.</p>
        @elseif (! $ai['enabled'])
            <p class="text-sm text-slate-600">Available on the Growth plan and above. <a href="{{ route('buyer.billing.index') }}" class="font-medium text-emerald-700 underline">See plans</a></p>
        @elseif ($ai['left'] === 0)
            <p class="text-sm text-slate-600">You've used all {{ $ai['limit'] }} AI reads this month. <a href="{{ route('buyer.billing.index') }}" class="font-medium text-emerald-700 underline">Upgrade for more</a></p>
        @endif

        @if ($usable)
            <form method="POST" action="{{ route('buyer.rfqs.ai.store') }}" enctype="multipart/form-data" class="space-y-3" data-ai-form>
                @csrf
                <textarea name="ai_text" rows="5" maxlength="30000"
                          placeholder="e.g. Need 5000 corrugated boxes 5 ply 18x12x12 brown, 200 rolls BOPP tape 48mm, delivery Ludhiana by 20 Nov, 30 days credit"
                          class="block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20">{{ old('ai_text') }}</textarea>
                @error('ai_text') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                <div class="flex flex-wrap items-center gap-3">
                    <input type="file" name="ai_file" accept=".pdf,.xlsx,.jpg,.jpeg,.png"
                           class="text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-white file:px-3 file:py-2 file:text-sm file:font-medium file:ring-1 file:ring-slate-300">
                    <button class="rounded-lg bg-emerald-700 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-800" data-ai-submit>Fill the form with AI</button>
                </div>
                @error('ai_file') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                <p class="text-xs text-slate-500">
                    Excel, PDF, JPG or PNG up to 10 MB. Takes about 10–30 seconds.
                    @if ($ai['limit'] !== null) {{ $ai['left'] }} of {{ $ai['limit'] }} AI reads left this month. @endif
                    Your text is processed securely by our AI provider and never shared with suppliers.
                </p>
            </form>
        @endif
    </div>
</details>
