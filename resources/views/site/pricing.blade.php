@extends('layouts.site')

@section('title', 'Pricing')
@section('description', 'Simple monthly plans for buyers, from free. Suppliers always use GetL1 free. Extra auctions and AI reading packs when you need them.')

@php
    $appOn = config('site.mode') !== 'website';
    $inr = fn ($v) => \App\Support\Money::inr($v, 0);
    $gst = (bool) config('billing.gst_enabled');
@endphp

@section('content')
    <section class="mx-auto max-w-6xl px-4 py-16 sm:py-20">
        <div class="mx-auto max-w-2xl text-center">
            <h1 class="text-4xl font-bold tracking-tight">Simple pricing. Free for suppliers.</h1>
            <p class="mt-4 text-lg text-slate-600">Start with a 14-day free trial of Growth. After that, stay on Free or pick a plan. Cancel anytime.</p>
        </div>

        @if ($plans->isNotEmpty())
            <div class="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($plans as $plan)
                    @php $popular = $plan->code === 'growth'; @endphp
                    <section class="relative flex flex-col rounded-2xl border bg-white p-6 {{ $popular ? 'border-emerald-600 shadow-lg shadow-emerald-900/5 ring-1 ring-emerald-600' : 'border-slate-200' }}">
                        @if ($popular)<span class="absolute -top-3 left-6 rounded-full bg-emerald-700 px-2.5 py-0.5 text-xs font-semibold text-white">Most popular</span>@endif
                        <h2 class="text-lg font-semibold">{{ $plan->name }}</h2>
                        <p class="text-sm text-slate-500">{{ $plan->tagline }}</p>
                        <div class="mt-5 min-h-16">
                            @if ($plan->contact_sales)
                                <p class="text-3xl font-bold">Talk to us</p>
                                <p class="text-sm text-slate-500">Custom pricing for larger teams</p>
                            @elseif ((float) $plan->price_monthly <= 0)
                                <p class="text-3xl font-bold">₹0</p>
                                <p class="text-sm text-slate-500">Free forever</p>
                            @else
                                <p><span class="text-3xl font-bold">{{ $inr($plan->price_monthly) }}</span><span class="text-slate-500">/month</span></p>
                                <p class="text-sm text-slate-500">or {{ $inr($plan->price_yearly) }}/year (2 months free){{ $gst ? ' · + GST' : '' }}</p>
                            @endif
                        </div>
                        <ul class="mt-5 flex-1 space-y-2 text-sm text-slate-700">
                            @foreach ($plan->featureList() as $f)<li class="flex gap-2"><span class="text-emerald-700">✓</span>{{ $f }}</li>@endforeach
                        </ul>
                        @php
                            $href = $plan->contact_sales || ! $appOn ? route('site.contact') : route('register');
                            $label = $plan->contact_sales ? 'Talk to us' : ($appOn ? ((float) $plan->price_monthly <= 0 ? 'Start free' : 'Start free trial') : 'Get early access');
                        @endphp
                        <a href="{{ $href }}" class="mt-6 rounded-lg px-4 py-2.5 text-center text-sm font-semibold {{ $popular ? 'bg-emerald-700 text-white hover:bg-emerald-800' : 'border border-slate-300 hover:bg-slate-50' }}">{{ $label }}</a>
                    </section>
                @endforeach
            </div>
        @endif

        <div class="mt-10 grid gap-4 md:grid-cols-2">
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-6">
                <p class="font-semibold">Extra live auctions</p>
                <p class="mt-1 text-sm text-slate-600"><strong>{{ $inr(config('billing.auction_credit_price')) }}</strong> per auction{{ $gst ? ' + GST' : '' }}, on any plan. Used only after your monthly auctions run out. Never expire.</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-6">
                <p class="font-semibold">AI reading packs</p>
                <p class="mt-1 text-sm text-slate-600"><strong>{{ $inr(config('billing.ai_pack_price')) }}</strong> for {{ config('billing.ai_pack_reads') }} reads{{ $gst ? ' + GST' : '' }}. Turn WhatsApp messages, Excel, PDFs and photos into RFQs on any plan. A failed read is never charged.</p>
            </div>
        </div>

        <div class="mx-auto mt-16 max-w-3xl">
            <h2 class="text-2xl font-bold tracking-tight">Pricing questions</h2>
            <dl class="mt-6 space-y-6">
                @foreach ([
                    ['What counts as a live auction?', 'One reverse auction on one RFQ, however many suppliers and items it has. RFQs and sealed quotes are unlimited on every plan.'],
                    ['What happens after the trial?', 'You move to Free automatically, with nothing charged and no data lost. Upgrade whenever you need more auctions or users.'],
                    ['How do I pay?', 'UPI, cards, net banking or UPI AutoPay through Razorpay. You get an invoice by email for every payment.'],
                    ['Can I cancel?', 'Yes, anytime from the Billing page. You keep your plan until the end of the period you paid for. See the cancellation and refund policy.'],
                    ['Do suppliers pay anything?', 'Never. Suppliers register, quote, bid and receive purchase orders free.'],
                ] as [$q, $a])
                    <div><dt class="font-semibold">{{ $q }}</dt><dd class="mt-1 text-slate-600">{{ $a }}</dd></div>
                @endforeach
            </dl>
            <p class="mt-8 text-sm text-slate-500">Read the <a href="{{ route('site.refunds') }}" class="underline">cancellation and refund policy</a>.</p>
        </div>
    </section>
@endsection
