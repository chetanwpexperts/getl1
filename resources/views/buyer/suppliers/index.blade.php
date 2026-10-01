@extends('layouts.app')

@section('title', 'Suppliers')

@section('content')
    @php $canManage = in_array($currentRole?->value, ['buyer_admin', 'buyer_user'], true); @endphp

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Suppliers</h1>
            <p class="mt-1 text-sm text-slate-600">Your private supplier list. Other buyers can't see it.</p>
        </div>
        @if ($canManage)
            <div class="flex gap-2 text-sm">
                <a href="{{ route('buyer.suppliers.import') }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2 font-medium hover:bg-slate-50">Import from Excel</a>
                <a href="{{ route('buyer.suppliers.create') }}" class="rounded-lg bg-emerald-700 px-4 py-2 font-semibold text-white hover:bg-emerald-800">Add supplier</a>
            </div>
        @endif
    </div>

    @if (session('import_errors'))
        <div class="mt-5 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <p class="font-semibold">Rows that weren't imported</p>
            <ul class="mt-2 list-disc space-y-0.5 pl-5">
                @foreach (session('import_errors') as $err)<li>{{ $err }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form method="GET" class="mt-5 flex flex-wrap gap-2 text-sm">
        <input name="q" value="{{ $filters['q'] }}" placeholder="Search name, email or mobile" maxlength="100"
               class="min-w-56 flex-1 rounded-lg border border-slate-300 px-3 py-2">
        <select name="status" class="rounded-lg border border-slate-300 px-3 py-2">
            <option value="">All statuses</option>
            <option value="active" @selected($filters['status'] === 'active')>Active</option>
            <option value="blocked" @selected($filters['status'] === 'blocked')>Blocked</option>
        </select>
        @if ($tags->isNotEmpty())
            <select name="tag" class="rounded-lg border border-slate-300 px-3 py-2">
                <option value="">All tags</option>
                @foreach ($tags as $t)<option value="{{ $t }}" @selected($filters['tag'] === $t)>{{ $t }}</option>@endforeach
            </select>
        @endif
        <button class="rounded-lg border border-slate-300 bg-white px-4 py-2 hover:bg-slate-50">Filter</button>
    </form>

    <div class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full min-w-[640px] text-sm">
            <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                <tr><th class="px-5 py-3.5">Supplier</th><th class="px-5 py-3.5">Contact</th><th class="px-5 py-3.5">Tag</th><th class="px-5 py-3.5">On GetL1</th><th class="px-5 py-3.5"></th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($suppliers as $s)
                    <tr class="{{ $s->status === 'blocked' ? 'bg-slate-50 text-slate-500' : '' }}">
                        <td class="px-5 py-3.5">
                            <span class="font-medium">{{ $s->company_name }}</span>
                            @if ($s->status === 'blocked')<span class="ml-1 rounded bg-red-100 px-1.5 py-0.5 text-xs text-red-800">Blocked</span>@endif
                            @if ($s->contact_name)<span class="block text-xs text-slate-500">{{ $s->contact_name }}</span>@endif
                        </td>
                        <td class="px-5 py-3.5">
                            @if ($s->contact_phone)<span class="block">{{ $s->contact_phone }}</span>@endif
                            @if ($s->contact_email)<span class="block text-xs text-slate-500">{{ $s->contact_email }}</span>@endif
                        </td>
                        <td class="px-5 py-3.5">{{ $s->tag ?? '—' }}</td>
                        <td class="px-5 py-3.5">
                            @if ($s->supplier)
                                @if ($s->supplier->isVerified())
                                    <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-800">✓ Verified</span>
                                @else
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-700">Registered</span>
                                @endif
                            @else
                                <span class="text-xs text-slate-500">Not yet</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-5 py-3.5 text-right">
                            @if ($canManage)
                                <a href="{{ route('buyer.suppliers.edit', $s->id) }}" class="text-emerald-700 hover:underline">Edit</a>
                                <form method="POST" action="{{ route('buyer.suppliers.block', $s->id) }}" class="ml-3 inline">
                                    @csrf
                                    <button class="text-slate-600 hover:underline">{{ $s->status === 'blocked' ? 'Unblock' : 'Block' }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-slate-600">
                        @if (array_filter($filters)) No suppliers match your search. @else No suppliers yet. Add one, or import your list from Excel. @endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $suppliers->links() }}</div>
@endsection
