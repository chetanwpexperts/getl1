@extends('layouts.site')

@section('title', config('site.mode') === 'website' ? 'Get early access' : 'Book a demo')
@section('description', 'Talk to the GetL1 team. Book a demo or request early access to reverse auctions for your purchase team.')

@php
    $in = 'mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20';
    $isSite = config('site.mode') === 'website';
@endphp

@section('content')
    <section class="mx-auto grid max-w-6xl gap-12 px-4 py-16 lg:grid-cols-5">
        <div class="lg:col-span-2">
            <h1 class="text-4xl font-bold tracking-tight">{{ $isSite ? 'Get early access' : 'Book a demo' }}</h1>
            <p class="mt-4 text-lg leading-relaxed text-slate-600">
                Tell us a little about what you buy. We'll call you within one working day, set up your account and help you run your first auction with your own suppliers.
            </p>
            <ul class="mt-8 space-y-3 text-slate-700">
                <li class="flex gap-3"><span class="text-emerald-700">✓</span>20-minute call, no obligation</li>
                <li class="flex gap-3"><span class="text-emerald-700">✓</span>We set up your first RFQ from your own Excel or list</li>
                <li class="flex gap-3"><span class="text-emerald-700">✓</span>Suppliers join free</li>
            </ul>
            <div class="mt-10 text-sm text-slate-600">
                <p>Prefer email? <a href="mailto:{{ config('site.email') }}" class="font-medium text-emerald-700 underline">{{ config('site.email') }}</a></p>
                @if (config('site.phone'))<p class="mt-1">Phone / WhatsApp: <a href="tel:{{ config('site.phone') }}" class="font-medium text-emerald-700 underline">{{ config('site.phone') }}</a></p>@endif
            </div>
        </div>

        <form method="POST" action="{{ route('site.contact.store') }}" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8 lg:col-span-3">
            @csrf
            <input type="hidden" name="t" value="{{ $startedAt }}">
            <input type="hidden" name="source" value="{{ old('source', $source) }}">
            <div class="hidden" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>

            <fieldset>
                <legend class="text-sm font-medium text-slate-700">I am a</legend>
                <div class="mt-2 grid grid-cols-2 gap-2">
                    @foreach (['buyer' => 'Buyer / purchase team', 'supplier' => 'Supplier'] as $k => $label)
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-300 px-3 py-2.5 text-sm has-[:checked]:border-emerald-600 has-[:checked]:bg-emerald-50">
                            <input type="radio" name="interest" value="{{ $k }}" @checked(old('interest', $interest) === $k) class="accent-emerald-700"> {{ $label }}
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label for="name" class="text-sm font-medium text-slate-700">Your name</label><input id="name" name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="name" class="{{ $in }}">@error('name')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror</div>
                <div><label for="company" class="text-sm font-medium text-slate-700">Company</label><input id="company" name="company" value="{{ old('company') }}" required maxlength="160" autocomplete="organization" class="{{ $in }}">@error('company')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror</div>
                <div><label for="email" class="text-sm font-medium text-slate-700">Work email</label><input id="email" type="email" name="email" value="{{ old('email') }}" required maxlength="190" autocomplete="email" class="{{ $in }}">@error('email')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror</div>
                <div><label for="phone" class="text-sm font-medium text-slate-700">Mobile</label><input id="phone" name="phone" value="{{ old('phone') }}" required inputmode="tel" maxlength="16" autocomplete="tel" placeholder="98765 43210" class="{{ $in }}">@error('phone')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror</div>
                <div><label for="city" class="text-sm font-medium text-slate-700">City</label><input id="city" name="city" value="{{ old('city') }}" maxlength="80" autocomplete="address-level2" class="{{ $in }}"></div>
                <div>
                    <label for="monthly_spend" class="text-sm font-medium text-slate-700">Monthly purchases (optional)</label>
                    <select id="monthly_spend" name="monthly_spend" class="{{ $in }}">
                        <option value="">Select</option>
                        @foreach (['under_5l' => 'Under ₹5 lakh', '5l_25l' => '₹5–25 lakh', '25l_1cr' => '₹25 lakh – 1 crore', 'over_1cr' => 'Over ₹1 crore'] as $k => $label)
                            <option value="{{ $k }}" @selected(old('monthly_spend') === $k)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div><label for="message" class="text-sm font-medium text-slate-700">What do you buy? (optional)</label><textarea id="message" name="message" rows="3" maxlength="2000" placeholder="e.g. packaging material, steel, chemicals, spare parts…" class="{{ $in }}">{{ old('message') }}</textarea></div>
            <button class="w-full rounded-lg bg-emerald-700 px-5 py-3 font-semibold text-white hover:bg-emerald-800">{{ $isSite ? 'Request early access' : 'Request a demo' }}</button>
            <p class="text-xs text-slate-500">We use these details only to contact you about GetL1. See our <a href="{{ route('site.privacy') }}" class="underline">privacy policy</a>.</p>
        </form>
    </section>
@endsection
