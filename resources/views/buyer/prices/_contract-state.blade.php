@php
    $st = $rc->state();
    [$label, $cls] = match ($st) {
        'active' => ['In force', 'bg-emerald-100 text-emerald-800'],
        'upcoming' => ['Starts '.$rc->valid_from->format('d M'), 'bg-sky-100 text-sky-900'],
        'expired' => ['Ended', 'bg-slate-100 text-slate-700'],
        default => ['Cancelled', 'bg-red-100 text-red-800'],
    };
@endphp
<span class="inline-block rounded-full px-2 py-0.5 text-xs font-medium {{ $cls }}">{{ $label }}</span>
