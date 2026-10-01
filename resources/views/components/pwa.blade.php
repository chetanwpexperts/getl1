{{-- Installable app: lets the browser offer "Install GetL1". Used on the login, app and admin pages. --}}
<link rel="manifest" href="{{ url('/manifest.webmanifest') }}" crossorigin="use-credentials">
<meta name="theme-color" content="#047857">
<link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ config('site.name') }}">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
