@extends('layouts.admin')

@section('title', $org->name)

@section('content')
    @php
        $input = 'rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm';
        $btn = 'rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800';
    @endphp
    <a href="{{ route('admin.companies.index') }}" class="text-sm text-slate-600 hover:text-slate-900">← Companies</a>
    <div class="mt-2 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">{{ $org->name }}
                @if ($org->status === 'suspended')<span class="ml-2 rounded bg-red-100 px-2 py-0.5 align-middle text-sm text-red-700">Suspended</span>@endif
            </h1>
            <p class="mt-1 text-sm text-slate-600">{{ $org->type->label() }} · joined {{ $org->created_at->ist()->format('d M Y') }}{{ $org->verified_at ? ' · KYC verified' : '' }}</p>
        </div>
    </div>

    <div class="mt-6 grid gap-4 lg:grid-cols-3">
        <section class="rounded-xl border border-slate-200 bg-white p-5 text-sm lg:col-span-2">
            <h2 class="font-semibold">Details</h2>
            <dl class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2">
                @foreach (['GSTIN' => $org->gstin, 'PAN' => $org->pan, 'Udyam' => $org->udyam_no, 'Email' => $org->email, 'Phone' => $org->phone,
                           'Address' => collect([$org->address, $org->city, $org->state, $org->pincode])->filter()->implode(', ')] as $k => $v)
                    <div><dt class="text-xs text-slate-500">{{ $k }}</dt><dd class="{{ in_array($k, ['GSTIN', 'PAN'], true) ? 'font-mono' : '' }}">{{ $v ?: '—' }}</dd></div>
                @endforeach
            </dl>
            <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach ($counts as $label => $n)
                    <div class="rounded-lg bg-slate-50 p-3"><p class="text-xs text-slate-500">{{ $label }}</p><p class="text-lg font-semibold tabular-nums">{{ $n }}</p></div>
                @endforeach
            </div>

            <h2 class="mt-6 font-semibold">Users</h2>
            <ul class="mt-2 divide-y divide-slate-100">
                @foreach ($org->users as $u)
                    <li class="flex flex-wrap justify-between gap-2 py-2">
                        <span>{{ $u->name }} <span class="text-slate-500">· {{ $u->email }}{{ $u->phone ? ' · '.$u->phone : '' }}</span></span>
                        <span class="text-xs text-slate-500">{{ \App\Enums\OrgRole::from($u->pivot->role)->label() }}{{ $u->pivot->is_owner ? ' · owner' : '' }} · last login {{ $u->last_login_at?->ist()->format('d M, h:i A') ?? 'never' }}</span>
                    </li>
                @endforeach
            </ul>
        </section>

        <div class="space-y-4">
            @if ($org->isBuyer())
                <section class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
                    <h2 class="font-semibold">Plan</h2>
                    <p class="mt-1">{{ $plan?->name ?? 'Free' }}
                        @if ($live?->status?->value === 'trialing') <span class="text-slate-500">· trial ends {{ $live->trial_ends_at->ist()->format('d M Y') }}</span>
                        @elseif ($live) <span class="text-slate-500">· {{ $live->billing_cycle }} · {{ str_replace('_', ' ', $live->status->value) }}{{ $live->current_period_end ? ', renews '.$live->current_period_end->ist()->format('d M Y') : '' }}</span>
                        @endif
                    </p>
                    <p class="mt-2 text-slate-600">Auctions this month: {{ $auctions['used'] }} {{ $auctions['limit'] === null ? '(unlimited)' : 'of '.$auctions['limit'] }} · {{ $auctions['credits'] }} extra credits</p>
                    <p class="text-slate-600">AI reads this month: {{ $ai['used'] }}{{ $ai['enabled'] && $ai['limit'] !== null ? ' of '.$ai['limit'] : '' }} · {{ $ai['credits'] }} prepaid</p>

                    @if (! $live || $live->status->value === 'trialing')
                        <form method="POST" action="{{ route('admin.companies.trial', $org->id) }}" class="mt-4 space-y-2 border-t border-slate-100 pt-4">
                            @csrf
                            <p class="font-medium">{{ $live ? 'Extend trial' : 'Give a Growth trial' }}</p>
                            <div class="flex gap-2"><input name="days" type="number" min="1" max="60" value="14" class="{{ $input }} w-20" aria-label="Days"><span class="self-center text-slate-500">days</span></div>
                            <input name="reason" required minlength="5" maxlength="200" placeholder="Reason (kept in the audit log)" class="{{ $input }} w-full">
                            <button class="{{ $btn }}">Save</button>
                        </form>
                    @endif

                    <form method="POST" action="{{ route('admin.companies.credits', $org->id) }}" class="mt-4 space-y-2 border-t border-slate-100 pt-4">
                        @csrf
                        <p class="font-medium">Add free credits</p>
                        <div class="flex gap-2">
                            <input name="quantity" type="number" min="1" max="500" value="1" class="{{ $input }} w-20" aria-label="Quantity">
                            <select name="kind" class="{{ $input }}"><option value="auction_credits">Auction credits</option><option value="ai_credits">AI reads</option></select>
                        </div>
                        <input name="reason" required minlength="5" maxlength="200" placeholder="Reason (kept in the audit log)" class="{{ $input }} w-full">
                        <button class="{{ $btn }}">Add</button>
                    </form>
                </section>
            @endif

            <section class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
                @if ($org->status === 'suspended')
                    <h2 class="font-semibold">Restore access</h2>
                    <form method="POST" action="{{ route('admin.companies.restore', $org->id) }}" class="mt-2 space-y-2">
                        @csrf
                        <input name="reason" required minlength="5" maxlength="200" placeholder="Reason" class="{{ $input }} w-full">
                        <button class="{{ $btn }}">Restore</button>
                    </form>
                @else
                    <h2 class="font-semibold text-red-700">Suspend company</h2>
                    <p class="mt-1 text-slate-600">All its users lose access until restored. Use for fraud or abuse only.</p>
                    <form method="POST" action="{{ route('admin.companies.suspend', $org->id) }}" class="mt-2 space-y-2" data-confirm="Suspend {{ $org->name }}? All its users lose access.">
                        @csrf
                        <input name="reason" required minlength="5" maxlength="200" placeholder="Reason" class="{{ $input }} w-full">
                        <button class="rounded-lg border border-red-300 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">Suspend</button>
                    </form>
                @endif
            </section>
        </div>
    </div>

    @if ($documents->isNotEmpty())
        <section class="mt-4 rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold">KYC documents</h2>
            <ul class="mt-2 divide-y divide-slate-100">
                @foreach ($documents as $d)
                    <li class="flex justify-between gap-2 py-2"><span>{{ strtoupper($d->type) }} · {{ $d->original_name }}</span><span class="text-slate-500">{{ ucfirst($d->status) }}</span></li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($payments->isNotEmpty())
        <section class="mt-4 rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold">Payments</h2>
            <ul class="mt-2 divide-y divide-slate-100">
                @foreach ($payments as $p)
                    <li class="flex flex-wrap justify-between gap-2 py-2">
                        <span>{{ $p->description() }} <span class="text-slate-500">· {{ $p->status }}{{ $p->invoice_number ? ' · '.$p->invoice_number : '' }}</span></span>
                        <span class="tabular-nums">{{ \App\Support\Money::inr($p->total) }} <span class="text-slate-500">· {{ ($p->paid_at ?? $p->created_at)->ist()->format('d M Y') }}</span></span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="mt-4 rounded-xl border border-slate-200 bg-white p-5 text-sm">
        <h2 class="font-semibold">Recent activity</h2>
        @include('admin._audit-rows', ['logs' => $activity])
        <a href="{{ route('admin.audit', ['org' => $org->id]) }}" class="mt-3 inline-block text-emerald-700 underline">Full audit log</a>
    </section>
@endsection
