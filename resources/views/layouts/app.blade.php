<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ config('site.name') }}</title>
    <x-favicon />
    <x-pwa />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
@php
    $user = auth()->user();
    $isBuyer = isset($currentOrg) && $currentOrg->isBuyer();
    $role = $currentRole?->value ?? null;
    $canBuy = $isBuyer && in_array($role, ['buyer_admin', 'buyer_user'], true);
    $initials = collect(preg_split('/\s+/', trim((string) $user->name)))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('') ?: '?';

    // [route, label, icon, active pattern, badge]
    $groups = [];
    if (isset($currentOrg)) {
        $groups[''] = [['dashboard', 'Dashboard', 'home', 'dashboard', null]];
        if ($isBuyer) {
            $groups['Buying'] = array_values(array_filter([
                ['buyer.rfqs.index', 'RFQs & auctions', 'rfqs', 'buyer.rfqs.*|buyer.auctions.*', null],
                in_array($role, ['buyer_admin', 'approver'], true) ? ['buyer.approvals.index', 'Approvals', 'approvals', 'buyer.approvals.*', $pendingApprovals ?? 0] : null,
                ['buyer.orders.index', 'Purchase orders', 'orders', 'buyer.orders.*', null],
                ['buyer.payments.index', 'Payments', 'billing', 'buyer.payments.*', $paymentsAttention ?? 0],
                ['buyer.suppliers.index', 'Suppliers', 'suppliers', 'buyer.suppliers.*', null],
            ]));
            $groups['Insights'] = [['buyer.reports.savings', 'Savings', 'savings', 'buyer.reports.*', null]];
            $groups['Company'] = [
                ['company.edit', 'Company profile', 'company', 'company.*', null],
                ['team.index', 'Team', 'team', 'team.*', null],
                ['buyer.billing.index', 'Plan & billing', 'billing', 'buyer.billing.*', null],
            ];
        } else {
            $groups['Selling'] = [
                ['supplier.rfqs.index', 'RFQs & auctions', 'rfqs', 'supplier.rfqs.*|supplier.auctions.*', null],
                ['supplier.orders.index', 'Purchase orders', 'orders', 'supplier.orders.*', $openOrders ?? 0],
            ];
            $groups['Company'] = [
                ['company.edit', 'Company profile', 'company', 'company.*', null],
                ['team.index', 'Team', 'team', 'team.*', null],
                ['supplier.documents.index', 'Documents & KYC', 'documents', 'supplier.documents.*', null],
            ];
        }
    }
    $groups['Account'] = [['account.edit', 'My profile', 'profile', 'account.*', null]];
    $is = fn ($pattern) => request()->routeIs(...explode('|', $pattern));
@endphp
<body class="min-h-full bg-slate-50 font-sans text-slate-900 antialiased" data-server-time="{{ now()->getTimestampMs() }}">
@php
    // Top menu: the main pages as tabs; company pages under one "Company" menu.
    $tabs = [];
    $companyItems = [];
    foreach ($groups as $heading => $items) {
        foreach ($items as $item) {
            if (in_array($heading, ['Company', 'Account'], true)) {
                if ($heading === 'Company') { $companyItems[] = $item; }
                continue;
            }
            $tabs[] = $item;
        }
    }
    $companyOn = collect($companyItems)->contains(fn ($i) => $is($i[3]));
