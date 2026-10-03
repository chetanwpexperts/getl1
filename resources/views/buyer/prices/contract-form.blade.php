@extends('layouts.app')

@section('title', 'New rate contract')

@section('content')
    @php
        $field = 'mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20';
        $rows = old('items', $prefill['items'] ?? [['name' => '', 'unit' => 'pcs', 'rate' => '', 'gst_rate' => '18', 'spec' => '']]);
    @endphp
    <x-page-header title="New rate contract" subtitle="Lock in agreed rates with a supplier for a period. The supplier is asked to confirm, and you're reminded before it ends." />

    <form method="POST" action="{{ route('buyer.contracts.store') }}" class="space-y-6">
        @csrf
        @if (! empty($prefill['award_id']))<input type="hidden" name="award_id" value="{{ $prefill['award_id'] }}">@endif
        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="grid gap-5 sm:grid-cols-6">
                <div class="sm:col-span-3">
                    <label for="title" class="block text-sm font-medium">Title</label>
                    <input id="title" name="title" value="{{ old('title', $prefill['title'] ?? '') }}" required maxlength="150" class="{{ $field }}" placeholder="Corrugated boxes, FY 2026-27">
                    @error('title') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-3">
                    <label for="supplier_org_id" class="block text-sm font-medium">Supplier</label>
                    <select id="supplier_org_id" name="supplier_org_id" required class="{{ $field }}">
                        <option value="">Choose…</option>
                        @foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected((int) old('supplier_org_id', $prefill['supplier_org_id'] ?? 0) === $s->id)>{{ $s->name }}</option>@endforeach
                    </select>
                    @error('supplier_org_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="valid_from" class="block text-sm font-medium">Valid from</label>
                    <input type="date" id="valid_from" name="valid_from" value="{{ old('valid_from', $prefill['valid_from']) }}" required class="{{ $field }}">
                    @error('valid_from') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="valid_to" class="block text-sm font-medium">Valid until</label>
                    <input type="date" id="valid_to" name="valid_to" value="{{ old('valid_to', $prefill['valid_to']) }}" required class="{{ $field }}">
                    @error('valid_to') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-6">
                    <label for="terms" class="block text-sm font-medium">Terms <span class="font-normal text-slate-500">(optional, shown to the supplier)</span></label>
                    <textarea id="terms" name="terms" rows="2" maxlength="3000" class="{{ $field }}" placeholder="Delivery within 7 days of each order; rates fixed for the period; freight included.">{{ old('terms') }}</textarea>
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="font-semibold">Items and agreed rates</h2>
            @error('items') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            <div class="mt-4 space-y-3" data-items>
                @foreach ($rows as $i => $row)
                    @include('buyer.prices._contract-row', ['i' => $i, 'row' => $row])
                @endforeach
            </div>
            <template data-item-template>
                @include('buyer.prices._contract-row', ['i' => '__i__', 'row' => []])
            </template>
            <button type="button" data-add-item class="mt-3 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium hover:bg-slate-50">+ Add item</button>
        </section>

        <div class="flex justify-end gap-3">
            <a href="{{ route('buyer.contracts.index') }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium hover:bg-slate-50">Cancel</a>
            <button class="rounded-lg bg-emerald-700 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Create and send to supplier</button>
        </div>
    </form>
@endsection
