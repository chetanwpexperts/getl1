@php
    $appOn = config('site.mode') !== 'website';
    $cta = $appOn ? route('register') : route('site.contact');
    $ctaLabel = $appOn ? 'Start free trial' : 'Get early access';
    $nav = [['site.suppliers', 'For suppliers'], ['site.pricing', 'Pricing'], ['site.contact', 'Contact']];
    $title = trim($__env->yieldContent('title'));
    $siteName = config('site.name', 'GetL1');
    $defaultTitle = config('site.home_title') ?: $siteName.': Make your suppliers compete. Buy at L1.';
    $ga = preg_match('/^G-[A-Z0-9]{4,15}$/', (string) config('site.ga4_id')) ? config('site.ga4_id') : null;
    $socials = collect(['LinkedIn' => 'social_linkedin', 'Instagram' => 'social_instagram', 'Facebook' => 'social_facebook', 'X' => 'social_x', 'YouTube' => 'social_youtube'])
        ->map(fn ($k) => config('site.'.$k))->filter();
    $description = trim($__env->yieldContent('description')) ?: 'Reverse auction software for Indian manufacturers and SMEs. Invite your suppliers, run a live auction and buy at L1. Suppliers join free and bid from their phone.';
@endphp
<!DOCTYPE html>
<html lang="en-IN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title.' · '.$siteName : $defaultTitle }}</title>
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ url()->current() }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $siteName }}">
    <meta property="og:title" content="{{ $title ?: $defaultTitle }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:image" content="{{ config('site.og_image') ? \App\Services\WebsiteSettings::url(config('site.og_image')) : asset('og-image.png') }}">
    @if (config('site.google_verification'))<meta name="google-site-verification" content="{{ config('site.google_verification') }}">@endif
    <meta name="twitter:card" content="summary_large_image">
    <meta name="theme-color" content="#047857">
    @if (! app()->environment('production') && config('site.mode') !== 'website')<meta name="robots" content="noindex, nofollow">@endif
    <x-favicon />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @if ($ga)
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ $ga }}"></script>
        <script>window.dataLayer = window.dataLayer || []; function gtag(){dataLayer.push(arguments);} gtag('js', new Date()); gtag('config', @json($ga), { anonymize_ip: true });</script>
    @endif
    <script type="application/ld+json">{!! json_encode([
        '@context' => 'https://schema.org', '@type' => 'SoftwareApplication', 'name' => $siteName,
        'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web',
        'description' => 'Reverse auction and e-procurement software for Indian SMEs.',
        'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'INR'],
    ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
</head>
<body class="min-h-screen bg-white font-sans text-slate-900 antialiased">
    @if (config('site.announcement_on') && config('site.announcement_text'))
        <div class="bg-slate-900 px-4 py-2 text-center text-sm text-white">
            @if (config('site.announcement_link'))
                <a href="{{ config('site.announcement_link') }}" class="font-medium hover:underline">{{ config('site.announcement_text') }} <span aria-hidden="true">→</span></a>
            @else
                {{ config('site.announcement_text') }}
            @endif
        </div>
    @endif
    <header class="sticky top-0 z-30 border-b border-slate-200/70 bg-white/85 backdrop-blur">
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3.5">
            <a href="{{ route('home') }}" aria-label="{{ $siteName }} home"><x-logo /></a>
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
                <a href="{{ route('home') }}" aria-label="{{ $siteName }} home"><x-logo size="text-xl" height="h-9" /></a>
                <p class="mt-3 max-w-sm text-slate-600">{{ config('site.tagline') }}</p>
                <p class="mt-4 whitespace-pre-line text-slate-500">{{ config('site.legal_name') }}
{{ config('site.address') }}@if (config('site.gstin'))

GSTIN {{ config('site.gstin') }}@endif</p>
                @if ($socials->isNotEmpty())
                    <ul class="mt-4 flex flex-wrap gap-2">
                        @foreach ($socials as $label => $url)
                            <li><a href="{{ $url }}" target="_blank" rel="noopener" class="inline-flex rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-medium text-slate-700 hover:border-slate-300">{{ $label }}</a></li>
                        @endforeach
                    </ul>
                @endif
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
        <p class="border-t border-slate-200 py-5 text-center text-xs text-slate-500">© {{ date('Y') }} {{ config('site.legal_name') }}. {{ config('site.footer_note') }}</p>
    </footer>
</body>
</html>
