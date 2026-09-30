@extends('layouts.app')

@section('title', 'Documents')

@section('content')
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">Business documents</h1>
            <p class="mt-1 text-sm text-slate-600">Upload your GST or Udyam certificate to get the verified badge. Only GetL1 staff can see these files, never buyers.</p>
        </div>
        @if ($org->isVerified())
            <span class="rounded-full bg-emerald-100 px-3 py-1 text-sm font-medium text-emerald-800">✓ Verified business</span>
        @else
            <span class="rounded-full bg-amber-100 px-3 py-1 text-sm font-medium text-amber-900">Not verified yet</span>
        @endif
    </div>

    <form method="POST" action="{{ route('supplier.documents.store') }}" enctype="multipart/form-data"
          class="mt-6 grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-[200px_1fr_auto] sm:items-end">
        @csrf
        <div>
            <label for="type" class="block text-sm font-medium text-slate-700">Document type</label>
            <select id="type" name="type" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2">
                @foreach ($types as $value => $label)
                    <option value="{{ $value }}" @selected(old('type', 'gst') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @error('type') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="file" class="block text-sm font-medium text-slate-700">File (PDF, JPG or PNG, max 5 MB)</label>
            <input id="file" name="file" type="file" accept=".pdf,.jpg,.jpeg,.png" required
                   class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm file:mr-3 file:rounded file:border-0 file:bg-slate-100 file:px-3 file:py-1.5">
            @error('file') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        <div><x-button class="sm:w-auto">Upload</x-button></div>
    </form>

    <div class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr><th class="px-4 py-3">Document</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Uploaded</th><th class="px-4 py-3"></th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($documents as $doc)
                    <tr>
                        <td class="px-4 py-3">
                            <span class="font-medium">{{ $types[$doc->type] ?? $doc->type }}</span>
                            <span class="block text-xs text-slate-500">{{ $doc->original_name }}</span>
                        </td>
                        <td class="px-4 py-3">
                            @switch($doc->status)
                                @case('verified') <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-800">Verified</span> @break
                                @case('rejected') <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-800">Rejected</span>
                                    @if ($doc->remarks)<span class="mt-1 block text-xs text-red-700">{{ $doc->remarks }}</span>@endif
                                    @break
                                @default <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-700">Under review</span>
                            @endswitch
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ $doc->created_at->format('d M Y') }}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <a href="{{ route('supplier.documents.download', $doc->id) }}" class="text-emerald-700 hover:underline">Download</a>
                            @if ($doc->status !== 'verified')
                                <form method="POST" action="{{ route('supplier.documents.destroy', $doc->id) }}" class="ml-3 inline"
                                      onsubmit="return confirm('Delete this document?')">
                                    @csrf @method('DELETE')
                                    <button class="text-red-700 hover:underline">Delete</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center text-slate-600">No documents yet. Start with your GST certificate.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p class="mt-3 text-xs text-slate-500">Up to {{ $maxDocs }} documents. Files are stored privately and every view is logged.</p>
@endsection
