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
            <div class="mx-auto mt-5 max-w-sm rounded-lg border border-slate-200 bg-white p-4 text-left text-sm text-slate-600">
                <p class="font-semibold text-slate-800">For photos of a handwritten list</p>
                <ul class="mt-1 list-disc space-y-0.5 pl-5">
                    <li>Good light, no shadow on the page.</li>
                    <li>Phone straight above the page, whole list in the frame.</li>
                    <li>One item per line, with quantity and unit.</li>
                </ul>
                <p class="mt-2 text-xs text-slate-500">Failed or unclear reads are never counted against your AI reads.</p>
            </div>
            <a href="{{ route('buyer.rfqs.create') }}" class="mt-6 inline-block rounded-lg bg-emerald-700 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Back to New RFQ</a>
        </div>
    </div>
@endsection
