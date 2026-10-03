@extends('layouts.app')

@section('title', 'New purchase request')

@section('content')
    @php
        $field = 'mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20';
        $rows = old('items', [['name' => '', 'qty' => '', 'unit' => 'pcs', 'spec' => '', 'est_rate' => '']]);
    @endphp
    <x-page-header title="New purchase request" subtitle="Say what you need and by when. It goes to an approver, then to the purchase team." />

    <form method="POST" action="{{ route('buyer.requests.store') }}" class="space-y-6">
        @csrf
        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="grid gap-5 sm:grid-cols-6">
                <div class="sm:col-span-4">
                    <label for="title" class="block text-sm font-medium">What is it for?</label>
                    <input id="title" name="title" value="{{ old('title') }}" required maxlength="150" class="{{ $field }}" placeholder="Packing material for November dispatches">
                    @error('title') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="needed_by" class="block text-sm font-medium">Needed by</label>
                    <input type="date" id="needed_by" name="needed_by" value="{{ old('needed_by') }}" min="{{ now()->setTimezone(config('app.display_timezone'))->toDateString() }}" class="{{ $field }}">
                    @error('needed_by') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="department" class="block text-sm font-medium">Department <span class="font-normal text-slate-500">(optional)</span></label>
                    <input id="department" name="department" value="{{ old('department') }}" maxlength="100" class="{{ $field }}" placeholder="Stores, Plant 2, Maintenance…">
                </div>
                <div class="sm:col-span-4">
                    <label for="notes" class="block text-sm font-medium">Notes for the approver <span class="font-normal text-slate-500">(optional)</span></label>
                    <input id="notes" name="notes" value="{{ old('notes') }}" maxlength="2000" class="{{ $field }}" placeholder="Why it's needed, preferred brand, current stock…">
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="font-semibold">Items</h2>
            @error('items') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            <div class="mt-4 space-y-3" data-items>
                @foreach ($rows as $i => $row)
                    @include('buyer.requests._item-row', ['i' => $i, 'row' => $row])
                @endforeach
            </div>
            <template data-item-template>
                @include('buyer.requests._item-row', ['i' => '__i__', 'row' => []])
            </template>
            <button type="button" data-add-item class="mt-3 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium hover:bg-slate-50">+ Add item</button>
        </section>

        <div class="flex justify-end gap-3">
            <a href="{{ route('buyer.requests.index') }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium hover:bg-slate-50">Cancel</a>
            <button class="rounded-lg bg-emerald-700 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Send request</button>
        </div>
    </form>
@endsection
