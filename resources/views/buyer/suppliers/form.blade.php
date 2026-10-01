@extends('layouts.app')

@section('title', $entry->exists ? 'Edit supplier' : 'Add supplier')

@section('content')
    <x-breadcrumb :items="[['Suppliers', route('buyer.suppliers.index')]]" :current="$entry->exists ? 'Edit' : 'Add'" />
    <x-page-header :title="$entry->exists ? 'Edit '.$entry->company_name : 'Add a supplier'" subtitle="We'll send RFQ invites to this mobile (WhatsApp) and email. Add at least one." />

    <form method="POST" action="{{ $entry->exists ? route('buyer.suppliers.update', $entry->id) : route('buyer.suppliers.store') }}"
          class="max-w-2xl space-y-4 rounded-2xl border border-slate-200 bg-white shadow-sm p-5">
        @csrf
        @if ($entry->exists) @method('PUT') @endif

        <div class="grid gap-4 sm:grid-cols-2">
            <x-field name="company_name" label="Company name" :value="$entry->company_name" required maxlength="150" />
            <x-field name="contact_name" label="Contact person" :value="$entry->contact_name" maxlength="100" />
            <x-field name="contact_phone" label="Mobile (WhatsApp)" type="tel" :value="$entry->contact_phone" maxlength="14" inputmode="numeric" placeholder="98XXXXXXXX" />
            <x-field name="contact_email" label="Email" type="email" :value="$entry->contact_email" maxlength="190" />
            <x-field name="tag" label="Tag (optional)" :value="$entry->tag" maxlength="50" placeholder="boxes, steel, transport…" hint="Group suppliers to invite them together." />
        </div>
        <div>
            <label for="notes" class="block text-sm font-medium text-slate-700">Notes (only you see this)</label>
            <textarea id="notes" name="notes" rows="3" maxlength="1000" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2">{{ old('notes', $entry->notes) }}</textarea>
            @error('notes') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 pt-2">
            <div class="w-full sm:w-48"><x-button>{{ $entry->exists ? 'Save changes' : 'Add supplier' }}</x-button></div>
        </div>
    </form>

    @if ($entry->exists)
        <form method="POST" action="{{ route('buyer.suppliers.destroy', $entry->id) }}" class="mt-4 max-w-2xl"
              data-confirm="Remove this supplier from your list?">
            @csrf @method('DELETE')
            <button class="text-sm text-red-700 hover:underline">Remove from my list</button>
        </form>
    @endif
@endsection
