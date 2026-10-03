@extends('layouts.site')

@section('description', config('site.home_description') ?: 'GetL1 is reverse auction software for Indian manufacturers and SMEs. Post a requirement, collect sealed quotes, run a live auction and send the purchase order. Suppliers join free.')

@php
    $appOn = config('site.mode') !== 'website';
    $cta = $appOn ? route('register') : route('site.contact');
    $ctaLabel = $appOn ? 'Start 14-day free trial' : 'Get early access';
    $faq = [
        ['What is a reverse auction?', 'Instead of you negotiating with each supplier one by one, your invited suppliers bid against each other for your order, live, for a fixed time. Prices go down, not up. The lowest bidder is L1.'],
        ['Will my suppliers use it?', 'Yes, if they want your order. Suppliers join free, get your requirement by email and WhatsApp link, and quote or bid from their phone. No software to install and no fee for them.'],
        ['Can suppliers see each other\'s names or prices?', 'No. You choose what they see: only their own rank, or their rank plus the current lowest price. Names are never shown to other suppliers.'],
        ['What if someone bids in the last second?', 'Late bids extend the auction by a few minutes, so everyone gets a fair chance to respond. The extension is limited, so the auction always ends.'],
        ['Do I have to give the order to L1?', 'No. You compare landed cost, delivery and supplier history, then award. If you pick someone other than L1 you note the reason, which keeps your audit trail clean.'],
        ['Is our data safe?', 'Each company\'s data is kept separate. Quotes stay sealed until your deadline, every action is logged, and documents are stored privately. Your last purchase price is never shown to suppliers.'],
        ['Does it help with MSME payments under Section 43B(h)?', 'Yes. Suppliers upload invoices against your purchase order, and GetL1 works out each MSME due date (within the agreed period, never more than 45 days from accepting the goods) and reminds you before it is due.'],
        ['Does it handle GST and purchase orders?', 'Yes. The purchase order is created automatically with CGST/SGST or IGST based on both GSTINs, amount in words, and your terms, then emailed to the supplier.'],
    ];
@endphp

