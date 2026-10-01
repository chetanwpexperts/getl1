@extends('layouts.guest')

@section('title', 'Log in')

@section('content')
    <h1 class="text-2xl font-bold tracking-tight">Log in to GetL1</h1>
    <p class="mt-1 text-sm text-slate-600">Welcome back.</p>

    <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4">
        @csrf
        <x-field name="email" label="Email" type="email" required autofocus autocomplete="email" />
        <x-field name="password" label="Password" type="password" required autocomplete="current-password" />

        <div class="flex items-center justify-between">
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="remember" class="rounded border-slate-300"> Remember me
            </label>
            <a href="{{ route('password.request') }}" class="text-sm font-medium text-emerald-700 hover:underline">Forgot password?</a>
        </div>

        <x-button>Log in</x-button>
    </form>

    <p class="mt-6 text-center text-sm text-slate-600">
        New to GetL1?
        <a href="{{ route('register') }}" class="font-medium text-emerald-700 hover:underline">Create an account</a>
    </p>
@endsection
