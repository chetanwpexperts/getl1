@extends('layouts.admin')

@section('title', 'Companies')

@section('content')
    <h1 class="text-2xl font-semibold">Companies</h1>
    <form method="GET" class="mt-4 flex flex-wrap gap-2">
        <input name="q" value="{{ $q }}" placeholder="Name, GSTIN, email, phone or city" class="w-72 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
        <select name="type" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
            <option value="">Buyers and suppliers</option>
            <option value="buyer" @selected($type === 'buyer')>Buyers</option>
            <option value="supplier" @selected($type === 'supplier')>Suppliers</option>
        </select>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="status" value="suspended" @checked(request('status') === 'suspended')> Suspended only</label>
        <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Search</button>
    </form>

    <div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr><th class="px-4 py-2.5">Company</th><th class="px-4 py-2.5">Type</th><th class="px-4 py-2.5">Plan / trust</th><th class="px-4 py-2.5">Users</th><th class="px-4 py-2.5">KYC</th><th class="px-4 py-2.5">Joined</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($orgs as $o)
                    @php $sub = $plansByOrg[$o->id] ?? null; @endphp
                    <tr class="{{ $o->status === 'suspended' ? 'bg-red-50/50' : '' }}">
                        <td class="px-4 py-2.5">
                            <a href="{{ route('admin.companies.show', $o->id) }}" class="font-medium hover:underline">{{ $o->name }}</a>
                            @if ($o->status === 'suspended')<span class="ml-1 rounded bg-red-100 px-1.5 text-xs text-red-700">Suspended</span>@endif
                            <span class="block text-xs text-slate-500">{{ collect([$o->city, $o->gstin])->filter()->implode(' · ') }}</span>
                        </td>
                        <td class="px-4 py-2.5">{{ $o->type->label() }}</td>
                        <td class="px-4 py-2.5">
                            @if ($o->isBuyer())
                                {{ $sub ? ($sub->status->value === 'trialing' ? 'Trial to '.$sub->trial_ends_at->ist()->format('d M') : $sub->plan?->name) : 'Free' }}
                            @else
                                @php $tr = $trustByOrg[$o->id] ?? null; @endphp
                                <span class="text-slate-600">Trust: {{ $tr['score'] !== null ? $tr['score'].' · '.$tr['label'] : 'New' }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5 tabular-nums">{{ $o->users_count }}</td>
                        <td class="px-4 py-2.5">{{ $o->verified_at ? 'Verified' : '—' }}</td>
                        <td class="px-4 py-2.5 text-slate-600">{{ $o->created_at->ist()->format('d M Y') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">No companies found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $orgs->links() }}</div>
@endsection
