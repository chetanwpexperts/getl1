<!DOCTYPE html>
<html lang="en-IN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    <title>@yield('title', config('site.name')) · {{ config('site.name') }}</title>
    <x-favicon />
    <x-pwa />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-white font-sans text-slate-900 antialiased">
    <div class="grid min-h-screen lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
        {{-- Brand panel (large screens) --}}
        <aside class="relative hidden overflow-hidden bg-emerald-900 p-12 text-emerald-50 lg:flex lg:flex-col lg:justify-between">
            <div class="absolute inset-0 bg-[radial-gradient(40rem_30rem_at_20%_0%,rgba(52,211,153,0.25),transparent)]"></div>
            <a href="{{ route('home') }}" class="relative" aria-label="{{ config('site.name') }} home"><x-logo :dark="true" /></a>
            <div class="relative max-w-md">
                <p class="text-3xl font-bold leading-tight text-white">Make your suppliers compete. Buy at L1.</p>
                <ul class="mt-8 space-y-3 text-emerald-100">
                    <li class="flex gap-3"><span class="text-emerald-300">✓</span>Sealed quotes, live reverse auctions and POs in one place</li>
                    <li class="flex gap-3"><span class="text-emerald-300">✓</span>Suppliers bid from their phone, free</li>
                    <li class="flex gap-3"><span class="text-emerald-300">✓</span>Every quote, bid and approval on record</li>
                </ul>
            </div>
            <p class="relative text-sm text-emerald-200/80">Need help? <a href="mailto:{{ config('site.email') }}" class="underline">{{ config('site.email') }}</a></p>
        </aside>

        <main class="flex flex-col items-center justify-center px-4 py-10 sm:px-8">
            <a href="{{ route('home') }}" class="mb-8 lg:hidden" aria-label="{{ config('site.name') }} home"><x-logo /></a>
            <div class="w-full {{ $wide ?? false ? 'max-w-2xl' : 'max-w-md' }}">
                @if (session('status'))
                    <div class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
                @endif
                @yield('content')
            </div>
            <p class="mt-8 text-center text-sm"><x-install-app class="font-medium text-emerald-700 hover:underline" /></p>
            <p class="mt-4 text-center text-xs text-slate-500">
                <a href="{{ route('site.terms') }}" class="hover:underline">Terms</a> ·
                <a href="{{ route('site.privacy') }}" class="hover:underline">Privacy</a> ·
                <a href="{{ route('site.contact') }}" class="hover:underline">Contact</a>
            </p>
        </main>
    </div>
</body>
</html>
