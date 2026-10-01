@extends('layouts.app')

@section('title', 'Billing')

@section('content')
    @php
        $inr = fn ($v) => \App\Support\Money::inr($v, 0);
        $status = $live?->status->value;
        $limit = $allowance['limit'];
        $used = $allowance['used'];
        $pct = $limit ? min(100, (int) round($used / max(1, $limit) * 100)) : 0;
    @endphp

    <div data-billing data-subscribe-url="{{ route('buyer.billing.subscribe') }}" data-subscribe-confirm-url="{{ route('buyer.billing.subscribe.confirm') }}"
         data-credits-url="{{ route('buyer.billing.credits') }}" data-credits-confirm-url="{{ route('buyer.billing.credits.confirm') }}"
         data-ai-packs-url="{{ route('buyer.billing.ai-packs') }}">

        <h1 class="text-2xl font-semibold tracking-tight">Billing</h1>
        <p class="mt-1 text-sm text-slate-600">Your plan, usage and invoices. Suppliers always use GetL1 free.</p>

        @if (! $canPay)
            <p class="mt-5 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">Online payment is being set up. To upgrade now, write to <a href="mailto:{{ config('billing.seller.email') }}" class="font-medium underline">{{ config('billing.seller.email') }}</a>.</p>
        @elseif ($testMode)
            <p class="mt-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">Test mode: no real money is charged. Use card 4111 1111 1111 1111, any future date, any CVV.</p>
        @endif
        <p class="mt-4 hidden rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" data-billing-error role="alert"></p>

        {{-- Current plan and usage --}}
        <div class="mt-6 grid gap-4 lg:grid-cols-3">
            <section class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5 lg:col-span-2">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Current plan</p>
                <div class="mt-1 flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-xl font-semibold">{{ $current?->name ?? 'Free' }}</h2>
                    <span class="text-sm text-slate-600">
                        @if ($status === 'trialing')
                            Free trial · ends {{ $live->trial_ends_at->ist()->format('d M Y') }}, then Free unless you choose a plan
                        @elseif ($status === 'active' && $live->cancel_at_period_end)
                            Ends {{ $live->current_period_end?->ist()->format('d M Y') }} (won’t renew)
                        @elseif ($status === 'active')
                            {{ $live->billing_cycle === 'yearly' ? 'Yearly' : 'Monthly' }} · renews {{ $live->current_period_end?->ist()->format('d M Y') }}
                        @elseif ($status === 'past_due')
                            <span class="font-medium text-red-700">Payment failed: Razorpay is retrying. Please check your card or UPI mandate.</span>
                        @else
                            Free forever
                        @endif
                    </span>
                </div>
                <div class="mt-5">
                    <div class="flex justify-between text-sm">
                        <span>Live auctions this month</span>
                        <span class="tabular-nums font-medium">{{ $used }} {{ $limit === null ? '· unlimited' : 'of '.$limit }}</span>
                    </div>
                    @if ($limit !== null)
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full {{ $pct >= 100 ? 'bg-red-500' : ($pct >= 80 ? 'bg-amber-500' : 'bg-emerald-600') }}" style="width: {{ $pct }}%"></div></div>
                    @endif
                    @php
                        $aiPct = $ai['enabled'] && $ai['limit'] ? min(100, (int) round($ai['used'] / max(1, $ai['limit']) * 100)) : 0;
                    @endphp
                    <div class="mt-5 flex justify-between text-sm">
                        <span>AI reads this month</span>
                        <span class="tabular-nums font-medium">
                            @if (! $ai['enabled']) Not included{{ $ai['credits'] ? ' · '.$ai['credits'].' prepaid' : '' }}
                            @else {{ $ai['used'] }} {{ $ai['limit'] === null ? '· unlimited' : 'of '.$ai['limit'] }}{{ $ai['credits'] ? ' · +'.$ai['credits'].' prepaid' : '' }}
                            @endif
                        </span>
                    </div>
                    @if ($ai['enabled'] && $ai['limit'] !== null)
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full {{ $aiPct >= 100 ? 'bg-red-500' : ($aiPct >= 80 ? 'bg-amber-500' : 'bg-emerald-600') }}" style="width: {{ $aiPct }}%"></div></div>
                    @endif
                    <p class="mt-3 text-xs text-slate-500">Limits reset on the 1st of every month. Unlimited RFQs, sealed quotes and suppliers on every plan.</p>
                </div>
                @if ($canManage && $status === 'active' && ! $live->cancel_at_period_end)
                    <form method="POST" action="{{ route('buyer.billing.cancel') }}" class="mt-4" data-confirm="Stop renewing? You keep the plan until the end of the paid period, then move to Free.">
                        @csrf
                        <button class="text-sm text-slate-500 underline hover:text-slate-700">Cancel renewal</button>
                    </form>
                @endif
            </section>

            <div class="space-y-4">
                <section class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Extra auctions</p>
                    <p class="mt-1 text-xl font-semibold">{{ $allowance['credits'] }} <span class="text-sm font-normal text-slate-600">{{ \Illuminate\Support\Str::plural('credit', $allowance['credits']) }} available</span></p>
                    <p class="mt-1 text-sm text-slate-600">Used automatically when the month's limit is reached. Never expire.</p>
                    @if ($canManage && $canPay)
                        <form class="mt-4 flex items-center gap-2" data-buy-credits>
                            <input type="number" name="quantity" min="1" max="{{ config('billing.auction_credit_max_qty') }}" value="1"
                                   class="w-20 rounded-lg border border-slate-300 px-3 py-2 text-sm" aria-label="Number of auctions">
                            <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                                Buy · {{ \App\Support\Money::inr($creditPrice['total'], 0) }} each
                            </button>
                        </form>
                        @if ($gst)<p class="mt-1 text-xs text-slate-500">Incl. {{ (int) $creditPrice['gst_rate'] }}% GST</p>@endif
                    @endif
                </section>

                <section class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5" id="ai-reads">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">AI reads</p>
                    <p class="mt-1 text-xl font-semibold">{{ $ai['credits'] }} <span class="text-sm font-normal text-slate-600">extra {{ \Illuminate\Support\Str::plural('read', $ai['credits']) }} available</span></p>
                    <p class="mt-1 text-sm text-slate-600">
                        Create RFQs from a WhatsApp message, Excel, PDF or photo. Extra reads are used after your plan's reads, on any plan. Never expire; a failed read is refunded.
                    </p>
                    @if ($canManage && $canPay)
                        <form class="mt-4 flex items-center gap-2" data-buy-ai>
                            <input type="number" name="quantity" min="1" max="{{ config('billing.ai_pack_max_qty') }}" value="1"
                                   class="w-20 rounded-lg border border-slate-300 px-3 py-2 text-sm" aria-label="Number of AI packs">
                            <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                                Buy · {{ \App\Support\Money::inr($aiPackPrice['total'], 0) }} per {{ config('billing.ai_pack_reads') }} reads
                            </button>
                        </form>
                        @if ($gst)<p class="mt-1 text-xs text-slate-500">Incl. {{ (int) $aiPackPrice['gst_rate'] }}% GST</p>@endif
                    @endif
                </section>
            </div>
        </div>

        {{-- Plans --}}
        <div class="mt-10 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold">Plans</h2>
                <p class="text-sm text-slate-600">One auction typically saves 5–10% on an order. Most plans pay for themselves on the first one.</p>
            </div>
            <div class="inline-flex rounded-lg border border-slate-300 bg-white p-0.5 text-sm" role="group" aria-label="Billing cycle">
                <button type="button" data-cycle="monthly" class="rounded-md bg-slate-900 px-3 py-1.5 font-medium text-white">Monthly</button>
                <button type="button" data-cycle="yearly" class="rounded-md px-3 py-1.5 font-medium text-slate-700">Yearly <span class="text-emerald-700">· 2 months free</span></button>
            </div>
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($plans as $plan)
                @php
                    $isCurrent = $current && $current->id === $plan->id;
                    $popular = $plan->code === 'growth';
                    $features = $plan->featureList();
                @endphp
                <section class="relative flex flex-col rounded-xl border bg-white p-5 {{ $popular ? 'border-emerald-600 ring-1 ring-emerald-600' : 'border-slate-200' }}">
                    @if ($popular)<span class="absolute -top-2.5 left-5 rounded-full bg-emerald-700 px-2 py-0.5 text-[11px] font-semibold text-white">Most popular</span>@endif
                    <h3 class="font-semibold">{{ $plan->name }}</h3>
                    <p class="text-xs text-slate-500">{{ $plan->tagline }}</p>
                    <div class="mt-3 min-h-14">
                        @if ($plan->contact_sales)
                            <p class="text-2xl font-semibold">Talk to us</p>
                            <p class="text-xs text-slate-500">Custom pricing for larger teams</p>
                        @elseif ((float) $plan->price_monthly <= 0)
                            <p class="text-2xl font-semibold">₹0</p>
                            <p class="text-xs text-slate-500">Free forever</p>
                        @else
                            <p data-show-cycle="monthly"><span class="text-2xl font-semibold">{{ $inr($plan->price_monthly) }}</span><span class="text-sm text-slate-500">/month</span></p>
                            <p data-show-cycle="yearly" hidden><span class="text-2xl font-semibold">{{ $inr($plan->price_yearly) }}</span><span class="text-sm text-slate-500">/year</span></p>
                            <p class="text-xs text-slate-500">{{ $gst ? '+ '.(int) config('billing.gst_rate').'% GST' : 'All-inclusive' }}</p>
                        @endif
                    </div>
                    <ul class="mt-4 flex-1 space-y-1.5 text-sm text-slate-700">
                        @foreach ($features as $f)<li class="flex gap-2"><span class="text-emerald-700">✓</span>{{ $f }}</li>@endforeach
                    </ul>
                    <div class="mt-5">
                        @if ($isCurrent && $status !== 'trialing')
                            <span class="block rounded-lg border border-slate-300 px-4 py-2 text-center text-sm font-medium text-slate-600">Current plan</span>
                        @elseif ($plan->contact_sales)
                            <a href="mailto:{{ config('billing.seller.email') }}?subject={{ rawurlencode('GetL1 '.$plan->name.' plan') }}" class="block rounded-lg border border-slate-300 px-4 py-2 text-center text-sm font-semibold hover:bg-slate-50">Contact us</a>
                        @elseif ((float) $plan->price_monthly <= 0)
                            <span class="block px-4 py-2 text-center text-xs text-slate-500">You move here automatically after a trial or paid plan ends</span>
                        @elseif ($canManage && $canPay)
                            <button type="button" data-subscribe="{{ $plan->code }}" class="w-full rounded-lg px-4 py-2 text-sm font-semibold {{ $popular ? 'bg-emerald-700 text-white hover:bg-emerald-800' : 'border border-emerald-700 text-emerald-800 hover:bg-emerald-50' }}">
                                {{ $isCurrent ? 'Keep '.$plan->name.' after trial' : 'Choose '.$plan->name }}
                            </button>
                        @elseif (! $canManage)
                            <span class="block text-center text-xs text-slate-500">Ask your company admin to change the plan</span>
                        @endif
                    </div>
                </section>
            @endforeach
        </div>

        {{-- Invoices --}}
        <section class="mt-10 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <h2 class="border-b border-slate-100 px-6 py-4 font-semibold">Invoices</h2>
            <table class="w-full min-w-[560px] text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr><th class="px-4 py-2">Date</th><th class="px-4 py-2">Description</th><th class="px-4 py-2 text-right">Amount</th><th class="px-4 py-2"></th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($payments as $p)
                        <tr>
                            <td class="px-4 py-2">{{ $p->paid_at?->ist()->format('d M Y') }}<span class="block text-xs text-slate-500">{{ $p->invoice_number }}</span></td>
                            <td class="px-4 py-2">{{ $p->description() }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ \App\Support\Money::inr($p->total) }}</td>
                            <td class="px-4 py-2 text-right">@if ($p->invoice_pdf_path)<a href="{{ route('buyer.billing.invoice', $p->id) }}" class="text-emerald-700 hover:underline">Download</a>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-slate-600">No payments yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    </div>
@endsection
