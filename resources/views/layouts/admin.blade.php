<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Admin') · {{ config('site.name') }} Admin</title>
    <x-favicon />
    <x-pwa />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-900 antialiased" data-server-time="{{ now()->getTimestampMs() }}">
@php
    $items = [
        ['admin.dashboard', 'Overview', 'admin.dashboard'],
        ['admin.auctions', 'Live auctions', 'admin.auctions*'],
        ['admin.companies.index', 'Companies', 'admin.companies.*'],
        ['admin.users.index', 'Users', 'admin.users.*'],
        ['admin.kyc.index', 'KYC review', 'admin.kyc.*'],
        ['admin.leads', 'Leads', 'admin.leads*'],
        ['admin.payments', 'Payments', 'admin.payments*'],
        ['admin.ai', 'AI usage', 'admin.ai'],
        ['admin.audit', 'Audit log', 'admin.audit'],
        ['admin.security', 'Security log', 'admin.security'],
        ['admin.health', 'System health', 'admin.health'],
        ['admin.settings', 'Platform rules', 'admin.settings*'],
        ['admin.website', 'Website', 'admin.website*'],
    ];
@endphp
<div class="lg:flex">
    <aside class="bg-slate-900 text-slate-300 lg:fixed lg:inset-y-0 lg:w-60">
        <div class="flex items-center justify-between px-5 py-4 lg:block">
            <a href="{{ route('admin.dashboard') }}" class="inline-flex items-center gap-1 text-white"><x-logo :dark="true" size="text-lg" height="h-6" />
                <span class="ml-1 rounded bg-slate-800 px-1.5 py-0.5 align-middle text-[10px] font-semibold uppercase tracking-wider text-slate-400">Admin</span></a>
        </div>
        <nav class="flex gap-1 overflow-x-auto px-3 pb-3 text-sm lg:block lg:space-y-0.5 lg:overflow-visible">
            @foreach ($items as [$route, $label, $pattern])
                <a href="{{ route($route) }}"
                   class="block whitespace-nowrap rounded-lg px-3 py-2 {{ request()->routeIs($pattern) ? 'bg-slate-800 font-semibold text-white' : 'hover:bg-slate-800/60 hover:text-white' }}">{{ $label }}</a>
            @endforeach
        </nav>
        <div class="hidden border-t border-slate-800 px-5 py-4 text-xs lg:absolute lg:inset-x-0 lg:bottom-0 lg:block">
            <p class="truncate text-slate-400">{{ auth()->user()->email }}</p>
            <div class="mt-2 flex items-center justify-between">
                @if (config('site.mode') === 'website')
                    <a href="{{ route('home') }}" class="hover:text-white">← Website</a>
                @else
                    <a href="{{ route('dashboard') }}" class="hover:text-white">← Back to app</a>
                @endif
                <x-install-app class="hover:text-white" :up="true" />
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="hover:text-white">Log out</button></form>
            </div>
        </div>
    </aside>

    <main class="min-w-0 flex-1 px-4 py-6 sm:px-8 lg:ml-60">
        @if (session('status'))
            <div class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first() }}</div>
        @endif
        @yield('content')
    </main>
</div>
</body>
</html>
