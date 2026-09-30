@extends('layouts.guest')

@section('title', 'Quote request')

@section('content')
    <p class="text-sm font-medium text-emerald-700">Quote request</p>
    <h1 class="mt-1 text-xl font-semibold">{{ $rfq->title }}</h1>
    <p class="mt-1 text-sm text-slate-600">from <span class="font-medium text-slate-800">{{ $rfq->organization->name }}</span>, {{ $rfq->organization->city }}</p>

    <dl class="mt-5 space-y-2 rounded-lg bg-slate-50 p-4 text-sm">
        <div class="flex justify-between gap-4"><dt class="text-slate-500">Reference</dt><dd>{{ $rfq->ref_no }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-slate-500">Items</dt><dd>{{ $rfq->items()->count() }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-slate-500">Quote by</dt>
            <dd class="font-medium">{{ $rfq->isCancelled() ? 'Cancelled' : $rfq->quote_deadline?->ist()->format('d M Y, h:i A').' IST' }}</dd></div>
    </dl>

    @if ($rfq->isOpenForQuotes())
        <p class="mt-5 text-sm text-slate-600">Sign in or create a free supplier account to see the full requirement and submit a sealed quote. Use the email or mobile the buyer has for you.</p>
        <div class="mt-4 grid gap-3 sm:grid-cols-2">
            <a href="{{ route('login') }}" class="rounded-lg bg-emerald-700 px-4 py-2.5 text-center text-sm font-semibold text-white hover:bg-emerald-800">Sign in</a>
            <a href="{{ route('register', ['as' => 'supplier']) }}" class="rounded-lg border border-slate-300 px-4 py-2.5 text-center text-sm font-semibold hover:bg-slate-50">Create supplier account</a>
        </div>
        <p class="mt-4 text-xs text-slate-500">Suppliers use GetL1 free. Your prices are sealed: the buyer sees them only after the deadline, and other suppliers never do.</p>
    @else
        <p class="mt-5 text-sm text-slate-600">This request is no longer accepting quotes.</p>
    @endif
@endsection
