@props(['url', 'live', 'interval' => null])
{{-- Keeps the page current: refreshes when its data changes or a deadline passes. --}}
<span hidden data-live-page data-url="{{ $url }}" data-v="{{ $live['v'] }}"
      data-refresh-at="{{ $live['refresh_at'] ?? '' }}" data-server-time="{{ now()->getTimestampMs() }}" @if ($interval) data-interval="{{ $interval }}" @endif></span>
