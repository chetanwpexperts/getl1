@extends('layouts.site')

@section('title', 'Features: e-procurement and reverse auction software')
@section('description', 'Everything GetL1 does: purchase requests and approvals, sealed quotes, live reverse auctions, purchase orders with GST, goods receipts, invoice matching, MSME payments, supplier scorecards and rate contracts.')

@php
    $appOn = config('site.mode') !== 'website';
    $cta = $appOn ? route('register') : route('site.contact');
    $ctaLabel = $appOn ? 'Start 14-day free trial' : 'Get early access';
    $stages = [
        ['Request', 'Staff raise purchase requests; managers approve them by your rules.', 'purchase-requests-and-approvals'],
        ['Source', 'Sealed quotes from your suppliers, then a live reverse auction. AI turns Excel, PDF or WhatsApp text into an RFQ.', null],
        ['Decide', 'Compare landed cost with each supplier\'s score and last price. Multi-level approvals before the PO goes out.', 'supplier-management'],
        ['Order and receive', 'GST-ready purchase orders sent automatically; goods receipts with rejections.', 'purchase-orders-grn-invoices'],
        ['Pay', 'Invoices matched to PO and goods received; MSME due dates and reminders.', 'msme-payment-tracker'],
        ['Improve', 'Price history, rate contracts and a savings report you can show your management.', 'supplier-management'],
    ];
@endphp

@section('content')
    <section class="relative overflow-hidden border-b border-slate-200">
        <div class="absolute inset-0 -z-10 bg-[radial-gradient(50rem_25rem_at_85%_-10%,rgba(16,185,129,0.12),transparent)]"></div>
        <div class="mx-auto max-w-4xl px-4 py-16 text-center sm:py-20">
            <p class="text-sm font-semibold uppercase tracking-wide text-emerald-700">Features</p>
            <h1 class="mt-3 text-4xl font-bold leading-tight tracking-tight sm:text-5xl">Your whole purchase cycle, in one place</h1>
            <p class="mx-auto mt-6 max-w-2xl text-lg leading-relaxed text-slate-600">From a request on the shop floor to the payment to your supplier: sourcing, live auctions, approvals, orders, deliveries and invoices, built for how Indian manufacturers and SMEs buy.</p>
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-16">
        <ol class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($stages as $i => [$h, $p, $link])
                <li class="rounded-2xl border border-slate-200 p-6">
                    <span class="flex size-8 items-center justify-center rounded-full bg-emerald-700 text-sm font-bold text-white">{{ $i + 1 }}</span>
                    <p class="mt-4 font-semibold">{{ $h }}</p>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ $p }}</p>
                    @if ($link)<a href="{{ route('site.feature', $link) }}" class="mt-3 inline-block text-sm font-semibold text-emerald-700 hover:underline">Learn more →</a>
                    @else<a href="{{ route('home') }}#how" class="mt-3 inline-block text-sm font-semibold text-emerald-700 hover:underline">How it works →</a>@endif
                </li>
            @endforeach
        </ol>
    </section>

    @foreach ($pages as $slug => $p)
        <section class="{{ $loop->odd ? 'bg-slate-50' : '' }}">
            <div class="mx-auto grid max-w-6xl items-center gap-10 px-4 py-16 lg:grid-cols-2">
                <div class="{{ $loop->even ? 'lg:order-2' : '' }}">
                    <h2 class="text-3xl font-bold tracking-tight">{{ $p['nav'] }}</h2>
                    <p class="mt-4 leading-relaxed text-slate-600">{{ $p['intro'] }}</p>
                    <a href="{{ route('site.feature', $slug) }}" class="mt-6 inline-block font-semibold text-emerald-700 hover:underline">{{ $p['title'] }} →</a>
                </div>
                <a href="{{ route('site.feature', $slug) }}" class="block overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl shadow-slate-900/5">
                    <img src="{{ asset('images/features/'.$p['sections'][0]['image']) }}" alt="{{ $p['sections'][0]['alt'] }}" loading="lazy" decoding="async" class="block h-auto w-full">
                </a>
            </div>
        </section>
    @endforeach

    <section class="bg-slate-900 text-slate-300">
        <div class="mx-auto max-w-6xl px-4 py-16">
            <h2 class="text-2xl font-bold tracking-tight text-white">Also included</h2>
            <div class="mt-8 grid gap-x-10 gap-y-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ([
                    ['Live reverse auctions', 'Standard, item-by-item and Japanese formats, rank-only view, automatic extensions.'],
                    ['Counter-offers', 'Ask a supplier for a better price after the quotes, with a deadline.'],
                    ['Questions and answers', 'Suppliers ask; your answer reaches everyone, so they quote on the same basis.'],
                    ['Live alerts', 'Bell, pop-ups and phone alerts for quotes, auctions, approvals, deliveries and invoices.'],
                    ['Tally export', 'Purchase orders and masters as Tally XML, plus an Excel register.'],
                    ['Audit trail', 'Every quote, bid, approval and change recorded with time and user.'],
                ] as [$h, $p])
                    <div>
                        <p class="flex items-center gap-2 font-semibold text-white"><span class="text-emerald-400" aria-hidden="true">✓</span> {{ $h }}</p>
                        <p class="mt-1.5 pl-6 text-sm leading-relaxed text-slate-400">{{ $p }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="px-4 py-16">
        <div class="mx-auto max-w-6xl rounded-3xl bg-emerald-800 px-6 py-12 text-center text-white sm:px-12">
            <h2 class="text-3xl font-bold tracking-tight">Run your next purchase on GetL1</h2>
            <div class="mt-8 flex flex-wrap justify-center gap-3">
                <a href="{{ $cta }}" class="rounded-lg bg-white px-5 py-3 font-semibold text-emerald-900 hover:bg-emerald-50">{{ $ctaLabel }}</a>
                <a href="{{ route('site.contact') }}" class="rounded-lg border border-emerald-400 px-5 py-3 font-semibold text-white hover:bg-emerald-700">Book a demo</a>
            </div>
        </div>
    </section>
@endsection
