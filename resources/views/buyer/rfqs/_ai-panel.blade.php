@php
    $usable = $ai['configured'] && $ai['can'];
    $open = $errors->has('ai_text') || $errors->has('ai_file');
    $isAdmin = auth()->user()->roleIn(app(\App\Support\Tenancy\CurrentOrganization::class)->get())?->value === 'buyer_admin';
    $pack = \App\Support\Money::inr((float) config('billing.ai_pack_price'), 0);
    $packReads = (int) config('billing.ai_pack_reads');
    $samples = [
        ['file' => 'GetL1-sample-handwritten-list.jpg', 'label' => 'Photo of a handwritten list', 'type' => 'JPG'],
        ['file' => 'GetL1-sample-indent.xlsx', 'label' => 'Excel indent sheet', 'type' => 'XLSX'],
        ['file' => 'GetL1-sample-requirement.pdf', 'label' => 'PDF requirement letter', 'type' => 'PDF'],
    ];
    $tips = [
        'WhatsApp or email text' => [
            'Copy the whole message and paste it. Hindi or Punjabi words are fine.',
            'One item per line works best: name, size, quantity, unit.',
            'Add the delivery place, date and payment terms if you know them.',
        ],
        'Excel sheet' => [
            'Keep a header row, e.g. Item, Specification, Qty, Unit, Needed by.',
            'One item per row. Up to 100 items from the first sheet.',
            'Remove totals, signatures and notes rows if you can.',
        ],
        'PDF' => [
            'A typed indent, purchase requisition or letter works best.',
            'Scanned PDFs are fine if the scan is sharp and straight.',
            'Up to 10 MB. Only the requirement, not a whole catalogue.',
        ],
        'Photo of a handwritten list' => [
            'Good light, no shadow of your phone or hand on the page.',
            'Hold the phone straight above the page, the whole list inside the frame.',
            'Write clearly: one item per line, with the quantity and unit next to it.',
            'Cross out mistakes fully. One list per photo.',
            'Anything hard to read is marked "Check this" in yellow for you to confirm.',
        ],
    ];
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
        @elseif (! $ai['can'])
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-emerald-200 bg-white px-4 py-3">
                <p class="text-sm text-slate-700">
                    @if ($ai['enabled'])
                        You've used all {{ $ai['limit'] }} AI reads included this month.
                    @else
                        AI reads are not included in your plan.
                    @endif
                    Get an AI pack: <strong>{{ $packReads }} reads for {{ $pack }}</strong>{{ config('billing.gst_enabled') ? ' + GST' : '' }}, works on any plan, never expires.
                </p>
                @if ($isAdmin)
                    <a href="{{ route('buyer.billing.index') }}#ai-reads" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Buy AI pack</a>
                @else
                    <span class="text-sm text-slate-500">Ask your company admin to buy one on the Billing page.</span>
                @endif
            </div>
            @error('ai_text') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
        @endif

        @if ($usable)
            <form method="POST" action="{{ route('buyer.rfqs.ai.store') }}" enctype="multipart/form-data" class="space-y-3" data-ai-form>
                @csrf
                <div class="flex items-center justify-between">
                    <label for="ai_text" class="text-sm font-medium text-slate-700">Your requirement</label>
                    <button type="button" class="text-xs font-semibold text-emerald-700 hover:underline" data-ai-example
                            data-example="Need 5000 corrugated box 5 ply 18x12x12 inch brown, 200 roll BOPP tape 48mm, 50 kg stretch film 23 micron. Delivery at Focal Point Ludhiana by 20 Nov. 30 days credit, freight included.">Try an example</button>
                </div>
                <textarea id="ai_text" name="ai_text" rows="5" maxlength="30000"
                          placeholder="Paste or type here: items, quantities, sizes, delivery place and date, payment terms…"
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
                    @if ($ai['use_credit'])
                        Uses 1 of your {{ $ai['credits'] }} prepaid AI {{ \Illuminate\Support\Str::plural('read', $ai['credits']) }}.
                    @elseif ($ai['limit'] !== null)
                        {{ $ai['left'] }} of {{ $ai['limit'] }} AI reads left this month{{ $ai['credits'] ? ', plus '.$ai['credits'].' prepaid' : '' }}.
                    @endif
                    Unclear or failed reads are not counted. Your file is processed securely and never shared with suppliers.
                </p>
            </form>
        @endif

        @if ($ai['configured'])
            <details class="mt-4 rounded-lg border border-emerald-100 bg-white" data-ai-tips>
                <summary class="cursor-pointer px-4 py-2.5 text-sm font-medium text-emerald-900">How to get the best result, with sample files</summary>
                <div class="border-t border-emerald-50 px-4 py-3">
                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach ($tips as $title => $lines)
                            <div>
                                <p class="text-sm font-semibold text-slate-800">{{ $title }}</p>
                                <ul class="mt-1 list-disc space-y-0.5 pl-5 text-sm text-slate-600">
                                    @foreach ($lines as $line)<li>{{ $line }}</li>@endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                    <p class="mt-4 text-sm font-semibold text-slate-800">Sample files</p>
                    <p class="text-sm text-slate-600">Download one to see what works well, or upload it above to try AI reading.</p>
                    <ul class="mt-2 flex flex-wrap gap-2">
                        @foreach ($samples as $s)
                            <li>
                                <a href="{{ asset('samples/'.$s['file']) }}" download
                                   class="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-1.5 text-sm text-slate-700 hover:border-emerald-300 hover:bg-emerald-50">
                                    <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-600">{{ $s['type'] }}</span>
                                    {{ $s['label'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-3 text-xs text-slate-500">AI only fills the form. Nothing goes to suppliers until you check it, save and publish.</p>
                </div>
            </details>
        @endif
    </div>
</details>
