@extends('layouts.admin')

@section('title', 'AI usage')

@section('content')
    <h1 class="text-2xl font-semibold">AI usage</h1>
    <p class="mt-1 text-sm text-slate-600">This month. Cost is our Anthropic cost in rupees (estimated from tokens).</p>
    <div class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-4">
        @include('admin._stat', ['label' => 'Successful reads', 'value' => number_format($month['done']), 'note' => $month['prepaid'].' from prepaid packs'])
        @include('admin._stat', ['label' => 'Failed / unclear', 'value' => number_format($month['failed']), 'note' => 'Not charged to customers'])
        @include('admin._stat', ['label' => 'Our cost', 'value' => \App\Support\Money::inr($month['cost']), 'note' => $month['done'] ? 'About '.\App\Support\Money::inr($month['cost'] / max(1, $month['done'] + $month['failed'])).' per read' : null])
        @include('admin._stat', ['label' => 'Tokens', 'value' => number_format($month['tokens_in'] + $month['tokens_out']), 'note' => number_format($month['tokens_in']).' in · '.number_format($month['tokens_out']).' out'])
    </div>

    <div class="mt-6 grid gap-4 lg:grid-cols-3">
        <section class="rounded-xl border border-slate-200 bg-white p-4 text-sm">
            <h2 class="font-semibold">Top companies this month</h2>
            <ul class="mt-2 divide-y divide-slate-100">
                @forelse ($byOrg as $r)
                    <li class="flex justify-between gap-2 py-2"><span class="truncate">{{ $r->organization?->name ?? '#'.$r->organization_id }}</span><span class="shrink-0 tabular-nums">{{ $r->n }} · {{ \App\Support\Money::inr((float) $r->cost) }}</span></li>
                @empty
                    <li class="py-4 text-slate-500">No AI reads this month.</li>
                @endforelse
            </ul>
        </section>
        <section class="overflow-x-auto rounded-xl border border-slate-200 bg-white lg:col-span-2">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr><th class="px-4 py-2.5">When</th><th class="px-4 py-2.5">Company</th><th class="px-4 py-2.5">Input</th><th class="px-4 py-2.5">Result</th><th class="px-4 py-2.5 text-right">Cost</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($jobs as $j)
                        <tr class="align-top">
                            <td class="whitespace-nowrap px-4 py-2 text-slate-600">{{ $j->created_at->ist()->format('d M, h:i A') }}</td>
                            <td class="px-4 py-2">{{ $j->organization?->name }}</td>
                            <td class="px-4 py-2 text-slate-600">{{ $j->input_file_path ? strtoupper(pathinfo($j->input_file_path, PATHINFO_EXTENSION)) : 'Text' }}{{ $j->paid_with_credit ? ' · prepaid' : '' }}</td>
                            <td class="px-4 py-2">
                                <span class="{{ $j->status === 'failed' ? 'text-red-700' : '' }}">{{ $j->status === 'done' ? count($j->output['fields']['items'] ?? []).' items' : ucfirst($j->status) }}</span>
                                @if ($j->status === 'failed')<span class="block text-xs text-slate-500">{{ \Illuminate\Support\Str::limit($j->error, 90) }}</span>@endif
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $j->cost_inr !== null ? \App\Support\Money::inr($j->cost_inr) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="p-3">{{ $jobs->links() }}</div>
        </section>
    </div>
@endsection
