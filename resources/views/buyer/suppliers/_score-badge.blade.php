@php
    $cls = match (true) {
        $score >= 85 => 'bg-emerald-100 text-emerald-800',
        $score >= 70 => 'bg-sky-100 text-sky-900',
        $score >= 50 => 'bg-amber-100 text-amber-900',
        default => 'bg-red-100 text-red-800',
    };
@endphp
<span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums {{ $cls }}" title="Supplier score from your last 12 months of orders">{{ $score }}<span class="font-medium">· {{ $grade }}</span></span>
