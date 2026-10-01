@extends('layouts.guest')

@section('title', 'Join your team')

@section('content')
    <h1 class="text-2xl font-bold tracking-tight">Welcome, {{ \Illuminate\Support\Str::before($user->name, ' ') }}</h1>
    <p class="mt-1 text-sm text-slate-600">
        @if ($org)You're joining <strong class="text-slate-900">{{ $org->name }}</strong> on {{ config('site.name') }}. @endif
        Choose a password to finish. At least 8 characters.
    </p>

    <form method="POST" action="{{ $action }}" class="mt-6 space-y-4">
        @csrf
        <x-field name="email_shown" label="Email" type="email" :value="$user->email" disabled />
        <x-field name="password" label="Password" type="password" required autofocus autocomplete="new-password" />
        <x-field name="password_confirmation" label="Repeat password" type="password" required autocomplete="new-password" />
        <x-button>Set password and continue</x-button>
    </form>
@endsection
