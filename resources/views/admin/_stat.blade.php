<div class="rounded-xl border border-slate-200 bg-white p-4 {{ ($warn ?? false) ? 'ring-1 ring-amber-300' : '' }}">
    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</p>
    <p class="mt-1 text-2xl font-semibold tabular-nums">{{ $value }}</p>
    @isset($note)<p class="mt-0.5 text-xs text-slate-500">{{ $note }}</p>@endisset
</div>
