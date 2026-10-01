@extends('layouts.guest')

@section('title', 'Forgot password')

@section('content')
    <h1 class="text-2xl font-bold tracking-tight">Forgot your password?</h1>
    <p class="mt-1 text-sm text-slate-600">Enter the email you log in with. We'll send a link to choose a new password.</p>

    <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-4">
        @csrf
        <x-field name="email" label="Email" type="email" required autofocus autocomplete="email" />
        <x-button>Send reset link</x-button>
    </form>

    <p class="mt-6 text-center text-sm text-slate-600"><a href="{{ route('login') }}" class="font-medium text-emerald-700 hover:underline">← Back to log in</a></p>
@endsection
