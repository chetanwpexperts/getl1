@extends('layouts.guest')

@section('title', 'Choose a new password')

@section('content')
    <h1 class="text-2xl font-bold tracking-tight">Choose a new password</h1>
    <p class="mt-1 text-sm text-slate-600">At least 8 characters.</p>

    <form method="POST" action="{{ route('password.update') }}" class="mt-6 space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-field name="email" label="Email" type="email" :value="$email" required autocomplete="email" />
        <x-field name="password" label="New password" type="password" required autofocus autocomplete="new-password" />
        <x-field name="password_confirmation" label="Repeat new password" type="password" required autocomplete="new-password" />
        <x-button>Change password</x-button>
    </form>
@endsection
