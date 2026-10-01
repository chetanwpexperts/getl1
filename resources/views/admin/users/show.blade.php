@extends('layouts.admin')

@section('title', $u->name)

@section('content')
    @php
        $input = 'w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm';
        $self = auth()->id() === $u->id;
    @endphp
    <a href="{{ route('admin.users.index') }}" class="text-sm text-slate-600 hover:text-slate-900">← Users</a>
    <h1 class="mt-2 text-2xl font-semibold">{{ $u->name }}
        @if ($u->locked_at)<span class="ml-2 rounded bg-red-100 px-2 py-0.5 align-middle text-sm text-red-700">Locked</span>@endif
        @if ($u->is_platform_admin)<span class="ml-2 rounded bg-slate-900 px-2 py-0.5 align-middle text-sm text-white">GetL1 staff</span>@endif
    </h1>
    <p class="mt-1 text-sm text-slate-600">{{ $u->email }}{{ $u->phone ? ' · '.$u->phone : '' }} · joined {{ $u->created_at->ist()->format('d M Y') }}
        · last login {{ $u->last_login_at?->ist()->format('d M Y, h:i A') ?? 'never' }}{{ $sessions !== null ? ' · '.$sessions.' active '.\Illuminate\Support\Str::plural('session', $sessions) : '' }}</p>
    @if ($u->locked_at)<p class="mt-3 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">Locked {{ $u->locked_at->ist()->format('d M Y, h:i A') }}: {{ $u->locked_reason }}</p>@endif

    <div class="mt-6 grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <section class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
                <h2 class="font-semibold">Companies</h2>
                <ul class="mt-2 divide-y divide-slate-100">
                    @forelse ($u->organizations as $o)
                        <li class="flex justify-between gap-2 py-2"><a href="{{ route('admin.companies.show', $o->id) }}" class="hover:underline">{{ $o->name }}</a><span class="text-slate-500">{{ \App\Enums\OrgRole::from($o->pivot->role)->label() }}{{ $o->pivot->is_owner ? ' · owner' : '' }}</span></li>
                    @empty
                        <li class="py-2 text-slate-500">Not in any company yet.</li>
                    @endforelse
                </ul>
            </section>
            <section class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
                <h2 class="font-semibold">Sign-ins</h2>
                <ul class="mt-2 space-y-1.5">
                    @forelse ($logins as $l)
                        <li class="flex flex-wrap justify-between gap-2"><span>{{ ucfirst(str_replace('_', ' ', $l->action)) }}</span><span class="font-mono text-xs text-slate-500">{{ $l->created_at->ist()->format('d M, h:i A') }} · {{ $l->ip }}</span></li>
                    @empty
                        <li class="text-slate-500">No sign-ins recorded.</li>
                    @endforelse
                </ul>
                <h3 class="mt-5 font-semibold">Failed attempts (7 days)</h3>
                <ul class="mt-2 space-y-1.5">
                    @forelse ($failed as $f)
                        <li class="flex flex-wrap justify-between gap-2 text-amber-900"><span>{{ str_replace('_', ' ', $f['event']) }}</span><span class="font-mono text-xs">{{ $f['time'] }} · {{ $f['context']['ip'] ?? '' }}</span></li>
                    @empty
                        <li class="text-slate-500">None.</li>
                    @endforelse
                </ul>
            </section>
            <section class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
                <h2 class="font-semibold">Recent activity</h2>
                @include('admin._audit-rows', ['logs' => $activity])
            </section>
        </div>

        <div class="space-y-4">
            @if ($self)
                <p class="rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-600">This is your own account. Another staff member has to make changes to it.</p>
            @else
                <section class="rounded-xl border border-slate-200 bg-white p-4 text-sm">
                    <h2 class="font-semibold">Sign out everywhere</h2>
                    <p class="mt-1 text-xs text-slate-500">Ends every session on every device. They can log in again.</p>
                    <form method="POST" action="{{ route('admin.users.signout', $u->id) }}" class="mt-2 space-y-2">
                        @csrf
                        <input name="reason" required minlength="5" maxlength="200" placeholder="Reason" class="{{ $input }}" aria-label="Reason">
                        <button class="w-full rounded-lg border border-slate-300 px-3 py-2 font-semibold hover:bg-slate-50">Sign out</button>
                    </form>
                </section>
                <section class="rounded-xl border border-slate-200 bg-white p-4 text-sm">
                    @if ($u->locked_at)
                        <h2 class="font-semibold">Unlock</h2>
                        <form method="POST" action="{{ route('admin.users.unlock', $u->id) }}" class="mt-2 space-y-2">
                            @csrf
                            <input name="reason" required minlength="5" maxlength="200" placeholder="Reason" class="{{ $input }}" aria-label="Reason">
                            <button class="w-full rounded-lg bg-slate-900 px-3 py-2 font-semibold text-white">Unlock</button>
                        </form>
                    @else
                        <h2 class="font-semibold text-red-700">Lock this person</h2>
                        <p class="mt-1 text-xs text-slate-500">Signs them out everywhere and blocks login. Their company and records stay as they are.</p>
                        <form method="POST" action="{{ route('admin.users.lock', $u->id) }}" class="mt-2 space-y-2" data-confirm="Lock {{ $u->name }}?">
                            @csrf
                            <input name="reason" required minlength="5" maxlength="200" placeholder="Reason" class="{{ $input }}" aria-label="Reason">
                            <button class="w-full rounded-lg border border-red-300 px-3 py-2 font-semibold text-red-700 hover:bg-red-50">Lock</button>
                        </form>
                    @endif
                </section>
                @if ($u->is_platform_admin && $u->two_factor_confirmed_at)
                    <section class="rounded-xl border border-slate-200 bg-white p-4 text-sm">
                        <h2 class="font-semibold">Reset two-step login</h2>
                        <p class="mt-1 text-xs text-slate-500">If they lost their phone and recovery codes. They set it up again at their next visit.</p>
                        <form method="POST" action="{{ route('admin.users.2fa-reset', $u->id) }}" class="mt-2 space-y-2" data-confirm="Reset two-step login for {{ $u->name }}?">
                            @csrf
                            <input name="reason" required minlength="5" maxlength="200" placeholder="Reason" class="{{ $input }}" aria-label="Reason">
                            <button class="w-full rounded-lg border border-slate-300 px-3 py-2 font-semibold hover:bg-slate-50">Reset</button>
                        </form>
                    </section>
                @endif
            @endif
        </div>
    </div>
@endsection