@endphp
<div class="flex min-h-full flex-col">
    <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur">
        {{-- Row 1: logo and company, actions and account --}}
        <div class="mx-auto flex h-20 max-w-7xl items-center gap-5 px-4 sm:px-6 lg:px-8">
            <a href="{{ route('dashboard') }}" class="shrink-0" aria-label="Dashboard"><x-logo size="text-2xl" height="h-11" /></a>

            @isset($currentOrg)
                @php $orgs = $user->organizations; @endphp
                <span class="hidden h-8 w-px bg-slate-200 sm:block" aria-hidden="true"></span>
                <details class="relative hidden min-w-0 sm:block" data-dropdown>
                    <summary class="flex max-w-xs cursor-pointer list-none items-center gap-2.5 rounded-lg px-2 py-1.5 hover:bg-slate-50 [&::-webkit-details-marker]:hidden">
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-md {{ $isBuyer ? 'bg-emerald-700' : 'bg-sky-700' }} text-xs font-bold text-white">{{ mb_strtoupper(mb_substr($currentOrg->name, 0, 1)) }}</span>
                        <span class="min-w-0 leading-tight">
                            <span class="block truncate text-sm font-semibold">{{ $currentOrg->name }}</span>
                            <span class="block truncate text-[11px] text-slate-500">{{ $currentOrg->type->label() }}{{ $currentRole && $currentRole->label() !== $currentOrg->type->label() ? ' · '.$currentRole->label() : '' }}</span>
                        </span>
                        @if ($orgs->count() > 1)<x-icon name="updown" class="size-4 text-slate-400" />@endif
                    </summary>
                    @if ($orgs->count() > 1)
                        <div class="absolute left-0 top-full z-40 mt-2 w-72 rounded-xl border border-slate-200 bg-white p-1 shadow-xl">
                            <p class="px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">Switch company</p>
                            @foreach ($orgs as $org)
                                <form method="POST" action="{{ route('organizations.switch', $org) }}">
                                    @csrf
                                    <button class="flex w-full items-center justify-between gap-2 rounded-lg px-3 py-2 text-left text-sm hover:bg-slate-50">
                                        <span class="min-w-0"><span class="block truncate {{ $org->id === $currentOrg->id ? 'font-semibold' : '' }}">{{ $org->name }}</span><span class="block text-xs text-slate-500">{{ $org->type->label() }}</span></span>
                                        @if ($org->id === $currentOrg->id)<span class="text-emerald-700">✓</span>@endif
                                    </button>
                                </form>
                            @endforeach
                        </div>
                    @endif
                </details>
            @endisset

            <div class="ml-auto flex items-center gap-2 sm:gap-3">
                @isset($navAuction)
                    <a href="{{ $navAuction['url'] }}" title="{{ $navAuction['title'] }}"
                       class="inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-semibold shadow-sm {{ $navAuction['live'] ? 'bg-emerald-600 text-white hover:bg-emerald-700' : 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200 hover:bg-emerald-100' }}">
                        <span class="relative flex size-2">
                            <span class="absolute inline-flex size-full animate-ping rounded-full {{ $navAuction['live'] ? 'bg-white' : 'bg-emerald-500' }} opacity-75"></span>
                            <span class="relative inline-flex size-2 rounded-full {{ $navAuction['live'] ? 'bg-white' : 'bg-emerald-600' }}"></span>
                        </span>
                        @if ($navAuction['live'])
                            <span>Auction live<span class="hidden sm:inline"> · Join</span></span>
                        @else
                            <span>Auction <span class="tabular-nums" data-countdown-to="{{ $navAuction['starts_ms'] }}" data-countdown-prefix="in " data-countdown-done="starting"></span></span>
                        @endif
                    </a>
                @endisset

                @if ($canBuy)
                    <a href="{{ route('buyer.rfqs.create') }}" class="hidden items-center gap-1.5 rounded-lg bg-emerald-700 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 sm:inline-flex">
                        <x-icon name="plus" class="size-4" /> New RFQ
                    </a>
                @endif

                <x-install-app />
                {{-- Account menu --}}
                <details class="relative" data-dropdown>
                    <summary class="flex cursor-pointer list-none items-center gap-2 rounded-full p-0.5 pr-1 hover:bg-slate-100 [&::-webkit-details-marker]:hidden" aria-label="Account menu">
                        <span class="flex size-9 items-center justify-center rounded-full bg-slate-900 text-xs font-semibold text-white">{{ $initials }}</span>
                        <x-icon name="down" class="hidden size-4 text-slate-400 sm:block" />
                    </summary>
                    <div class="absolute right-0 top-full z-40 mt-2 w-64 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl">
                        <div class="border-b border-slate-100 px-4 py-3">
                            <p class="truncate text-sm font-semibold">{{ $user->name }}</p>
                            <p class="truncate text-xs text-slate-500">{{ $user->email }}</p>
                            @isset($currentOrg)<p class="mt-2 truncate text-xs font-medium text-slate-700 sm:hidden">{{ $currentOrg->name }} · {{ $currentOrg->type->label() }}</p>@endisset
                        </div>
                        <div class="p-1 text-sm">
                            <a href="{{ route('account.edit') }}" class="flex items-center gap-3 rounded-lg px-3 py-2 text-slate-700 hover:bg-slate-50"><x-icon name="profile" class="size-5 text-slate-400" /> My profile</a>
                            @isset($currentOrg)
                                <a href="{{ route('company.edit') }}" class="flex items-center gap-3 rounded-lg px-3 py-2 text-slate-700 hover:bg-slate-50"><x-icon name="company" class="size-5 text-slate-400" /> Company profile</a>
                                @if ($isBuyer)
                                    <a href="{{ route('buyer.billing.index') }}" class="flex items-center gap-3 rounded-lg px-3 py-2 text-slate-700 hover:bg-slate-50"><x-icon name="billing" class="size-5 text-slate-400" /> Plan &amp; billing</a>
                                @else
                                    <a href="{{ route('supplier.documents.index') }}" class="flex items-center gap-3 rounded-lg px-3 py-2 text-slate-700 hover:bg-slate-50"><x-icon name="documents" class="size-5 text-slate-400" /> Documents &amp; KYC</a>
                                @endif
                            @endisset
                            @if ($user->is_platform_admin)
                                <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-3 rounded-lg px-3 py-2 text-slate-700 hover:bg-slate-50"><x-icon name="admin" class="size-5 text-slate-400" /> Admin console</a>
                            @endif
                        </div>
                        <form method="POST" action="{{ route('logout') }}" class="border-t border-slate-100 p-1">
                            @csrf
                            <button class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50"><x-icon name="logout" class="size-5 text-slate-400" /> Log out</button>
                        </form>
                    </div>
                </details>
            </div>
        </div>

        {{-- Row 2: main menu --}}
        <nav class="border-t border-slate-100" aria-label="Main menu"><div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <ul class="-mb-px flex items-center gap-8 overflow-x-auto text-sm font-medium [scrollbar-width:none] lg:overflow-visible">
                @foreach ($tabs as [$route, $label, $icon, $pattern, $badge])
                    @php $on = $is($pattern); @endphp
                    <li class="shrink-0">
                        <a href="{{ route($route) }}" @if ($on) aria-current="page" @endif
                           class="flex items-center gap-2 border-b-2 px-0.5 py-3.5 transition {{ $on ? 'border-emerald-700 text-emerald-800' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800' }}">
                            <x-icon :name="$icon" class="size-[18px] {{ $on ? 'text-emerald-700' : 'text-slate-400' }}" />
                            {{ $label }}
                            @if ($badge)<span class="rounded-full bg-amber-500 px-1.5 py-0.5 text-[10px] font-bold leading-none text-white">{{ $badge }}</span>@endif
                        </a>
                    </li>
                @endforeach
                @if ($companyItems)
                    <li class="shrink-0">
                        <details class="relative" data-dropdown>
                            <summary class="flex cursor-pointer list-none items-center gap-2 border-b-2 px-0.5 py-3.5 transition [&::-webkit-details-marker]:hidden {{ $companyOn ? 'border-emerald-700 text-emerald-800' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800' }}">
                                <x-icon name="company" class="size-[18px] {{ $companyOn ? 'text-emerald-700' : 'text-slate-400' }}" /> Company <x-icon name="down" class="size-3.5" />
                            </summary>
                            <div class="fixed z-40 mt-1 w-60 rounded-xl border border-slate-200 bg-white p-1 shadow-xl lg:absolute lg:left-0 lg:top-full">
                                @foreach ($companyItems as [$route, $label, $icon, $pattern])
                                    <a href="{{ route($route) }}" class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm {{ $is($pattern) ? 'bg-emerald-50 font-semibold text-emerald-800' : 'text-slate-700 hover:bg-slate-50' }}">
                                        <x-icon :name="$icon" class="size-5 text-slate-400" /> {{ $label }}
                                    </a>
                                @endforeach
                            </div>
                        </details>
                    </li>
                @endif
            </ul>
        </div></nav>
    </header>

    <main class="mx-auto w-full max-w-7xl flex-1 px-4 py-8 sm:px-6 sm:py-12 lg:px-8">
        @if (session('status'))
            <div class="mb-6 flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">
                <span class="mt-px flex size-5 shrink-0 items-center justify-center rounded-full bg-emerald-600 text-[11px] font-bold text-white" aria-hidden="true">✓</span>
                <span>{{ session('status') }}</span>
            </div>
        @endif

        @if ($errors->any() && ! isset($inlineErrors))
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                Please fix the highlighted fields.
            </div>
        @endif

        @yield('content')
    </main>

    <footer class="mx-auto w-full max-w-7xl px-4 pb-8 sm:px-6 lg:px-8">
        <div class="flex flex-wrap justify-between gap-3 border-t border-slate-200 pt-5 text-xs text-slate-500">
            <span>© {{ date('Y') }} {{ config('site.name') }} · Times shown in IST<x-credit class="before:mx-1.5 before:content-['·']" link-class="font-medium text-slate-600 hover:text-slate-800" /></span>
            <span class="flex gap-4">
                <a href="mailto:{{ config('site.email') }}" class="hover:text-slate-700">Help: {{ config('site.email') }}</a>
                <a href="{{ route('site.terms') }}" class="hover:text-slate-700">Terms</a>
                <a href="{{ route('site.privacy') }}" class="hover:text-slate-700">Privacy</a>
            </span>
        </div>
    </footer>
</div>
</body>
</html>
