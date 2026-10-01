@extends('layouts.admin')

@section('title', 'KYC review')

@section('content')
    <h1 class="text-2xl font-semibold">KYC review</h1>
    <p class="mt-1 text-sm text-slate-600">Approving a GST or Udyam certificate gives the supplier the verified badge. Every download and decision is audit-logged.</p>

    <div class="mt-5 flex gap-2 text-sm">
        @foreach (['pending' => 'Pending', 'verified' => 'Approved', 'rejected' => 'Rejected'] as $key => $label)
            <a href="{{ route('admin.kyc.index', ['status' => $key]) }}"
               class="rounded-lg border px-3 py-1.5 {{ $status === $key ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white hover:bg-slate-50' }}">
                {{ $label }} <span class="opacity-70">({{ $counts[$key] ?? 0 }})</span>
            </a>
        @endforeach
    </div>

    <div class="mt-5 space-y-3">
        @forelse ($documents as $doc)
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="text-sm">
                        <p class="font-semibold">{{ $doc->organization?->name ?? 'Deleted company' }}
                            <span class="ml-1 font-normal text-slate-500">· {{ $doc->organization?->city }}</span></p>
                        <p class="mt-1 text-slate-600">
                            {{ strtoupper($doc->type) }} · {{ $doc->original_name }} · uploaded {{ $doc->created_at->ist()->format('d M Y, h:i A') }}
                        </p>
                        <p class="mt-1 text-slate-600">
                            GSTIN on profile: <span class="font-mono">{{ $doc->organization?->gstin ?? '—' }}</span>
                            · PAN: <span class="font-mono">{{ $doc->organization?->pan ?? '—' }}</span>
                        </p>
                        @if ($doc->remarks)<p class="mt-1 text-red-700">Remarks: {{ $doc->remarks }}</p>@endif
                    </div>
                    <a href="{{ route('admin.kyc.download', $doc) }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">Download to check</a>
                </div>

                @if (in_array($doc->status, ['pending', 'verified'], true))
                    <div class="mt-4 flex flex-wrap items-start gap-3 border-t border-slate-100 pt-4">
                        @if ($doc->status === 'pending')
                            <form method="POST" action="{{ route('admin.kyc.approve', $doc) }}">
                                @csrf
                                <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Approve</button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('admin.kyc.reject', $doc) }}" class="flex flex-1 flex-wrap gap-2">
                            @csrf
                            <input name="remarks" required minlength="5" maxlength="255" placeholder="Reason shown to supplier, e.g. Name doesn't match GSTIN"
                                   class="min-w-64 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <button class="rounded-lg border border-red-300 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">Reject</button>
                        </form>
                    </div>
                @endif
            </div>
        @empty
            <p class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-600">Nothing here.</p>
        @endforelse
    </div>

    <div class="mt-6">{{ $documents->links() }}</div>
@endsection
