@props(['label', 'value', 'icon', 'hint' => null, 'href' => null, 'tone' => 'slate'])
{{-- Dashboard number card: icon, label, big value, one line of context. --}}
@php
    $tones = ['slate' => 'bg-slate-100 text-slate-600', 'emerald' => 'bg-emerald-50 text-emerald-700', 'sky' => 'bg-sky-50 text-sky-700', 'amber' => 'bg-amber-50 text-amber-700', 'violet' => 'bg-violet-50 text-violet-700'];
    $tag = $href ? 'a' : 'div';
@endphp
<{{ $tag }} @if ($href) href="{{ $href }}" @endif class="group block rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition {{ $href ? 'hover:border-slate-300 hover:shadow' : '' }}">
    <div class="flex items-center justify-between gap-3">
        <p class="text-sm font-medium text-slate-500">{{ $label }}</p>
        <span class="flex size-9 items-center justify-center rounded-lg {{ $tones[$tone] ?? $tones['slate'] }}"><x-icon :name="$icon" class="size-5" /></span>
    </div>
    <p class="mt-3 text-3xl font-semibold tracking-tight tabular-nums text-slate-900">{{ $value }}</p>
    @if ($hint)<p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>@endif
</{{ $tag }}>
