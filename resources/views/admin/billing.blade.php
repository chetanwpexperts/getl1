@extends('layouts.admin')

@section('title', 'Billing & GST')

@section('content')
    @php
        $input = 'mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20';
        $val = fn ($k) => old($k, $saved[$k] ?? null);
        $on = (bool) $current['gst_enabled'];
    @endphp

    <h1 class="text-2xl font-semibold">Billing &amp; GST</h1>
    <p class="mt-1 text-sm text-slate-600">GST and the details printed on payment receipts and tax invoices. Changes apply to new payments; receipts already issued don't change. Every change is logged.</p>

    <div class="mt-6 grid max-w-5xl gap-6 lg:grid-cols-[minmax(0,1fr)_18rem]">
        <form method="POST" action="{{ route('admin.billing.update') }}" class="space-y-6" data-gst-form>
            @csrf

            <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-3.5">
                    <h2 class="font-semibold">GST</h2>
                    <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $on ? 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200' : 'bg-slate-100 text-slate-600' }}">
                        {{ $on ? 'Charging '.(int) $current['gst_rate'].'% GST' : 'Not charging GST' }}
                    </span>
                </div>
                <div class="divide-y divide-slate-100">
                    <div class="grid gap-2 px-5 py-4 md:grid-cols-[14rem_minmax(0,1fr)]">
                        <div>
                            <label for="gst_enabled" class="text-sm font-medium">{{ $fields['gst_enabled'][2] }}</label>
                            <p class="mt-0.5 text-xs text-slate-500">{{ $fields['gst_enabled'][3] }}</p>
                        </div>
                        <div>
                            <label class="inline-flex items-center gap-2 text-sm">
                                <input id="gst_enabled" type="checkbox" name="gst_enabled" value="1" @checked(old('gst_enabled', $on)) data-gst-toggle data-was="{{ $on ? 1 : 0 }}" class="size-4 rounded border-slate-300 accent-emerald-700">
                                GetL1 is GST-registered, charge GST
                            </label>
                            @error('gst_enabled')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    @foreach (['gstin', 'gst_rate', 'sac'] as $key)
                        <div class="grid gap-2 px-5 py-4 md:grid-cols-[14rem_minmax(0,1fr)]">
                            <div>
                                <label for="{{ $key }}" class="text-sm font-medium">{{ $fields[$key][2] }}</label>
                                <p class="mt-0.5 text-xs text-slate-500">{{ $fields[$key][3] }}</p>
                            </div>
                            <div>
                                @if ($key === 'gst_rate')
                                    <select id="gst_rate" name="gst_rate" class="{{ $input }} max-w-32">
                                        @foreach ($rates as $r)
                                            <option value="{{ $r }}" @selected((int) old('gst_rate', $saved['gst_rate'] ?? $current['gst_rate']) === $r)>{{ $r }}%</option>
                                        @endforeach
                                    </select>
                                @else
                                    <input id="{{ $key }}" name="{{ $key }}" value="{{ $val($key) ?? $current[$key] }}"
                                           maxlength="{{ $key === 'gstin' ? 15 : 6 }}" autocomplete="off" spellcheck="false"
                                           placeholder="{{ $key === 'gstin' ? '06ABCDE1234F1Z5' : '998314' }}"
                                           class="{{ $input }} max-w-64 font-mono uppercase tracking-wide">
                                @endif
                                @error($key)<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-100 px-5 py-3.5 font-semibold">Printed on receipts and invoices</h2>
                <div class="divide-y divide-slate-100">
                    @foreach (['seller_name', 'seller_address', 'seller_email'] as $key)
                        <div class="grid gap-2 px-5 py-4 md:grid-cols-[14rem_minmax(0,1fr)]">
                            <div>
                                <label for="{{ $key }}" class="text-sm font-medium">{{ $fields[$key][2] }}</label>
                                <p class="mt-0.5 text-xs text-slate-500">{{ $fields[$key][3] }}</p>
                            </div>
                            <div>
                                @if ($key === 'seller_address')
                                    <textarea id="{{ $key }}" name="{{ $key }}" rows="3" maxlength="300" placeholder="{{ $current[$key] }}" class="{{ $input }}">{{ $val($key) }}</textarea>
                                @else
                                    <input id="{{ $key }}" name="{{ $key }}" value="{{ $val($key) }}" type="{{ $key === 'seller_email' ? 'email' : 'text' }}" maxlength="{{ $key === 'seller_name' ? 160 : 190 }}" placeholder="{{ $current[$key] }}" class="{{ $input }}">
                                @endif
                                @error($key)<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div data-gst-confirm @if (! $errors->has('confirm_gst')) hidden @endif class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    <p class="font-semibold" data-gst-confirm-title>You're changing whether GST is charged.</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-amber-900/90">
                        <li>Prices on the website and in the app change straight away.</li>
                        <li>New payments get {{ $on ? 'a payment receipt without GST' : 'a tax invoice with GST' }}. Past receipts don't change.</li>
                        @if ($activeSubscriptions)
                            <li><strong>{{ $activeSubscriptions }} auto-renewing {{ \Illuminate\Support\Str::plural('subscription', $activeSubscriptions) }}</strong> keep the amount they started with until the customer renews or changes plan. Razorpay fixes it when a subscription starts.</li>
                        @endif
                    </ul>
                    <label class="mt-3 flex items-start gap-2 font-medium">
                        <input type="checkbox" name="confirm_gst" value="1" class="mt-0.5 size-4 rounded border-amber-400 accent-amber-700"> I understand, make this change
                    </label>
                    @error('confirm_gst')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                </div>
                <div class="flex flex-wrap items-end gap-3">
                    <div class="min-w-64 flex-1">
                        <label for="reason" class="text-sm font-medium">Reason for the change</label>
                        <input id="reason" name="reason" required minlength="5" maxlength="200" value="{{ old('reason') }}" placeholder="e.g. GST registration approved" class="{{ $input }}">
                        @error('reason')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <button class="rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">Save</button>
                </div>
            </section>
        </form>

        <aside class="space-y-4 text-sm">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="font-semibold">How customers are billed now</h2>
                <dl class="mt-3 space-y-2 text-slate-600">
                    <div class="flex justify-between gap-3"><dt>Document</dt><dd class="font-medium text-slate-900">{{ $on ? 'Tax invoice' : 'Payment receipt' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt>GST</dt><dd class="font-medium text-slate-900">{{ $on ? (int) $current['gst_rate'].'% on top of the price' : 'None' }}</dd></div>
                    @if ($on)
                        <div class="flex justify-between gap-3"><dt>Within {{ $current['gstin'] ? 'your state' : 'state' }}</dt><dd class="font-medium text-slate-900">CGST + SGST</dd></div>
                        <div class="flex justify-between gap-3"><dt>Other states</dt><dd class="font-medium text-slate-900">IGST</dd></div>
                    @endif
                    <div class="flex justify-between gap-3"><dt>Billed by</dt><dd class="truncate font-medium text-slate-900">{{ $current['seller_name'] }}</dd></div>
                </dl>
            </div>
            <p class="px-1 text-xs leading-relaxed text-slate-500">GST registration is compulsory once yearly turnover passes ₹20 lakh for services. Many companies also prefer suppliers with a GSTIN so they can claim the GST back. Check rates and codes with your CA.</p>
        </aside>
    </div>

    <script>
        (() => {
            const t = document.querySelector('[data-gst-toggle]');
            const box = document.querySelector('[data-gst-confirm]');
            if (!t || !box) return;
            const sync = () => { box.hidden = (t.checked ? '1' : '0') === t.dataset.was; };
            t.addEventListener('change', sync);
            sync();
        })();
    </script>
@endsection
