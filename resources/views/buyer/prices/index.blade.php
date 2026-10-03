@extends('layouts.app')

@section('title', 'Price history')

@section('content')
    @php $inr = fn ($v) => \App\Support\Money::inr($v); @endphp
    <x-page-header title="Prices" subtitle="What you have paid for every item, from each purchase order, and the rates agreed with suppliers." />
    @include('buyer.prices._tabs')

    <form method="GET" class="mb-4 flex max-w-lg gap-2">
        <label for="q" class="sr-only">Search items</label>
        <input id="q" name="q" value="{{ $search }}" maxlength="80" placeholder="Search an item, e.g. corrugated box"
               class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20">
        <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Search</button>
        @if ($search)<a href="{{ route('buyer.prices.index') }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50">Clear</a>@endif
    </form>

    <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full min-w-[820px] text-sm">
            <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                <tr>
                    <th class="px-5 py-3.5">Item</th><th class="px-5 py-3.5 text-right">Last rate</th><th class="px-5 py-3.5 text-right">Change</th>
                    <th class="px-5 py-3.5 text-right">Lowest paid</th><th class="px-5 py-3.5">Last bought</th><th class="px-5 py-3.5 text-right">Orders</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($items as $row)
                    <tr class="hover:bg-slate-50">
                        <td class="px-5 py-3.5">
                            <a href="{{ route('buyer.prices.item', ['key' => $row['key']]) }}" class="font-medium hover:underline">{{ $row['name'] }}</a>
                            <span class="block text-xs text-slate-500">per {{ $row['unit'] }}{{ $row['spec'] ? ' · '.\Illuminate\Support\Str::limit($row['spec'], 80) : '' }}</span>
                        </td>
                        <td class="px-5 py-3.5 text-right font-medium tabular-nums">{{ $inr($row['last']->rate) }}</td>
                        <td class="px-5 py-3.5 text-right tabular-nums">
                            @if ($row['change_pct'] === null)
                                <span class="text-slate-400">—</span>
                            @elseif ($row['change_pct'] > 0)
                                <span class="text-red-700">▲ {{ rtrim(rtrim(number_format($row['change_pct'], 1), '0'), '.') }}%</span>
                            @elseif ($row['change_pct'] < 0)
                                <span class="text-emerald-700">▼ {{ rtrim(rtrim(number_format(abs($row['change_pct']), 1), '0'), '.') }}%</span>
                            @else
                                <span class="text-slate-500">Same</span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-right tabular-nums text-slate-600">{{ $inr($row['lowest']) }}</td>
                        <td class="px-5 py-3.5">{{ $row['last']->priced_on->format('d M Y') }}<span class="block text-xs text-slate-500">{{ $row['last']->supplier?->name }}</span></td>
                        <td class="px-5 py-3.5 text-right tabular-nums">{{ $row['count'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-12 text-center text-slate-600">
                        {{ $search ? 'No items match "'.$search.'".' : 'No purchases yet. Every purchase order you issue adds its item rates here automatically.' }}
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $items->links() }}</div>
    <p class="mt-4 text-xs text-slate-500">Rates are per unit, before GST. The same item is matched by its name, specification and unit across RFQs. When you create an RFQ, the last rate is filled in for you.</p>
@endsection
