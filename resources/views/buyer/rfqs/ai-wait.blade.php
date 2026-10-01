@extends('layouts.app')

@section('title', 'Reading your requirement')

@section('content')
    <div class="mx-auto max-w-lg py-16 text-center" data-ai-job data-status-url="{{ route('buyer.rfqs.ai.status', $job->id) }}">
        <div data-ai-working @if ($job->status === 'failed') hidden @endif>
            <div class="mx-auto size-12 animate-spin rounded-full border-4 border-emerald-200 border-t-emerald-700" aria-hidden="true"></div>
            <h1 class="mt-6 text-xl font-semibold">Reading your requirement…</h1>
            <p class="mt-2 text-sm text-slate-600">AI is picking out the items, quantities, dates and terms. This usually takes 10–30 seconds. The form opens on its own when it's ready.</p>
        </div>
        <div data-ai-failed @if ($job->status !== 'failed') hidden @endif>
            <h1 class="text-xl font-semibold">We couldn't read that</h1>
            <p class="mt-2 text-sm text-slate-600" data-ai-error>{{ $job->error }}</p>
            <a href="{{ route('buyer.rfqs.create') }}" class="mt-6 inline-block rounded-lg bg-emerald-700 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Back to New RFQ</a>
        </div>
    </div>
@endsection
