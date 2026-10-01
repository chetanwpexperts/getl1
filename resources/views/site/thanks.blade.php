@extends('layouts.site')

@section('title', 'Thank you')

@section('content')
    <section class="mx-auto max-w-xl px-4 py-24 text-center">
        <div class="mx-auto flex size-14 items-center justify-center rounded-full bg-emerald-100 text-2xl text-emerald-700">✓</div>
        <h1 class="mt-6 text-3xl font-bold tracking-tight">Thank you, we've got it</h1>
        <p class="mt-4 text-lg text-slate-600">Someone from GetL1 will contact you within one working day. A confirmation is on its way to your email.</p>
        <a href="{{ route('home') }}" class="mt-8 inline-block font-semibold text-emerald-700 hover:underline">← Back to home</a>
    </section>
@endsection
