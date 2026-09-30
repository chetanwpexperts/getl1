@props(['status'])
@php
    $map = [
        'draft' => ['Draft', 'bg-slate-100 text-slate-700'],
        'open' => ['Open for quotes', 'bg-emerald-100 text-emerald-800'],
        'closed' => ['Quotes closed', 'bg-sky-100 text-sky-800'],
        'cancelled' => ['Cancelled', 'bg-red-100 text-red-800'],
        'invited' => ['Invited', 'bg-slate-100 text-slate-700'],
        'accepted' => ['Accepted', 'bg-sky-100 text-sky-800'],
        'quoted' => ['Quoted', 'bg-emerald-100 text-emerald-800'],
        'declined' => ['Declined', 'bg-red-100 text-red-800'],
    ];
    [$label, $classes] = $map[$status] ?? [ucfirst($status), 'bg-slate-100 text-slate-700'];
@endphp
<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {$classes}"]) }}>{{ $label }}</span>
