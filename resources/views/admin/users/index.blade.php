@extends('layouts.admin')

@section('title', 'Users')

@section('content')
    <h1 class="text-2xl font-semibold">Users</h1>
    <p class="mt-1 text-sm text-slate-600">Everyone who can log in, across all companies.</p>
    <form method="GET" class="mt-4 flex flex-wrap gap-2">
        <input name="q" value="{{ $q }}" placeholder="Name, email or phone" class="w-72 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
        <select name="filter" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
            <option value="">Everyone</option>
            <option value="locked" @selected($filter === 'locked')>Locked</option>
            <option value="staff" @selected($filter === 'staff')>GetL1 staff</option>
        </select>
        <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Search</button>
    </form>
    <div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-2.5">Person</th><th class="px-4 py-2.5">Companies</th><th class="px-4 py-2.5">Last login</th><th class="px-4 py-2.5">Status</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($users as $u)
                    <tr class="{{ $u->locked_at ? 'bg-red-50/50' : '' }}">
                        <td class="px-4 py-2.5"><a href="{{ route('admin.users.show', $u->id) }}" class="font-medium hover:underline">{{ $u->name }}</a><span class="block text-xs text-slate-500">{{ $u->email }}{{ $u->phone ? ' · '.$u->phone : '' }}</span></td>
                        <td class="px-4 py-2.5 text-slate-600">{{ $u->organizations->pluck('name')->implode(', ') ?: '—' }}</td>
                        <td class="px-4 py-2.5 text-slate-600">{{ $u->last_login_at?->ist()->format('d M Y, h:i A') ?? 'Never' }}</td>
                        <td class="px-4 py-2.5">
                            @if ($u->locked_at)<span class="rounded bg-red-100 px-1.5 py-0.5 text-xs font-semibold text-red-700">Locked</span>@endif
                            @if ($u->is_platform_admin)<span class="rounded bg-slate-900 px-1.5 py-0.5 text-xs font-semibold text-white">Staff{{ $u->two_factor_confirmed_at ? '' : ' · no 2-step' }}</span>@endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">No users found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $users->links() }}</div>
@endsection
