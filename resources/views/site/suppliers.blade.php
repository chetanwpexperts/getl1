@extends('layouts.site')

@section('title', 'For suppliers')
@section('description', 'GetL1 is free for suppliers. Receive requirements from buyers, quote from your phone, see your rank live in auctions and receive purchase orders online.')

@php $appOn = config('site.mode') !== 'website'; @endphp

@section('content')
    <section class="mx-auto max-w-6xl px-4 py-16 sm:py-20">
        <div class="max-w-3xl">
            <p class="text-sm font-semibold uppercase tracking-wide text-emerald-700">For suppliers · Free, always</p>
            <h1 class="mt-3 text-4xl font-bold tracking-tight sm:text-5xl">Win orders on price and reliability, from your phone</h1>
            <p class="mt-5 text-lg leading-relaxed text-slate-600">Buyers invite you to their requirements on GetL1. You quote, take part in short live auctions and receive the purchase order online. No fees, no commission, nothing to install.</p>
            <div class="mt-8 flex flex-wrap gap-3">
                <a href="{{ $appOn ? route('register', ['as' => 'supplier']) : route('site.contact', ['as' => 'supplier']) }}" class="rounded-lg bg-emerald-700 px-5 py-3 font-semibold text-white hover:bg-emerald-800">{{ $appOn ? 'Register free as a supplier' : 'Register interest' }}</a>
            </div>
        </div>

        <div class="mt-16 grid gap-6 md:grid-cols-3">
            @foreach ([
                ['Get the requirement', 'You receive the buyer\'s RFQ by email and a WhatsApp link: items, quantities, delivery and payment terms, drawings if any.'],
                ['Quote and bid', 'Enter your rates once. If the buyer runs an auction, you see your rank live and can lower your price until the clock ends.'],
                ['Receive the PO', 'If you win, the purchase order with GST details arrives by email. Accept it online in one click.'],
            ] as $i => [$h, $p])
                <div class="rounded-2xl border border-slate-200 p-6">
                    <span class="flex size-9 items-center justify-center rounded-full bg-slate-900 text-sm font-bold text-white">{{ $i + 1 }}</span>
                    <p class="mt-4 font-semibold">{{ $h }}</p>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ $p }}</p>
                </div>
            @endforeach
        </div>

        <div class="mt-16 grid gap-10 lg:grid-cols-2">
            <div>
                <h2 class="text-2xl font-bold tracking-tight">Fair to you</h2>
                <ul class="mt-5 space-y-3 text-slate-700">
                    @foreach ([
                        'Your company name and prices are never shown to other suppliers.',
                        'Sealed quotes stay sealed until the buyer\'s deadline.',
                        'Everyone bids on the same server clock. Late bids extend the auction, so nobody is sniped in the last second.',
                        'Get a verified badge by uploading your GST certificate and Udyam registration.',
                        'All your orders and documents in one place.',
                    ] as $line)
                        <li class="flex gap-3"><span class="mt-0.5 text-emerald-700">✓</span><span>{{ $line }}</span></li>
                    @endforeach
                </ul>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-6">
                <p class="font-semibold">Questions suppliers ask</p>
                <dl class="mt-4 space-y-4 text-sm">
                    <div><dt class="font-medium">Do I have to bid lower than my cost?</dt><dd class="mt-1 text-slate-600">No. You decide your price. Many buyers also consider delivery, quality and history, not only L1.</dd></div>
                    <div><dt class="font-medium">Can buyers find me on GetL1?</dt><dd class="mt-1 text-slate-600">Buyers invite the suppliers they already work with or want to try. Registering and getting verified makes you easy to add.</dd></div>
                    <div><dt class="font-medium">Is there any charge later?</dt><dd class="mt-1 text-slate-600">No. GetL1 is paid for by buyers. Suppliers never pay.</dd></div>
                </dl>
            </div>
        </div>
    </section>
@endsection
