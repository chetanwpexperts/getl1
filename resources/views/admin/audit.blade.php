@extends('layouts.admin')

@section('title', 'Audit log')

@section('content')
    <h1 class="text-2xl font-semibold">Audit log</h1>
    <p class="mt-1 text-sm text-slate-600">Every important action, append-only. Filter by action (e.g. <span class="font-mono">admin_</span>, <span class="font-mono">bid</span>, <span class="font-mono">payment</span>).</p>
    <form method="GET" class="mt-4 flex flex-wrap gap-2">
        <input name="action" value="{{ request('action') }}" placeholder="Action starts with…" class="w-60 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
        @if (request('org'))<input type="hidden" name="org" value="{{ request('org') }}">@endif
        <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Filter</button>
        @if (request('org'))<a href="{{ route('admin.audit') }}" class="self-center text-sm text-slate-600 underline">All companies</a>@endif
    </form>
    <div class="mt-4 rounded-xl border border-slate-200 bg-white px-4 py-2">
        @include('admin._audit-rows', ['logs' => $logs])
    </div>
    <div class="mt-4">{{ $logs->links() }}</div>
@endsection
