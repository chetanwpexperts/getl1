@extends('layouts.guest')

@section('title', 'Recovery codes')

@section('content')
    <h1 class="text-xl font-semibold">Two-step login is on</h1>
    <p class="mt-1 text-sm text-slate-600">Save these recovery codes somewhere safe, such as a password manager. If you lose your phone, each code lets you in once. <strong>They are shown only now.</strong></p>
    <ul class="mt-4 grid grid-cols-2 gap-2 rounded-lg bg-slate-50 p-4 font-mono text-sm">
        @foreach ($codes as $c)<li class="select-all">{{ $c }}</li>@endforeach
    </ul>
    <a href="{{ route('admin.dashboard') }}" class="mt-6 inline-block rounded-lg bg-slate-900 px-5 py-2 text-sm font-semibold text-white hover:bg-slate-800">I've saved them, continue</a>
@endsection
