@extends('layouts.app')

@section('title', 'Team')

@section('content')
    @php
        $input = 'mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20';
        $roleHelp = [
            'buyer_admin' => 'Everything, including team, billing and approvals.',
            'buyer_user' => 'Creates RFQs, runs auctions and manages suppliers.',
            'approver' => 'Reviews and approves awards. Can view RFQs.',
            'supplier_user' => 'Quotes, bids and accepts purchase orders.',
        ];
        $used = $members->count();
        $full = $seats !== null && $used >= $seats;
        $initials = fn ($n) => collect(preg_split('/\s+/', trim((string) $n)))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('');
    @endphp

    <x-page-header title="Team" :subtitle="'People who can sign in for '.$org->name.'.'">
        @if ($seats !== null)
            <span class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3.5 py-1.5 text-sm shadow-sm">
                <span class="font-semibold tabular-nums">{{ $used }} of {{ $seats }}</span> <span class="text-slate-500">seats used</span>
            </span>
        @endif
    </x-page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="rounded-2xl border border-slate-200 bg-white shadow-sm lg:col-span-2 lg:self-start">
            <div class="border-b border-slate-100 px-6 py-4">
                <h2 class="font-semibold">Members</h2>
            </div>
            <ul class="divide-y divide-slate-100">
                @foreach ($members as $m)
                    @php
                        $isOwner = (bool) $m->pivot->is_owner;
                        $isMe = $m->id === auth()->id();
                        $pending = $m->invited_at && ! $m->last_login_at;
                        $role = \App\Enums\OrgRole::tryFrom((string) $m->pivot->role);
                        $editable = $canManage && ! $isOwner && ! $isMe;
                    @endphp
                    <li class="flex flex-wrap items-center gap-4 px-6 py-4">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-full {{ $pending ? 'bg-slate-100 text-slate-500' : 'bg-slate-900 text-white' }} text-sm font-semibold">{{ $initials($m->name) ?: '?' }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-slate-900">
                                {{ $m->name }}
                                @if ($isMe)<span class="text-xs font-normal text-slate-400">(you)</span>@endif
                                @if ($isOwner)<span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600">Owner</span>@endif
                                @if ($pending)<span class="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-800 ring-1 ring-amber-200">Invitation sent</span>@endif
                            </p>
                            <p class="truncate text-xs text-slate-500">
                                {{ $m->email }}{{ $m->phone ? ' · '.$m->phone : '' }}
                                @if ($m->last_login_at) · last sign-in {{ $m->last_login_at->ist()->format('d M Y') }}@endif
                            </p>
                        </div>

                        <div class="flex items-center gap-2">
                            @if ($editable && count($roles) > 1)
                                <form method="POST" action="{{ route('team.role', $m->id) }}">
                                    @csrf @method('PUT')
                                    <label class="sr-only" for="role-{{ $m->id }}">Role for {{ $m->name }}</label>
                                    <select id="role-{{ $m->id }}" name="role" onchange="this.form.requestSubmit()" class="rounded-lg border border-slate-300 bg-white py-1.5 pl-3 pr-8 text-sm">
                                        @foreach ($roles as $r)
                                            <option value="{{ $r->value }}" @selected($role === $r)>{{ $r->label() }}</option>
                                        @endforeach
                                    </select>
                                </form>
                            @else
                                <span class="rounded-lg bg-slate-50 px-3 py-1.5 text-sm text-slate-700">{{ $role?->label() }}</span>
                            @endif

                            @if ($editable)
                                <details class="relative" data-dropdown>
                                    <summary class="flex cursor-pointer list-none items-center rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 [&::-webkit-details-marker]:hidden" aria-label="More actions for {{ $m->name }}">
                                        <svg viewBox="0 0 20 20" fill="currentColor" class="size-5" aria-hidden="true"><path d="M10 3a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Zm0 5.5a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3ZM11.5 15.5a1.5 1.5 0 1 0-3 0 1.5 1.5 0 0 0 3 0Z"/></svg>
                                    </summary>
                                    <div class="absolute right-0 top-full z-20 mt-1 w-64 rounded-xl border border-slate-200 bg-white p-1 shadow-xl">
                                        @if ($pending)
                                            <form method="POST" action="{{ route('team.resend', $m->id) }}">
                                                @csrf
                                                <button class="w-full rounded-lg px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50">Resend invitation</button>
                                            </form>
                                        @endif
                                        <form method="POST" action="{{ route('team.destroy', $m->id) }}" class="border-t border-slate-100 p-2 first:border-0">
                                            @csrf @method('DELETE')
                                            <p class="px-1 text-xs text-slate-500">{{ $m->name }} will lose access to {{ $org->name }} straight away.</p>
                                            <button class="mt-2 w-full rounded-lg bg-red-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-red-700">Remove from team</button>
                                        </form>
                                    </div>
                                </details>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>

        <aside class="space-y-6">
            @if ($canManage)
                <form method="POST" action="{{ route('team.store') }}" class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                    @csrf
                    <div class="border-b border-slate-100 px-6 py-4">
                        <h2 class="font-semibold">Add a team member</h2>
                        <p class="text-sm text-slate-500">They get an email to set their password.</p>
                    </div>
                    <fieldset @disabled($full) class="space-y-4 px-6 py-5">
                        <div>
                            <label for="name" class="block text-sm font-medium">Full name</label>
                            <input id="name" name="name" value="{{ old('name') }}" required maxlength="120" class="{{ $input }}">
                            @error('name')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="email" class="block text-sm font-medium">Work email</label>
                            <input id="email" name="email" type="email" value="{{ old('email') }}" required maxlength="190" class="{{ $input }}">
                            @error('email')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="phone" class="block text-sm font-medium">Mobile <span class="font-normal text-slate-400">(optional)</span></label>
                            <input id="phone" name="phone" value="{{ old('phone') }}" inputmode="tel" class="{{ $input }}">
                            @error('phone')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                        @if (count($roles) > 1)
                            <fieldset>
                                <legend class="text-sm font-medium">Role</legend>
                                <div class="mt-2 space-y-2">
                                    @foreach ($roles as $r)
                                        <label class="flex cursor-pointer gap-3 rounded-lg border border-slate-200 p-3 hover:bg-slate-50 has-[:checked]:border-emerald-600 has-[:checked]:bg-emerald-50/50">
                                            <input type="radio" name="role" value="{{ $r->value }}" @checked(old('role', 'buyer_user') === $r->value) class="mt-0.5 accent-emerald-700">
                                            <span><span class="block text-sm font-medium">{{ $r->label() }}</span><span class="block text-xs text-slate-500">{{ $roleHelp[$r->value] ?? '' }}</span></span>
                                        </label>
                                    @endforeach
                                </div>
                                @error('role')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                            </fieldset>
                        @else
                            <input type="hidden" name="role" value="{{ $roles[0]->value }}">
                        @endif
                    </fieldset>
                    <div class="border-t border-slate-100 px-6 py-4">
                        @if ($full)
                            <p class="text-sm text-slate-600">All {{ $seats }} seats on your plan are in use. <a href="{{ route('buyer.billing.index') }}" class="font-medium text-emerald-700 hover:underline">Upgrade your plan</a> to add more people.</p>
                        @else
                            <button class="w-full rounded-lg bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800">Send invitation</button>
                        @endif
                    </div>
                </form>
            @else
                <div class="rounded-2xl border border-slate-200 bg-white p-6 text-sm text-slate-600 shadow-sm">
                    {{ $org->isBuyer() ? 'Only an Admin can add or remove team members.' : 'Only the company owner can add or remove team members.' }}
                </div>
            @endif

        </aside>
    </div>
@endsection
