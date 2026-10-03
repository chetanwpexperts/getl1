@extends('layouts.site')

@section('title', $page['title'])
@section('description', $page['description'])

@php
    $appOn = config('site.mode') !== 'website';
    $cta = $appOn ? route('register') : route('site.contact');
    $ctaLabel = $appOn ? 'Start 14-day free trial' : 'Get early access';
@endphp

@push('head')
    <script type="application/ld+json">{!! json_encode([
        '@context' => 'https://schema.org',
        '@graph' => [
            ['@type' => 'BreadcrumbList', 'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => config('site.name', 'GetL1'), 'item' => route('home')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Features', 'item' => route('site.features')],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $page['nav'], 'item' => route('site.feature', $slug)],
            ]],
            ['@type' => 'FAQPage', 'mainEntity' => array_map(fn ($f) => [
                '@type' => 'Question', 'name' => $f[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f[1]],
            ], $page['faq'])],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
@endpush

@section('content')
    <section class="relative overflow-hidden border-b border-slate-200">
        <div class="absolute inset-0 -z-10 bg-[radial-gradient(50rem_25rem_at_85%_-10%,rgba(16,185,129,0.12),transparent)]"></div>
        <div class="mx-auto max-w-4xl px-4 py-16 text-center sm:py-20">
            <nav class="text-sm text-slate-500" aria-label="Breadcrumb">
                <a href="{{ route('site.features') }}" class="hover:text-slate-800">Features</a> <span aria-hidden="true">/</span> <span class="text-slate-700">{{ $page['nav'] }}</span>
            </nav>
            <h1 class="mt-4 text-4xl font-bold leading-tight tracking-tight sm:text-5xl">{{ $page['h1'] }}</h1>
            <p class="mx-auto mt-6 max-w-2xl text-lg leading-relaxed text-slate-600">{{ $page['intro'] }}</p>
            <div class="mt-8 flex flex-wrap justify-center gap-3">
                <a href="{{ $cta }}" class="rounded-lg bg-emerald-700 px-5 py-3 font-semibold text-white shadow-sm hover:bg-emerald-800">{{ $ctaLabel }}</a>
                <a href="{{ route('site.contact') }}" class="rounded-lg border border-slate-300 bg-white px-5 py-3 font-semibold hover:bg-slate-50">Book a 20-minute demo</a>
            </div>
        </div>
    </section>

    @foreach ($page['sections'] as $i => $s)
        <section class="{{ $i % 2 ? 'bg-slate-50' : '' }}">
            <div class="mx-auto grid max-w-6xl items-center gap-10 px-4 py-16 lg:grid-cols-5 lg:py-20">
                <div class="lg:col-span-2 {{ $i % 2 ? 'lg:order-2' : '' }}">
                    <h2 class="text-3xl font-bold tracking-tight">{{ $s['h2'] }}</h2>
                    <p class="mt-4 leading-relaxed text-slate-600">{{ $s['text'] }}</p>
                    <ul class="mt-6 space-y-2.5">
                        @foreach ($s['bullets'] as $b)
                            <li class="flex gap-2.5 text-sm text-slate-700"><span class="mt-0.5 text-emerald-600" aria-hidden="true">✓</span>{{ $b }}</li>
                        @endforeach
                    </ul>
                </div>
                <figure class="lg:col-span-3">
                    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl shadow-slate-900/5">
                        <img src="{{ asset('images/features/'.$s['image']) }}" alt="{{ $s['alt'] }}" loading="{{ $i === 0 ? 'eager' : 'lazy' }}" decoding="async" class="block h-auto w-full">
                    </div>
                    <figcaption class="mt-2 text-center text-xs text-slate-500">GetL1 screen with example data</figcaption>
                </figure>
            </div>
        </section>
    @endforeach

    <section class="mx-auto max-w-3xl px-4 py-16">
        <h2 class="text-center text-3xl font-bold tracking-tight">Questions</h2>
        <div class="mt-8 divide-y divide-slate-200 border-y border-slate-200">
            @foreach ($page['faq'] as [$q, $a])
                <details class="group py-4">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-medium">
                        {{ $q }} <span class="text-xl text-slate-400 transition group-open:rotate-45" aria-hidden="true">+</span>
                    </summary>
                    <p class="mt-3 leading-relaxed text-slate-600">{{ $a }}</p>
                </details>
            @endforeach
        </div>
    </section>

    <section class="border-t border-slate-200 bg-slate-50">
        <div class="mx-auto max-w-6xl px-4 py-14">
            <h2 class="text-xl font-bold tracking-tight">More of GetL1</h2>
            <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($others as $os => $op)
                    <a href="{{ route('site.feature', $os) }}" class="group rounded-2xl border border-slate-200 bg-white p-5 hover:border-emerald-300">
                        <p class="font-semibold group-hover:text-emerald-800">{{ $op['nav'] }}</p>
                        <p class="mt-1 text-sm leading-relaxed text-slate-600">{{ \Illuminate\Support\Str::limit($op['description'], 110) }}</p>
                    </a>
                @endforeach
                <a href="{{ route('home') }}#how" class="group rounded-2xl border border-slate-200 bg-white p-5 hover:border-emerald-300">
                    <p class="font-semibold group-hover:text-emerald-800">Reverse auctions</p>
                    <p class="mt-1 text-sm leading-relaxed text-slate-600">Sealed quotes, then a short live auction where your suppliers bid down. Buy at L1.</p>
                </a>
            </div>
        </div>
    </section>

    <section class="px-4 py-16">
        <div class="mx-auto max-w-6xl rounded-3xl bg-emerald-800 px-6 py-12 text-center text-white sm:px-12">
            <h2 class="text-3xl font-bold tracking-tight">See it with one of your own purchases</h2>
            <p class="mx-auto mt-3 max-w-xl text-emerald-100">Bring one real requirement. We'll set it up with you, invite your suppliers and run it end to end.</p>
            <div class="mt-8 flex flex-wrap justify-center gap-3">
                <a href="{{ $cta }}" class="rounded-lg bg-white px-5 py-3 font-semibold text-emerald-900 hover:bg-emerald-50">{{ $ctaLabel }}</a>
                <a href="{{ route('site.contact') }}" class="rounded-lg border border-emerald-400 px-5 py-3 font-semibold text-white hover:bg-emerald-700">Talk to us</a>
            </div>
        </div>
    </section>
@endsection
