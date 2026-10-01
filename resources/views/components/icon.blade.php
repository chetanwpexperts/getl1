@props(['name'])
<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" aria-hidden="true" {{ $attributes->merge(['class' => 'size-5 shrink-0']) }}>
    @foreach (\App\Support\Icons::PATHS[$name] ?? [] as $d)
        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $d }}" />
    @endforeach
</svg>
