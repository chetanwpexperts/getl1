@props(['dark' => false, 'size' => 'text-2xl', 'height' => 'h-10'])
{{-- The site logo from Admin → Website, or the text logo when none is uploaded. --}}
@if (config('site.logo'))
    {{-- On dark backgrounds the uploaded logo sits on a white plate so a dark logo stays visible. --}}
    <img src="{{ \App\Services\WebsiteSettings::url(config('site.logo')) }}" alt="{{ config('site.name') }}" {{ $attributes->merge(['class' => $height.' w-auto'.($dark ? ' rounded-md bg-white px-2 py-1 box-content' : '')]) }}>
@else
    <span {{ $attributes->merge(['class' => $size.' font-bold tracking-tight '.($dark ? 'text-white' : '')]) }}>Get<span class="{{ $dark ? 'text-emerald-400' : 'text-emerald-700' }}">L1</span></span>
@endif
