@props(['items' => [], 'current' => null])
{{-- Small trail above a page title: [[label, url], ...] then the current page. --}}
<nav {{ $attributes->merge(['class' => 'mb-3 flex flex-wrap items-center gap-1.5 text-sm text-slate-500']) }} aria-label="Breadcrumb">
    @foreach ($items as [$label, $url])
        <a href="{{ $url }}" class="hover:text-slate-900">{{ $label }}</a>
        <x-icon name="right" class="size-3.5 text-slate-400" />
    @endforeach
    @if ($current)<span class="text-slate-700">{{ $current }}</span>@endif
</nav>
