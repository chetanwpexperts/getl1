@extends('layouts.guest')

@section('title', 'Set up two-step login')

@section('content')
    <h1 class="text-xl font-semibold">Set up two-step login</h1>
    <p class="mt-1 text-sm text-slate-600">The admin console needs a code from an authenticator app as well as your password. This is a one-time setup.</p>

    <ol class="mt-5 space-y-4 text-sm">
        <li>
            <p class="font-medium">1. Install an authenticator app</p>
            <p class="text-slate-600">Google Authenticator or Microsoft Authenticator on your phone.</p>
        </li>
        <li>
            <p class="font-medium">2. Scan this code in the app</p>
            <canvas data-qr="{{ $uri }}" class="mt-2 rounded-lg border border-slate-200" width="208" height="208" aria-label="QR code for your authenticator app"></canvas>
            <p class="mt-2 text-slate-600">Can't scan? Choose "Enter a setup key" and type:</p>
            <p class="mt-1 select-all break-all rounded-lg bg-slate-50 px-3 py-2 font-mono text-sm tracking-wider">{{ trim(chunk_split($secret, 4, ' ')) }}</p>
        </li>
        <li>
            <p class="font-medium">3. Enter the 6-digit code it shows</p>
            <form method="POST" action="{{ route('admin.2fa.confirm') }}" class="mt-2 flex gap-2">
                @csrf
                <input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus
                       class="w-36 rounded-lg border border-slate-300 px-3 py-2 text-center font-mono text-lg tracking-[0.3em] focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20">
                <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Turn on</button>
            </form>
            @error('code') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
        </li>
    </ol>
@endsection
