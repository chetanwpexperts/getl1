@php $e = fn ($v) => \App\Support\Md::escape((string) $v); @endphp
<x-mail::message>
@if ($failing)
# Something needs attention

@foreach ($checks as $c)
@if ($c['status'] === 'fail')
- **{{ $e($c['label']) }}:** {{ $e($c['detail']) }}
@endif
@endforeach
@else
# All clear

Recovered: {{ $e(implode(', ', $recovered)) }}.
@endif

<x-mail::table>
| Check | Status | Detail |
|:--|:--|:--|
@foreach ($checks as $c)
| {{ $e($c['label']) }} | {{ strtoupper($c['status']) }} | {{ $e($c['detail']) }} |
@endforeach
</x-mail::table>

<x-mail::button :url="$url">
Open the Health page
</x-mail::button>

Checked {{ now()->ist()->format('d M Y, h:i A') }} IST.
</x-mail::message>
