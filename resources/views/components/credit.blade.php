@props(['linkClass' => 'font-medium hover:underline'])
{{-- "Developed by ChetanBuilds" credit (Admin → Website → Footer). --}}
@if (config('site.credit_on') && config('site.credit_name'))
    <span {{ $attributes }}>{{ config('site.credit_text') }}
        @if (config('site.credit_url'))<a href="{{ config('site.credit_url') }}" target="_blank" rel="noopener" class="{{ $linkClass }}">{{ config('site.credit_name') }}</a>@else{{ config('site.credit_name') }}@endif</span>
@endif
