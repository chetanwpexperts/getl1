@extends('layouts.app')

@section('title', 'Company profile')

@section('content')
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Company profile</h1>
            <p class="mt-1 text-sm text-slate-600">
                @if ($org->isSupplier())
                    Buyers see your company name, city, categories and verified badge.
                @else
                    Used on your RFQs and purchase orders.
                @endif
            </p>
        </div>
        @if ($org->isSupplier())
            @if ($org->isVerified())
                <span class="rounded-full bg-emerald-100 px-3 py-1 text-sm font-medium text-emerald-800">✓ Verified business</span>
            @else
                <a href="{{ route('supplier.documents.index') }}" class="rounded-full bg-amber-100 px-3 py-1 text-sm font-medium text-amber-900 hover:bg-amber-200">Not verified · upload documents</a>
            @endif
        @endif
    </div>

    <form method="POST" action="{{ route('company.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('PUT')

        <fieldset @disabled(! $canEdit) class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5">
            <legend class="px-1 text-sm font-semibold">Business details</legend>
            @unless ($canEdit)
                <p class="mb-4 text-sm text-slate-600">Only your company admin can change these.</p>
            @endunless
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="name" label="Company name" :value="$org->name" required maxlength="150" />
                <x-field name="city" label="City" :value="$org->city" required maxlength="100" />
                <x-field name="gstin" label="GSTIN" :value="$org->gstin" maxlength="15" class="uppercase" hint="15 characters. We check the format and check digit." />
                <x-field name="pan" label="PAN" :value="$org->pan" maxlength="10" class="uppercase" />
                <x-field name="udyam_no" label="Udyam number (optional)" :value="$org->udyam_no" maxlength="19" class="uppercase" placeholder="UDYAM-PB-01-0012345" />
                <x-field name="state" label="State" :value="$org->state" maxlength="100" />
                <x-field name="address" label="Address" :value="$org->address" maxlength="255" />
                <x-field name="pincode" label="PIN code" :value="$org->pincode" maxlength="6" inputmode="numeric" />
                <x-field name="email" label="Company email" type="email" :value="$org->email" maxlength="190" />
                <x-field name="phone" label="Company mobile" type="tel" :value="$org->phone" maxlength="10" inputmode="numeric" />
            </div>
            @if ($org->isSupplier() && $org->isVerified())
                <p class="mt-4 text-xs text-amber-800">Changing company name, GSTIN or PAN removes your verified badge until we re-check your documents.</p>
            @endif
        </fieldset>

        @if ($org->isSupplier())
            <fieldset class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5">
                <legend class="px-1 text-sm font-semibold">What do you supply?</legend>
                <p class="mb-4 text-sm text-slate-600">Buyers find you by these categories. Pick up to 20.</p>
                @error('categories') <p class="mb-3 text-sm text-red-600">{{ $message }}</p> @enderror
                @php $chosen = old('categories', $selected); @endphp
                <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($categories as $parent)
                        <div>
                            <p class="text-sm font-medium text-slate-800">{{ $parent->name }}</p>
                            <div class="mt-2 space-y-1.5">
                                @foreach ($parent->children as $child)
                                    <label class="flex items-center gap-2 text-sm text-slate-700">
                                        <input type="checkbox" name="categories[]" value="{{ $child->id }}" class="rounded border-slate-300"
                                               @checked(in_array($child->id, array_map('intval', (array) $chosen), true))>
                                        {{ $child->name }}
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </fieldset>
        @endif

        @if ($org->isBuyer())
            <fieldset @disabled(! $canEdit) class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5">
                <legend class="px-1 text-sm font-semibold">Awards and purchase orders</legend>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="award_approval_limit" class="block text-sm font-medium text-slate-700">Approval needed for awards from (₹, before GST)</label>
                        <input id="award_approval_limit" name="award_approval_limit" inputmode="decimal" placeholder="0"
                               value="{{ old('award_approval_limit', $org->award_approval_limit !== null ? rtrim(rtrim((string) $org->award_approval_limit, '0'), '.') : '') }}"
                               class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20">
                        <p class="mt-1 text-xs text-slate-500">Applies when your team has an Approver. Leave empty or 0 to approve every award. Below this amount, the PO goes out straight away.</p>
                        @error('award_approval_limit') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label for="po_terms" class="block text-sm font-medium text-slate-700">Standard terms printed on every purchase order (one per line)</label>
                        <textarea id="po_terms" name="po_terms" rows="4" maxlength="3000"
                                  placeholder="Please quote this PO number on your invoice and delivery challan.&#10;Material will be inspected on receipt."
                                  class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20">{{ old('po_terms', $org->po_terms) }}</textarea>
                        @error('po_terms') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </fieldset>
        @endif

        @if ($canEdit)
            <div class="max-w-xs"><x-button>Save profile</x-button></div>
        @endif
    </form>
@endsection
