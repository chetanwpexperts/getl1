@php
    $tones = [
        'amber' => 'bg-amber-100 text-amber-900', 'sky' => 'bg-sky-100 text-sky-900', 'red' => 'bg-red-100 text-red-800',
        'slate' => 'bg-slate-100 text-slate-700', 'violet' => 'bg-violet-100 text-violet-900', 'emerald' => 'bg-emerald-100 text-emerald-800',
    ];
@endphp
<span class="inline-block rounded-full px-2 py-0.5 text-xs font-medium {{ $tones[$progress['tone']] ?? $tones['slate'] }}">{{ $progress['label'] }}</span>
