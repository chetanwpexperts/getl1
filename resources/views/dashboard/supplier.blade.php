@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <h1 class="text-2xl font-semibold">Dashboard</h1>
    <p class="mt-1 text-sm text-slate-600">{{ $currentOrg->name }}, {{ $currentOrg->city }}</p>

    @unless ($verified)
        <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
            <p class="font-semibold">Get your verified badge</p>
            <p class="mt-1">Verified suppliers get invited more often. <a href="{{ route('supplier.documents.index') }}" class="font-medium underline">Upload your GST or Udyam certificate</a> and fill in your <a href="{{ route('company.edit') }}" class="font-medium underline">company profile</a>.</p>
        </div>
    @endunless

    <div class="mt-6 grid gap-4 sm:grid-cols-2">
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <p class="text-sm text-slate-600">New invitations</p>
            <p class="mt-1 text-3xl font-semibold tabular-nums">{{ $pendingInvites->count() }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <p class="text-sm text-slate-600">RFQs you're participating in</p>
            <p class="mt-1 text-3xl font-semibold tabular-nums">{{ $acceptedCount }}</p>
        </div>
    </div>

    <div class="mt-8 rounded-xl border border-slate-200 bg-white">
        <h2 class="border-b border-slate-200 px-5 py-3 font-semibold">Invitations</h2>
        @forelse ($pendingInvites as $invite)
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3 text-sm last:border-0">
                <span>
                    <span class="font-medium">{{ $invite->rfq->title }}</span>
                    <span class="block text-slate-500">from {{ $invite->rfq->organization->name }}</span>
                </span>
                <span class="text-xs text-slate-500">{{ $invite->created_at->diffForHumans() }}</span>
            </div>
        @empty
            <p class="px-5 py-6 text-sm text-slate-600">No invitations yet. Buyers will invite you by WhatsApp or email.</p>
        @endforelse
    </div>
@endsection
