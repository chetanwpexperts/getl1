@extends('layouts.guest')

@section('title', 'Two-step login')

@section('content')
    <h1 class="text-xl font-semibold">Admin console</h1>
    @if (! $recovery)
        <p class="mt-1 text-sm text-slate-600">Enter the 6-digit code from your authenticator app.</p>
        <form method="POST" action="{{ route('admin.2fa.verify') }}" class="mt-5 space-y-3">
            @csrf
            <input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus aria-label="Code"
                   class="w-full rounded-lg border border-slate-300 px-3 py-3 text-center font-mono text-2xl tracking-[0.4em] focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20">
            @error('code') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            <button class="w-full rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">Continue</button>
        </form>
        <a href="{{ route('admin.2fa.challenge', ['recovery' => 1]) }}" class="mt-4 inline-block text-sm text-slate-600 underline">Lost your phone? Use a recovery code</a>
    @else
        <p class="mt-1 text-sm text-slate-600">Enter one of your recovery codes. Each works once.</p>
        <form method="POST" action="{{ route('admin.2fa.verify') }}" class="mt-5 space-y-3">
            @csrf
            <input name="recovery_code" autocomplete="off" maxlength="20" required autofocus aria-label="Recovery code"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-center font-mono focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20">
            @error('recovery_code') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            @error('code') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            <button class="w-full rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">Continue</button>
        </form>
        <a href="{{ route('admin.2fa.challenge') }}" class="mt-4 inline-block text-sm text-slate-600 underline">Use the app code instead</a>
    @endif
    <p class="mt-6 border-t border-slate-100 pt-4 text-xs text-slate-500"><a href="{{ route('dashboard') }}" class="underline">Back to the app</a></p>
@endsection
