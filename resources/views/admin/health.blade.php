@extends('layouts.admin')

@section('title', 'System health')

@section('content')
    @php $dot = ['ok' => 'bg-emerald-500', 'warn' => 'bg-amber-500', 'fail' => 'bg-red-600']; $word = ['ok' => 'Healthy', 'warn' => 'Needs a look', 'fail' => 'Problem']; @endphp
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">System health</h1>
            <p class="mt-1 text-sm text-slate-600">Checked just now. An email alert goes to {{ $alertTo ?: 'nobody (set one in Platform rules)' }} when a check fails, at most once an hour per problem.</p>
        </div>
        <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-sm font-semibold ring-1 {{ ['ok' => 'bg-emerald-50 text-emerald-800 ring-emerald-200', 'warn' => 'bg-amber-50 text-amber-900 ring-amber-300', 'fail' => 'bg-red-50 text-red-700 ring-red-200'][$overall] }}">
            <span class="size-2 rounded-full {{ $dot[$overall] }}"></span>{{ $word[$overall] }}
        </span>
    </div>
    <div class="mt-6 divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
        @foreach ($checks as $c)
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                <div class="flex items-center gap-3">
                    <span class="size-2.5 shrink-0 rounded-full {{ $dot[$c['status']] }}" aria-hidden="true"></span>
                    <span class="font-medium">{{ $c['label'] }}</span>
                </div>
                <span class="text-sm {{ $c['status'] === 'ok' ? 'text-slate-600' : ($c['status'] === 'warn' ? 'text-amber-900' : 'font-medium text-red-700') }}">
                    <span class="sr-only">{{ $word[$c['status']] }}: </span>{{ $c['detail'] }}
                </span>
            </div>
        @endforeach
    </div>
    <p class="mt-4 text-xs text-slate-500">Environment {{ app()->environment() }} · PHP {{ PHP_VERSION }} · Laravel {{ app()->version() }} · queue {{ config('queue.default') }} · mail {{ config('mail.default') }}</p>
@endsection
