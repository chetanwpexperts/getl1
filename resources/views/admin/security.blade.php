@extends('layouts.admin')

@section('title', 'Security log')

@section('content')
    <h1 class="text-2xl font-semibold">Security log</h1>
    <p class="mt-1 text-sm text-slate-600">Failed logins, lockouts, denied access, rejected uploads, bad payment signatures and admin two-step events. Kept 90 days.</p>
    @if ($files->isNotEmpty())
        <form method="GET" class="mt-4 flex gap-2">
            <select name="file" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" aria-label="Day">
                @foreach ($files as $f)<option value="{{ $f }}" @selected($f === $file)>{{ str_replace(['security-', '.log'], '', $f) }}</option>@endforeach
            </select>
            <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Show</button>
        </form>
    @endif
    <div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr><th class="px-4 py-2.5">Time (server)</th><th class="px-4 py-2.5">Event</th><th class="px-4 py-2.5">User</th><th class="px-4 py-2.5">IP</th><th class="px-4 py-2.5">Path</th><th class="px-4 py-2.5">Details</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($lines as $l)
                    @php $c = $l['context']; $extra = \Illuminate\Support\Arr::except($c, ['user_id', 'ip', 'method', 'path', 'ua']); @endphp
                    <tr class="align-top {{ $l['level'] === 'warning' ? 'bg-amber-50/40' : '' }}">
                        <td class="whitespace-nowrap px-4 py-2 text-slate-600">{{ $l['time'] }}</td>
                        <td class="px-4 py-2 font-medium {{ $l['level'] === 'warning' ? 'text-amber-800' : '' }}">{{ $l['event'] }}</td>
                        <td class="px-4 py-2">{{ $c['user_id'] ?? '—' }}</td>
                        <td class="px-4 py-2 font-mono text-xs">{{ $c['ip'] ?? '' }}</td>
                        <td class="px-4 py-2 font-mono text-xs">{{ ($c['method'] ?? '').' '.($c['path'] ?? '') }}</td>
                        <td class="max-w-sm break-all px-4 py-2 font-mono text-xs text-slate-500">{{ $extra ? json_encode($extra, JSON_UNESCAPED_SLASHES) : '' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">No security events logged.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
