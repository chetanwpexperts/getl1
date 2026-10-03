<nav class="mb-6 flex print:hidden flex-wrap gap-1 border-b border-slate-200 text-sm" aria-label="Prices">
    @foreach (['buyer.prices.index' => ['Price history', 'buyer.prices.*'], 'buyer.contracts.index' => ['Rate contracts', 'buyer.contracts.*'], 'buyer.reports.savings' => ['Savings report', 'buyer.reports.*']] as $route => [$label, $pattern])
        <a href="{{ route($route) }}" class="-mb-px border-b-2 px-4 py-2.5 font-medium {{ request()->routeIs($pattern) ? 'border-emerald-700 text-emerald-800' : 'border-transparent text-slate-500 hover:text-slate-800' }}">{{ $label }}</a>
    @endforeach
</nav>
