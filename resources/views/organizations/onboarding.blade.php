@extends('layouts.guest')

@section('title', 'Set up your company')

@section('content')
    <h1 class="text-xl font-semibold">Set up your company</h1>
    <p class="mt-1 text-sm text-slate-600">One quick step before you start.</p>

    <form method="POST" action="{{ route('onboarding.store') }}" class="mt-6 space-y-4">
        @csrf
        <div>
            <label for="account_type" class="block text-sm font-medium text-slate-700">Account type</label>
            <select id="account_type" name="account_type" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2">
                <option value="buyer" @selected(old('account_type') === 'buyer')>Buyer: I run auctions</option>
                <option value="supplier" @selected(old('account_type') === 'supplier')>Supplier: I quote and bid</option>
            </select>
        </div>
        <x-field name="company_name" label="Company name" required />
        <x-field name="city" label="City" required />
        <x-button>Continue</x-button>
    </form>
@endsection
