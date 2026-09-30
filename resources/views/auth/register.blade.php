@extends('layouts.guest', ['wide' => true])

@section('title', 'Create account')

@section('content')
    <h1 class="text-xl font-semibold">Create your GetL1 account</h1>
    <p class="mt-1 text-sm text-slate-600">Buyers get a 30-day free trial. Suppliers are always free.</p>

    <form method="POST" action="{{ route('register') }}" class="mt-6 space-y-5">
        @csrf

        @php $selected = old('account_type', $as); @endphp
        <fieldset>
            <legend class="text-sm font-medium text-slate-700">I want to</legend>
            <div class="mt-2 grid gap-3 sm:grid-cols-2">
                <label class="flex cursor-pointer gap-3 rounded-xl border border-slate-300 p-4 has-[:checked]:border-emerald-600 has-[:checked]:bg-emerald-50">
                    <input type="radio" name="account_type" value="buyer" class="mt-1" @checked($selected === 'buyer')>
                    <span>
                        <span class="block font-semibold">Buy</span>
                        <span class="block text-sm text-slate-600">Run auctions and get lower prices from suppliers</span>
                    </span>
                </label>
                <label class="flex cursor-pointer gap-3 rounded-xl border border-slate-300 p-4 has-[:checked]:border-emerald-600 has-[:checked]:bg-emerald-50">
                    <input type="radio" name="account_type" value="supplier" class="mt-1" @checked($selected === 'supplier')>
                    <span>
                        <span class="block font-semibold">Supply</span>
                        <span class="block text-sm text-slate-600">Get invited to quote and bid for buyers' orders</span>
                    </span>
                </label>
            </div>
            @error('account_type') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </fieldset>

        <div class="grid gap-4 sm:grid-cols-2">
            <x-field name="name" label="Your name" required autocomplete="name" />
            <x-field name="phone" label="Mobile (WhatsApp)" type="tel" required inputmode="numeric" maxlength="10" placeholder="98XXXXXXXX" />
            <x-field name="company_name" label="Company name" required autocomplete="organization" />
            <x-field name="city" label="City" required placeholder="Mohali" />
            <x-field name="gstin" label="GSTIN (optional)" maxlength="15" class="uppercase" hint="You can add it later." />
            <x-field name="email" label="Work email" type="email" required autocomplete="email" />
            <x-field name="password" label="Password" type="password" required autocomplete="new-password" hint="At least 8 characters." />
            <x-field name="password_confirmation" label="Confirm password" type="password" required autocomplete="new-password" />
        </div>

        <x-button>Create account</x-button>
    </form>

    <p class="mt-6 text-center text-sm text-slate-600">
        Already have an account?
        <a href="{{ route('login') }}" class="font-medium text-emerald-700 hover:underline">Log in</a>
    </p>
@endsection