@push('head')
    <script type="application/ld+json">{!! json_encode(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn ($f) => [
        '@type' => 'Question', 'name' => $f[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f[1]],
    ], $faq)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
@endpush

@section('content')
    {{-- Hero --}}
    <section class="relative overflow-hidden">
        <div class="absolute inset-0 -z-10 bg-[radial-gradient(60rem_30rem_at_80%_-10%,rgba(16,185,129,0.12),transparent)]"></div>
        <div class="mx-auto grid max-w-6xl items-center gap-12 px-4 py-16 sm:py-20 lg:grid-cols-2 lg:py-24">
            <div>
                <p class="inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-800">
                    <span class="size-1.5 rounded-full bg-emerald-600"></span> Reverse auctions for Indian SMEs
                </p>
                <h1 class="mt-5 text-4xl font-bold leading-[1.1] tracking-tight sm:text-5xl">
                    @if (config('site.hero_headline'))
                        {{ config('site.hero_headline') }}
                    @else
                        Make your suppliers compete.<br><span class="text-emerald-700">Buy at L1.</span>
                    @endif
                </h1>
                <p class="mt-6 max-w-xl text-lg leading-relaxed text-slate-600">
                    {{ config('site.hero_subtext') ?: 'Stop haggling with suppliers one by one on calls and WhatsApp. Invite them to quote and run a short live reverse auction. You get the lowest price, a clean audit trail and the purchase order in one place.' }}
                </p>
                <div class="mt-8 flex flex-wrap gap-3">
                    <a href="{{ $cta }}" class="rounded-lg bg-emerald-700 px-5 py-3 font-semibold text-white shadow-sm hover:bg-emerald-800">{{ $ctaLabel }}</a>
                    <a href="{{ route('site.contact') }}" class="rounded-lg border border-slate-300 bg-white px-5 py-3 font-semibold hover:bg-slate-50">Book a 20-minute demo</a>
                </div>
                <p class="mt-4 text-sm text-slate-500">No card needed. Suppliers always use GetL1 free.</p>
            </div>

            {{-- Illustration of a live auction (example data) --}}
            <div class="relative" aria-label="Example of a live auction screen">
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-xl shadow-slate-900/5">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Live auction · example</p>
                            <p class="mt-0.5 font-semibold">Corrugated boxes, 5-ply · 50,000 pcs</p>
                        </div>
                        <span class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-700">
                            <span class="relative flex size-2"><span class="absolute inline-flex size-full animate-ping rounded-full bg-red-400 opacity-75"></span><span class="relative inline-flex size-2 rounded-full bg-red-600"></span></span>
                            08:42 left
                        </span>
                    </div>
                    <div class="mt-4 grid grid-cols-3 gap-2 text-center">
                        <div class="rounded-lg bg-slate-50 p-2.5"><p class="text-[11px] text-slate-500">Opening (sealed)</p><p class="font-semibold tabular-nums">₹21.40</p></div>
                        <div class="rounded-lg bg-emerald-50 p-2.5"><p class="text-[11px] text-emerald-800">Current L1</p><p class="font-semibold tabular-nums text-emerald-800">₹19.65</p></div>
                        <div class="rounded-lg bg-slate-50 p-2.5"><p class="text-[11px] text-slate-500">Saving so far</p><p class="font-semibold tabular-nums">8.2%</p></div>
                    </div>
                    <ul class="mt-4 space-y-2 text-sm">
                        @foreach ([['L1', 'Supplier C', '₹19.65', 'just now', true], ['L2', 'Supplier A', '₹19.80', '1 min ago', false], ['L3', 'Supplier D', '₹20.10', '2 min ago', false], ['L4', 'Supplier B', '₹20.90', '6 min ago', false]] as [$rank, $name, $price, $when, $top])
                            <li class="flex items-center justify-between rounded-lg border {{ $top ? 'border-emerald-200 bg-emerald-50/60' : 'border-slate-100' }} px-3 py-2">
                                <span class="flex items-center gap-3"><span class="w-7 rounded bg-slate-900 py-0.5 text-center text-xs font-bold text-white {{ $top ? '!bg-emerald-700' : '' }}">{{ $rank }}</span>{{ $name }}</span>
                                <span class="flex items-center gap-3"><span class="text-xs text-slate-400">{{ $when }}</span><span class="font-semibold tabular-nums">{{ $price }}</span></span>
                            </li>
                        @endforeach
                    </ul>
                    <div class="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-3">
                        <p class="text-xs text-slate-500">Suppliers see only their own rank. You see everything.</p>
                        <p class="rounded-full bg-emerald-700 px-3 py-1 text-xs font-semibold text-white">₹87,500 saved on this order</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Problem --}}
    <section class="border-y border-slate-200 bg-slate-50">
        <div class="mx-auto grid max-w-6xl gap-8 px-4 py-14 md:grid-cols-3">
            @foreach ([
                ['One-by-one negotiation', 'Calling five suppliers, waiting for rates on WhatsApp and going back and forth takes days, and you never know if you got the best price.'],
                ['No proof of a fair process', 'When the owner or auditor asks why this supplier and this price, the answer is in someone\'s phone and memory.'],
                ['Paperwork after the deal', 'Comparing quotes in Excel, typing the purchase order and working out GST by hand. Every order, again.'],
            ] as [$h, $p])
                <div>
                    <p class="font-semibold">{{ $h }}</p>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ $p }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- How it works --}}
    <section id="how" class="mx-auto max-w-6xl scroll-mt-20 px-4 py-20">
        <p class="text-sm font-semibold uppercase tracking-wide text-emerald-700">How it works</p>
        <h2 class="mt-2 text-3xl font-bold tracking-tight">From requirement to purchase order in four steps</h2>
        <ol class="mt-10 grid gap-6 md:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['Post your requirement', 'Items, quantities, delivery and payment terms. Or paste a WhatsApp message, upload your Excel indent, a PDF or a photo of a handwritten list, and AI fills the form for you.'],
                ['Collect sealed quotes', 'Invite your own suppliers by email and WhatsApp link. Quotes stay sealed until your deadline, so nobody sees anyone else\'s price.'],
                ['Run a live auction', 'The best sealed quotes become the starting point. Suppliers bid down for 15 to 60 minutes and see only their rank. Late bids extend the clock.'],
                ['Award and send the PO', 'Approve, award and the GST-ready purchase order is emailed to the supplier automatically. They accept it online.'],
            ] as $i => [$h, $p])
                <li class="rounded-2xl border border-slate-200 p-6">
                    <span class="flex size-9 items-center justify-center rounded-full bg-emerald-700 text-sm font-bold text-white">{{ $i + 1 }}</span>
                    <p class="mt-4 font-semibold">{{ $h }}</p>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ $p }}</p>
                </li>
            @endforeach
        </ol>
    </section>

    {{-- Features --}}
    <section class="bg-slate-900 text-slate-300">
        <div class="mx-auto max-w-6xl px-4 py-20">
            <h2 class="text-3xl font-bold tracking-tight text-white">Built for how Indian factories actually buy</h2>
            <div class="mt-10 grid gap-x-10 gap-y-8 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ([
                    ['Suppliers bid from their phone', 'A link on WhatsApp or email is all they need. No app, no training, no fee.'],
                    ['AI reads your requirement', 'Excel indents, PDFs, WhatsApp text and handwritten lists in English, Hindi or Punjabi words become a clean RFQ.'],
                    ['Fair, transparent auctions', 'Minimum bid steps, rank-only view, automatic extensions and a server clock everyone shares.'],
                    ['Approvals that fit your team', 'Approval levels by amount or when L1 isn\'t chosen: manager, plant head, director. Nobody approves their own award.', 'purchase-requests-and-approvals'],
                    ['Purchase requests from the floor', 'Store and plant staff raise requests for free; approved ones become an RFQ in one click.', 'purchase-requests-and-approvals'],
                    ['Purchase orders with GST', 'CGST/SGST or IGST chosen from GSTINs, amount in words, your terms, numbered automatically.'],
                    ['Savings you can show', 'Savings versus sealed quotes and your last purchase price, by month and financial year, with CSV export.'],
                    ['Supplier scorecards', 'Delivery, quality and response scored from your own orders, with GSTIN and PAN checks.', 'supplier-management'],
                    ['Rate contracts and price history', 'Lock in agreed rates and see what you paid for every item over time.', 'supplier-management'],
                    ['Goods receipt and invoice matching', 'Record deliveries and rejections; invoices are checked against the PO and goods received.', 'purchase-orders-grn-invoices'],
                    ['MSME 45-day payments', 'Due dates under the MSMED Act and Section 43B(h) worked out, with reminders.', 'msme-payment-tracker'],
                    ['A complete audit trail', 'Every quote, bid, approval and change is recorded with time and user, and can\'t be edited.'],
                    ['Live alerts', 'New quotes, auctions going live, approvals and deliveries pop up instantly, on screen and on your phone.'],
                    ['Automatic follow-ups', 'Reminders to suppliers who haven\'t quoted, auction alerts and results go out on their own.'],
                ] as $f)
                    @php [$h, $p] = $f; $link = $f[2] ?? null; @endphp
                    <div>
                        <p class="flex items-center gap-2 font-semibold text-white"><span class="text-emerald-400">✓</span>
                            @if ($link)<a href="{{ route('site.feature', $link) }}" class="hover:underline">{{ $h }}</a>@else{{ $h }}@endif
                        </p>
                        <p class="mt-1.5 pl-6 text-sm leading-relaxed text-slate-400">{{ $p }}</p>
                    </div>
                @endforeach
            </div>
            <a href="{{ route('site.features') }}" class="mt-10 inline-block font-semibold text-emerald-400 hover:underline">See all features →</a>
        </div>
    </section>

    {{-- Savings example --}}
    <section class="mx-auto max-w-6xl px-4 py-20">
        <div class="grid items-center gap-10 lg:grid-cols-2">
            <div>
                <p class="text-sm font-semibold uppercase tracking-wide text-emerald-700">What it's worth</p>
                <h2 class="mt-2 text-3xl font-bold tracking-tight">A small price drop on a regular order pays for a year</h2>
                <p class="mt-4 leading-relaxed text-slate-600">When suppliers can see they're not the lowest, many go a little further. Even a few percent on materials you buy every month adds up quickly. Here is an example; your numbers will differ.</p>
                <a href="{{ route('site.pricing') }}" class="mt-6 inline-block font-semibold text-emerald-700 hover:underline">See plans and pricing →</a>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-6">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Example: packaging buyer, one month</p>
                <dl class="mt-4 space-y-3 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-600">Monthly purchase on GetL1</dt><dd class="font-semibold tabular-nums">₹10,00,000</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-600">Price drop in live auctions</dt><dd class="font-semibold tabular-nums">5%</dd></div>
                    <div class="flex justify-between border-t border-slate-200 pt-3"><dt class="text-slate-600">Saved this month</dt><dd class="font-semibold tabular-nums text-emerald-700">₹50,000</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-600">Growth plan</dt><dd class="font-semibold tabular-nums">₹3,999 / month</dd></div>
                </dl>
            </div>
        </div>
    </section>

    {{-- Suppliers --}}
    <section class="border-y border-slate-200 bg-emerald-50/50">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-6 px-4 py-12">
            <div class="max-w-2xl">
                <h2 class="text-2xl font-bold tracking-tight">Are you a supplier?</h2>
                <p class="mt-2 text-slate-600">GetL1 is free for suppliers, always. Get requirements from serious buyers, quote from your phone and win orders on price and reliability.</p>
            </div>
            <a href="{{ route('site.suppliers') }}" class="rounded-lg border border-emerald-700 bg-white px-5 py-3 font-semibold text-emerald-800 hover:bg-emerald-50">For suppliers</a>
        </div>
    </section>

    {{-- FAQ --}}
    <section class="mx-auto max-w-3xl px-4 py-20">
        <h2 class="text-center text-3xl font-bold tracking-tight">Questions buyers ask</h2>
        <div class="mt-10 divide-y divide-slate-200 border-y border-slate-200">
            @foreach ($faq as [$q, $a])
                <details class="group py-4">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-medium">
                        {{ $q }} <span class="text-xl text-slate-400 transition group-open:rotate-45">+</span>
                    </summary>
                    <p class="mt-3 leading-relaxed text-slate-600">{{ $a }}</p>
                </details>
            @endforeach
        </div>
    </section>

    {{-- CTA --}}
    <section class="px-4 pb-20">
        <div class="mx-auto max-w-6xl rounded-3xl bg-emerald-800 px-6 py-14 text-center text-white sm:px-12">
            <h2 class="text-3xl font-bold tracking-tight">Run your next purchase as an auction</h2>
            <p class="mx-auto mt-3 max-w-xl text-emerald-100">Bring one real requirement. We'll help you invite your suppliers and run the first auction with you.</p>
            <div class="mt-8 flex flex-wrap justify-center gap-3">
                <a href="{{ $cta }}" class="rounded-lg bg-white px-5 py-3 font-semibold text-emerald-900 hover:bg-emerald-50">{{ $ctaLabel }}</a>
                <a href="{{ route('site.contact') }}" class="rounded-lg border border-emerald-400 px-5 py-3 font-semibold text-white hover:bg-emerald-700">Talk to us</a>
            </div>
        </div>
    </section>
@endsection
