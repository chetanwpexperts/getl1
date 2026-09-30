@extends('layouts.guest')

@section('title', 'Invitation')

@section('content')
    <h1 class="text-xl font-semibold">We couldn't open this invitation</h1>

    @switch($result['reason'])
        @case('not_supplier')
            <p class="mt-3 text-sm text-slate-600">This quote request is for suppliers, and you're signed in with a buyer account. Sign out and sign in (or register) as a supplier.</p>
            @break
        @case('contact_mismatch')
            <p class="mt-3 text-sm text-slate-600">
                This invitation was sent to a different contact{{ $result['hint'] ? ' ('.$result['hint'].')' : '' }}.
                Sign in with that email or mobile, or ask <span class="font-medium">{{ $rfq->organization->name }}</span> to update your details in their supplier list.
            </p>
            @break
        @case('other_supplier')
            <p class="mt-3 text-sm text-slate-600">This invitation belongs to another company. If you think this is a mistake, contact <span class="font-medium">{{ $rfq->organization->name }}</span>.</p>
            @break
        @case('duplicate')
            <p class="mt-3 text-sm text-slate-600">Your company already has an invitation for this request. Open it from your RFQs list.</p>
            @break
    @endswitch

    <div class="mt-6 flex flex-wrap gap-3 text-sm">
        @if ($result['reason'] === 'duplicate')
            <a href="{{ route('supplier.rfqs.index') }}" class="rounded-lg bg-emerald-700 px-4 py-2 font-semibold text-white hover:bg-emerald-800">My RFQs</a>
        @else
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="rounded-lg bg-emerald-700 px-4 py-2 font-semibold text-white hover:bg-emerald-800">Sign out</button>
            </form>
            <a href="{{ route('dashboard') }}" class="rounded-lg border border-slate-300 px-4 py-2 font-semibold hover:bg-slate-50">Go to dashboard</a>
        @endif
    </div>
@endsection
