<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'GetL1') · GetL1</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-900 antialiased">
    <div class="flex min-h-screen flex-col items-center justify-center px-4 py-10">
        <a href="{{ route('home') }}" class="mb-6 text-2xl font-bold tracking-tight">
            Get<span class="text-emerald-700">L1</span>
        </a>
        <div class="w-full {{ $wide ?? false ? 'max-w-2xl' : 'max-w-md' }} rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            @yield('content')
        </div>
    </div>
</body>
</html>
