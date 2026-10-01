@extends('layouts.admin')

@section('title', 'Leads')

@section('content')
    <h1 class="text-2xl font-semibold">Leads</h1>
    <p class="mt-1 text-sm text-slate-600">Early-access and demo requests from getl1.com.</p>
    <div class="mt-4 flex flex-wrap gap-2 text-sm">
        <a href="{{ route('admin.leads') }}" class="rounded-lg border px-3 py-1.5 {{ ! $status ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white' }}">All</a>
        @foreach (\App\Models\Lead::STATUSES as $k => $label)
            <a href="{{ route('admin.leads', ['status' => $k]) }}" class="rounded-lg border px-3 py-1.5 {{ $status === $k ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white' }}">{{ $label }} <span class="opacity-70">({{ $counts[$k] ?? 0 }})</span></a>
        @endforeach
    </div>

    <div class="mt-4 space-y-3">
        @forelse ($leads as $l)
            <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="font-semibold">{{ $l->company }} <span class="font-normal text-slate-500">· {{ $l->name }} · {{ $l->interest === 'supplier' ? 'Supplier' : 'Buyer' }}{{ $l->city ? ' · '.$l->city : '' }}</span></p>
                        <p class="mt-1 text-slate-600">
                            <a href="mailto:{{ $l->email }}" class="underline">{{ $l->email }}</a>
                            @if ($l->phone) · <a href="tel:{{ $l->phone }}" class="underline">{{ $l->phone }}</a>
                                · <a href="https://wa.me/{{ preg_replace('/\D/', '', strlen(preg_replace('/\D/', '', $l->phone)) === 10 ? '91'.$l->phone : $l->phone) }}" target="_blank" rel="noopener" class="text-emerald-700 underline">WhatsApp</a>@endif
                            @if ($l->monthly_spend) · spend {{ $l->monthly_spend }}@endif
                        </p>
                        @if ($l->message)<p class="mt-2 whitespace-pre-line text-slate-700">{{ $l->message }}</p>@endif
                        <p class="mt-2 text-xs text-slate-500">{{ $l->created_at->ist()->format('d M Y, h:i A') }}{{ $l->source ? ' · from '.$l->source : '' }}</p>
                    </div>
                    <form method="POST" action="{{ route('admin.leads.update', $l->id) }}" class="w-full space-y-2 sm:w-72">
                        @csrf
                        <select name="status" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                            @foreach (\App\Models\Lead::STATUSES as $k => $label)<option value="{{ $k }}" @selected($l->status === $k)>{{ $label }}</option>@endforeach
                        </select>
                        <textarea name="notes" rows="2" maxlength="2000" placeholder="Notes" class="w-full rounded-lg border border-slate-300 px-3 py-2">{{ $l->notes }}</textarea>
                        <button class="rounded-lg bg-slate-900 px-3 py-1.5 text-sm font-semibold text-white">Save</button>
                    </form>
                </div>
            </div>
        @empty
            <p class="rounded-xl border border-slate-200 bg-white p-8 text-center text-slate-500">No leads yet.</p>
        @endforelse
    </div>
    <div class="mt-4">{{ $leads->links() }}</div>
@endsection
