@if (config('site.favicon'))
    <link rel="icon" href="{{ \App\Services\WebsiteSettings::url(config('site.favicon')) }}" type="image/png">
@else
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
@endif
