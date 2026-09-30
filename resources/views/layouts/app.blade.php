<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · GetL1</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-900 antialiased">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3">
            <div class="flex items-center gap-6">
                <a href="{{ route('dashboard') }}" class="text-xl font-bold tracking-tight">
                    Get<span class="text-emerald-700">L1</span>
                </a>
                <nav class="hidden gap-4 text-sm font-medium text-slate-600 sm:flex">
                    @include('layouts.nav-links')
                </nav>
            </div>

            <div class="flex items-center gap-3 text-sm">
                @isset($currentOrg)
                    @php($orgs = auth()->user()->organizations)
                    @if ($orgs->count() > 1)
                        <details class="relative">
                            <summary class="cursor-pointer list-none rounded-lg border border-slate-200 px-3 py-1.5">
                                {{ $currentOrg->name }} ▾
                            </summary>
                            <div class="absolute right-0 z-10 mt-1 w-56 rounded-lg border border-slate-200 bg-white p-1 shadow-lg">
                                @foreach ($orgs as $org)
                                    <form method="POST" action="{{ route('organizations.switch', $org) }}">
                                        @csrf
                                        <button class="block w-full rounded px-3 py-2 text-left hover:bg-slate-100 {{ $org->id === $currentOrg->id ? 'font-semibold' : '' }}">
                                            {{ $org->name }}
                                            <span class="block text-xs text-slate-500">{{ $org->type->label() }}</span>
                                        </button>
                                    </form>
                                @endforeach
                            </div>
                        </details>
                    @else
                        <span class="hidden text-slate-600 sm:inline">{{ $currentOrg->name }}</span>
                    @endif
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ $currentOrg->type->label() }}</span>
                @endisset

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="text-slate-600 hover:text-slate-900">Log out</button>
                </form>
            </div>
        </div>
        <nav class="flex gap-5 overflow-x-auto border-t border-slate-100 px-4 py-2 text-sm font-medium text-slate-600 sm:hidden">
            @include('layouts.nav-links')
        </nav>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-8">
        @if (session('status'))
            <div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any() && ! isset($inlineErrors))
            <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                Please fix the highlighted fields.
            </div>
        @endif

        @yield('content')
    </main>
</body>
</html>
