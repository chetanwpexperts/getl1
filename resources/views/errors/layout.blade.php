<!DOCTYPE html>
<html lang="en-IN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') · {{ config('site.name', 'GetL1') }}</title>
    <x-favicon />
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen flex-col items-center justify-center bg-slate-50 px-4 font-sans text-slate-900 antialiased">
    <a href="/" aria-label="Home"><x-logo /></a>
    <div class="mt-8 w-full max-w-md rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm">
        <p class="text-sm font-semibold text-emerald-700">@yield('code')</p>
        <h1 class="mt-2 text-2xl font-bold tracking-tight">@yield('title')</h1>
        <p class="mt-3 text-slate-600">@yield('message')</p>
        <div class="mt-6 flex flex-wrap justify-center gap-3">
            @yield('actions')
            @hasSection('actions') @else
                <a href="{{ url()->previous() !== url()->current() ? url()->previous() : '/' }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold hover:bg-slate-50">Go back</a>
                <a href="/" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Home</a>
            @endif
        </div>
    </div>
    <p class="mt-6 text-sm text-slate-500">Need help? <a href="mailto:{{ config('site.email') }}" class="underline">{{ config('site.email') }}</a></p>
    <p class="mt-2 text-xs text-slate-400"><x-credit link-class="text-slate-500 hover:text-slate-700" /></p>
</body>
</html>
