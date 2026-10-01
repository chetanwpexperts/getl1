@extends('layouts.app')

@section('title', $rfq->exists ? 'Edit RFQ' : 'New RFQ')

@section('content')
    @php
        $rows = old('items', $items->map(fn ($i) => [
            'name' => $i->name, 'spec' => $i->spec, 'qty' => rtrim(rtrim((string) $i->qty, '0'), '.'), 'unit' => $i->unit,
            'delivery_date' => $i->delivery_date?->format('Y-m-d'), 'last_purchase_price' => $i->last_purchase_price,
        ])->all());
        if (empty($rows)) {
            $rows = [['name' => '', 'spec' => '', 'qty' => '', 'unit' => 'pcs', 'delivery_date' => '', 'last_purchase_price' => '']];
        }
        $terms = old('terms', $rfq->terms ?? []);
        $input = 'mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20';
    @endphp

    <a href="{{ $rfq->exists ? route('buyer.rfqs.show', $rfq->id) : route('buyer.rfqs.index') }}" class="text-sm text-slate-600 hover:text-slate-900">← {{ $rfq->exists ? $rfq->ref_no : 'RFQs' }}</a>
    <h1 class="mt-2 text-2xl font-semibold">{{ $rfq->exists ? 'Edit RFQ' : 'New RFQ' }}</h1>
    <p class="mt-1 text-sm text-slate-600">Saved as a draft. You'll invite suppliers and publish on the next screen.</p>

    @if ($ai)
        @include('buyer.rfqs._ai-panel')
    @endif

    @if ($aiSource ?? null)
        @php $srcExt = $aiSource->input_file_path ? pathinfo($aiSource->input_file_path, PATHINFO_EXTENSION) : null; @endphp
        <details class="mt-5 rounded-2xl border border-slate-200 bg-white shadow-sm" @if (session('ai_job_id')) open @endif data-ai-original>
            <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-slate-800">Compare with your original</summary>
            <div class="border-t border-slate-100 p-4">
                @if (in_array($srcExt, ['jpg', 'png'], true))
                    <a href="{{ route('buyer.rfqs.ai.original', $aiSource->id) }}" target="_blank" rel="noopener" title="Open full size">
                        <img src="{{ route('buyer.rfqs.ai.original', $aiSource->id) }}" alt="Your uploaded list"
                             class="max-h-[28rem] w-auto rounded-lg border border-slate-200 bg-slate-50 object-contain" loading="lazy">
                    </a>
                    <p class="mt-2 text-xs text-slate-500">Click the photo to open it full size in a new tab.</p>
                @elseif ($srcExt === 'pdf')
                    <a href="{{ route('buyer.rfqs.ai.original', $aiSource->id) }}" target="_blank" rel="noopener" class="text-sm font-medium text-emerald-700 underline">Open your PDF in a new tab</a>
                @elseif ($srcExt === 'xlsx')
                    <a href="{{ route('buyer.rfqs.ai.original', $aiSource->id) }}" class="text-sm font-medium text-emerald-700 underline">Download your Excel file</a>
                @endif
                @if ($aiSource->input_text)
                    <pre class="{{ $srcExt ? 'mt-3' : '' }} max-h-64 overflow-auto whitespace-pre-wrap rounded-lg bg-slate-50 p-3 font-sans text-sm text-slate-700">{{ $aiSource->input_text }}</pre>
                @endif
            </div>
        </details>
    @endif

    @if (session('ai_warnings'))
        <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <p class="font-semibold">Please check these before saving:</p>
            <ul class="mt-1 list-disc pl-5">
                @foreach (session('ai_warnings') as $w)<li>{{ $w }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ $rfq->exists ? route('buyer.rfqs.update', $rfq->id) : route('buyer.rfqs.store') }}" class="mt-6 space-y-6">
        @csrf
        @if ($rfq->exists) @method('PUT') @endif
        @if ($aiSource ?? null)<input type="hidden" name="ai_job_id" value="{{ $aiSource->id }}">@endif

        <section class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5">
            <h2 class="text-sm font-semibold">Requirement</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-field name="title" label="Title" :value="$rfq->title" required maxlength="150" placeholder="e.g. Corrugated boxes for October" />
                </div>
                <div>
                    <label for="category_id" class="block text-sm font-medium text-slate-700">Category</label>
                    <select id="category_id" name="category_id" class="{{ $input }}">
                        <option value="">Select</option>
                        @foreach ($categories as $parent)
                            <optgroup label="{{ $parent->name }}">
                                @foreach ($parent->children as $child)
                                    <option value="{{ $child->id }}" @selected((int) old('category_id', $rfq->category_id) === $child->id)>{{ $child->name }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>
                <x-field name="delivery_location" label="Delivery location" :value="$rfq->delivery_location" maxlength="190" placeholder="Factory address or city" />
                <div class="sm:col-span-2">
                    <label for="description" class="block text-sm font-medium text-slate-700">Details for suppliers (optional)</label>
                    <textarea id="description" name="description" rows="3" maxlength="5000" class="{{ $input }}">{{ old('description', $rfq->description) }}</textarea>
                    @error('description') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold">Items</h2>
                <span class="text-xs text-slate-500">Last purchase price is private: suppliers never see it.</span>
            </div>
            @error('items') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror

            <div class="mt-4 space-y-3" data-items>
                @foreach ($rows as $i => $row)
                    @include('buyer.rfqs._item-row', ['i' => $i, 'row' => $row, 'doubt' => $aiUncertain[$i] ?? []])
                @endforeach
            </div>
            <template data-item-template>
                @include('buyer.rfqs._item-row', ['i' => '__i__', 'row' => ['name' => '', 'spec' => '', 'qty' => '', 'unit' => 'pcs', 'delivery_date' => '', 'last_purchase_price' => '']])
            </template>
            <button type="button" data-add-item class="mt-3 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium hover:bg-slate-50">+ Add item</button>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5">
            <h2 class="text-sm font-semibold">Terms and deadline</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="terms_payment" class="block text-sm font-medium text-slate-700">Payment terms</label>
                    <select id="terms_payment" name="terms[payment]" class="{{ $input }}">
                        <option value="">Select</option>
                        @foreach ($paymentTerms as $k => $label)<option value="{{ $k }}" @selected(($terms['payment'] ?? '') === $k)>{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label for="terms_freight" class="block text-sm font-medium text-slate-700">Freight</label>
                    <select id="terms_freight" name="terms[freight]" class="{{ $input }}">
                        <option value="">Select</option>
                        @foreach ($freightTerms as $k => $label)<option value="{{ $k }}" @selected(($terms['freight'] ?? '') === $k)>{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label for="terms_delivery" class="block text-sm font-medium text-slate-700">Delivery terms</label>
                    <input id="terms_delivery" name="terms[delivery]" value="{{ $terms['delivery'] ?? '' }}" maxlength="255" class="{{ $input }}" placeholder="e.g. Within 7 days of PO">
                </div>
                <div>
                    <label for="quote_deadline" class="block text-sm font-medium text-slate-700">Quote deadline (IST)</label>
                    <input id="quote_deadline" name="quote_deadline" type="datetime-local" class="{{ $input }}"
                           value="{{ old('quote_deadline', $rfq->quote_deadline?->ist()->format('Y-m-d\TH:i')) }}">
                    <p class="mt-1 text-xs text-slate-500">@php
                        $minQ = \App\Services\RfqService::minDeadlineMinutes();
                    @endphp
                    At least {{ $minQ % 60 === 0 ? ($minQ / 60).' '.\Illuminate\Support\Str::plural('hour', $minQ / 60) : $minQ.' minutes' }} after you publish. Quotes stay sealed until then.</p>
                    @error('quote_deadline') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="terms_other" class="block text-sm font-medium text-slate-700">Other terms (optional)</label>
                    <textarea id="terms_other" name="terms[other]" rows="2" maxlength="1000" class="{{ $input }}">{{ $terms['other'] ?? '' }}</textarea>
                </div>
            </div>
        </section>

        <div class="sm:w-48"><x-button>Save draft</x-button></div>
    </form>
@endsection
