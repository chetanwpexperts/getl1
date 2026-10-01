{{-- GetL1: framework mail layout with the "Developed by" credit in the footer (Admin → Website → Footer). --}}
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
{{ config('app.name') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ config('app.name') }}. {{ __('All rights reserved.') }}
@if (config('site.credit_on') && config('site.credit_name'))
<br>{{ \App\Support\Md::escape((string) config('site.credit_text')) }} [{{ \App\Support\Md::escape((string) config('site.credit_name')) }}]({{ config('site.credit_url') }})
@endif
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
