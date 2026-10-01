@php
    $appOn = config('site.mode') !== 'website';
    $cta = $appOn ? route('register') : route('site.contact');
    $ctaLabel = $appOn ? 'Start free trial' : 'Get early access';
    $nav = [['site.suppliers', 'For suppliers'], ['site.pricing', 'Pricing'], ['site.contact', 'Contact']];
    $title = trim($__env->yieldContent('title'));
    $description = trim($__env->yieldContent('description')) ?: 'Reverse auction software for Indian manufacturers and SMEs. Invite your suppliers, run a live auction and buy at L1. Suppliers join free and bid from their phone.';
@endphp
<!DOCTYPE html>
<html lang="en-IN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title.' · GetL1' : 'GetL1: Make your suppliers compete. Buy at L1.' }}</title>
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ url()->current() }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="GetL1">
    <meta property="og:title" content="{{ $title ?: 'GetL1: Make your suppliers compete. Buy at L1.' }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:image" content="{{ asset('og-image.png') }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="theme-color" content="#047857">
    @if (! app()->environment('production') && config('site.mode') !== 'website')<meta name="robots" content="noindex, nofollow">@endif
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script type="application/ld+json">{!! json_encode([
        '@context' => 'https://schema.org', '@type' => 'SoftwareApplication', 'name' => 'GetL1',
        'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web',
        'description' => 'Reverse auction and e-procurement software for Indian SMEs.',
        'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'INR'],
    ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
</head>
<body class="min-h-screen bg-white font-sans text-slate-900 antialiased">
    <header class="sticky top-0 z-30 border-b border-slate-200/70 bg-white/85 backdrop-blur">
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3.5">
            <a href="{{ route('home') }}" class="text-2xl font-bold tracking-tight" aria-label="GetL1 home">Get<span class="text-emerald-700">L1</span></a>
            <nav class="hidden items-center gap-7 text-sm font-medium text-slate-600 md:flex">
                <a href="{{ route('home') }}#how" class="hover:text-slate-900">How it works</a>
                @foreach ($nav as [$r, $label])
                    <a href="{{ route($r) }}" class="hover:text-slate-900 {{ request()->routeIs($r) ? 'text-slate-900' : '' }}">{{ $label }}</a>
                @endforeach
            </nav>
            <div class="flex items-center gap-3 text-sm font-medium">
                @if ($appOn)
                    @auth
                        <a href="{{ route('dashboard') }}" class="rounded-lg bg-slate-900 px-4 py-2 text-white hover:bg-slate-800">Dashboard</a>
                    @else
                        <a href="{{ route('login') }}" class="hidden text-slate-600 hover:text-slate-900 sm:inline">Log in</a>
                        <a href="{{ $cta }}" class="rounded-lg bg-emerald-700 px-4 py-2 text-white shadow-sm hover:bg-emerald-800">{{ $ctaLabel }}</a>
                    @endauth
                @else
                    <a href="{{ $cta }}" class="rounded-lg bg-emerald-700 px-4 py-2 text-white shadow-sm hover:bg-emerald-800">{{ $ctaLabel }}</a>
                @endif
            </div>
        </div>
        <nav class="flex gap-5 overflow-x-auto border-t border-slate-100 px-4 py-2 text-sm font-medium text-slate-600 md:hidden">
            <a href="{{ route('home') }}#how" class="whitespace-nowrap">How it works</a>
            @foreach ($nav as [$r, $label])<a href="{{ route($r) }}" class="whitespace-nowrap">{{ $label }}</a>@endforeach
        </nav>
    </header>

    <main>@yield('content')</main>

    <footer class="border-t border-slate-200 bg-slate-50">
        <div class="mx-auto grid max-w-6xl gap-8 px-4 py-12 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div class="lg:col-span-2">
                <a href="{{ route('home') }}" class="text-xl font-bold tracking-tight">Get<span class="text-emerald-700">L1</span></a>
                <p class="mt-3 max-w-sm text-slate-600">Reverse auctions and purchase orders for Indian manufacturers and SMEs. Make your suppliers compete, and buy at L1.</p>
                <p class="mt-4 text-slate-500">{{ config('site.legal_name') }}<br>{{ config('site.address') }}</p>
            </div>
            <div>
                <p class="font-semibold">Product</p>
                <ul class="mt-3 space-y-2 text-slate-600">
                    <li><a href="{{ route('home') }}#how" class="hover:text-slate-900">How it works</a></li>
                    <li><a href="{{ route('site.pricing') }}" class="hover:text-slate-900">Pricing</a></li>
                    <li><a href="{{ route('site.suppliers') }}" class="hover:text-slate-900">For suppliers</a></li>
                    <li><a href="{{ route('site.contact') }}" class="hover:text-slate-900">Book a demo</a></li>
                </ul>
            </div>
            <div>
                <p class="font-semibold">Company</p>
                <ul class="mt-3 space-y-2 text-slate-600">
                    <li><a href="mailto:{{ config('site.email') }}" class="hover:text-slate-900">{{ config('site.email') }}</a></li>
                    @if (config('site.phone'))<li><a href="tel:{{ config('site.phone') }}" class="hover:text-slate-900">{{ config('site.phone') }}</a></li>@endif
                    <li><a href="{{ route('site.terms') }}" class="hover:text-slate-900">Terms of use</a></li>
                    <li><a href="{{ route('site.privacy') }}" class="hover:text-slate-900">Privacy policy</a></li>
                    <li><a href="{{ route('site.refunds') }}" class="hover:text-slate-900">Cancellation &amp; refunds</a></li>
                    <li><a href="{{ route('site.shipping') }}" class="hover:text-slate-900">Delivery policy</a></li>
                </ul>
            </div>
        </div>
        <p class="border-t border-slate-200 py-5 text-center text-xs text-slate-500">© {{ date('Y') }} {{ config('site.legal_name') }}. Made in India.</p>
    </footer>
</body>
</html>
