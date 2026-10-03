@extends('layouts.app')

@section('title', 'My profile')

@section('content')
    @php $input = 'mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20'; @endphp
    <x-page-header title="My profile" subtitle="Your own details and password. Company details are under Company profile." />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <form method="POST" action="{{ route('account.update') }}" class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                @csrf @method('PUT')
                <div class="border-b border-slate-100 px-6 py-4">
                    <h2 class="font-semibold">Personal details</h2>
                    <p class="text-sm text-slate-500">Shown to your team and on approvals you sign.</p>
                </div>
                <div class="grid gap-5 px-6 py-5 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="name" class="block text-sm font-medium">Full name</label>
                        <input id="name" name="name" value="{{ old('name', $user->name) }}" required maxlength="120" autocomplete="name" class="{{ $input }}">
                        @error('name')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="email" class="block text-sm font-medium">Email</label>
                        <input id="email" value="{{ $user->email }}" disabled class="{{ $input }} cursor-not-allowed bg-slate-50 text-slate-500">
                        <p class="mt-1 text-xs text-slate-500">Used to sign in. To change it, write to {{ config('site.email') }}.</p>
                    </div>
                    <div>
                        <label for="phone" class="block text-sm font-medium">Mobile number</label>
                        <input id="phone" name="phone" value="{{ old('phone', $user->phone) }}" required inputmode="tel" autocomplete="tel" class="{{ $input }}">
                        @error('phone')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="flex justify-end border-t border-slate-100 px-6 py-4">
                    <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Save details</button>
                </div>
            </form>

            <section id="notifications" class="scroll-mt-24 rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-6 py-4">
                    <h2 class="font-semibold">Notifications</h2>
                    <p class="text-sm text-slate-500">Every update appears in the bell and as a live pop-up while GetL1 is open. Device alerts also reach you when it's closed.</p>
                </div>
                <div class="border-b border-slate-100 px-6 py-5" data-push-settings data-vapid="{{ config('webpush.public_key') }}" data-devices="{{ route('notifications.devices.store') }}">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="text-sm font-medium">Alerts on this device</p>
                            <p class="text-sm text-slate-500" data-push-state>Checking…</p>
                        </div>
                        <div class="flex gap-2">
                            <button type="button" data-push-on hidden class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Turn on</button>
                            <button type="button" data-push-off hidden class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold hover:bg-slate-50">Turn off</button>
                        </div>
                    </div>
                    @if ($devices->isNotEmpty())
                        <p class="mt-3 text-xs text-slate-500">Turned on for: {{ $devices->map->deviceName()->unique()->implode(', ') }}.</p>
                    @endif
                </div>
                <form method="POST" action="{{ route('notifications.preferences') }}">
                    @csrf @method('PUT')
                    <fieldset class="px-6 py-5">
                        <legend class="text-sm font-medium">Send device alerts for</legend>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            @foreach (\App\Services\Notifier::CATEGORIES as $key => [$label, $help])
                                <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 text-sm hover:bg-slate-50">
                                    <input type="checkbox" name="push[{{ $key }}]" value="1" @checked(\App\Services\Notifier::wantsPush($user, $key)) class="mt-0.5 size-4 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600">
                                    <span><span class="block font-medium">{{ $label }}</span><span class="block text-xs text-slate-500">{{ $help }}</span></span>
                                </label>
                            @endforeach
                        </div>
                        <p class="mt-3 text-xs text-slate-500">The bell always keeps everything, whatever you choose here. Emails are sent as before.</p>
                        @if (\App\Services\Whatsapp::enabled())
                            <label class="mt-4 flex items-start gap-3 rounded-lg border border-slate-200 p-3 text-sm hover:bg-slate-50">
                                <input type="checkbox" name="whatsapp" value="1" @checked(data_get($user->notification_prefs, 'whatsapp', true)) class="mt-0.5 size-4 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600">
                                <span><span class="block font-medium">WhatsApp messages on {{ $user->phone ? '+91 '.$user->phone : 'your mobile' }}</span><span class="block text-xs text-slate-500">Only the important ones: new RFQ, auction starting, purchase order, approval needed, payment made.</span></span>
                            </label>
                        @endif
                    </fieldset>
                    <div class="flex justify-end border-t border-slate-100 px-6 py-4">
                        <button class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold hover:bg-slate-50">Save notification settings</button>
                    </div>
                </form>
            </section>

            <form method="POST" action="{{ route('account.password') }}" class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                @csrf @method('PUT')
                <div class="border-b border-slate-100 px-6 py-4">
                    <h2 class="font-semibold">Password</h2>
                    <p class="text-sm text-slate-500">Changing it signs you out on every other device.</p>
                </div>
                <div class="grid gap-5 px-6 py-5 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="current_password" class="block text-sm font-medium">Current password</label>
                        <input id="current_password" name="current_password" type="password" required autocomplete="current-password" class="{{ $input }} sm:max-w-sm">
                        @error('current_password', 'password')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="password" class="block text-sm font-medium">New password</label>
                        <input id="password" name="password" type="password" required minlength="8" autocomplete="new-password" class="{{ $input }}">
                        @error('password', 'password')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="password_confirmation" class="block text-sm font-medium">Repeat new password</label>
                        <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="{{ $input }}">
                    </div>
                </div>
                <div class="flex justify-end border-t border-slate-100 px-6 py-4">
                    <button class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold hover:bg-slate-50">Change password</button>
                </div>
            </form>
        </div>

        <aside class="space-y-6">
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex items-center gap-4">
                    <span class="flex size-12 items-center justify-center rounded-full bg-slate-900 text-base font-semibold text-white">
                        {{ collect(preg_split('/\s+/', trim($user->name)))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('') }}
                    </span>
                    <div class="min-w-0">
                        <p class="truncate font-semibold">{{ $user->name }}</p>
                        <p class="truncate text-sm text-slate-500">{{ $user->email }}</p>
                    </div>
                </div>
                @if ($user->last_login_at)
                    <p class="mt-4 text-xs text-slate-500">Last sign-in {{ $user->last_login_at->timezone('Asia/Kolkata')->format('d M Y, h:i A') }} IST</p>
                @endif
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-100 px-6 py-4 font-semibold">Your companies</h2>
                <ul class="divide-y divide-slate-100">
                    @forelse ($memberships as $org)
                        <li class="flex items-center justify-between gap-3 px-6 py-3 text-sm">
                            <span class="min-w-0">
                                <span class="block truncate font-medium">{{ $org->name }}</span>
                                <span class="block text-xs text-slate-500">{{ $org->type->label() }} · {{ \App\Enums\OrgRole::tryFrom((string) $org->pivot->role)?->label() }}</span>
                            </span>
                            @if ($org->id === $user->current_organization_id)
                                <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-800 ring-1 ring-emerald-200">Current</span>
                            @endif
                        </li>
                    @empty
                        <li class="px-6 py-4 text-sm text-slate-500">Not part of a company yet.</li>
                    @endforelse
                </ul>
            </div>
        </aside>
    </div>
@endsection
