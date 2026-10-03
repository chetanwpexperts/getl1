<x-mail::message>
# Delivery received: {{ $award->po_number }}

**{{ \App\Support\Md::escape($buyer?->name) }}** recorded a delivery against purchase order **{{ $award->po_number }}** on {{ $grn->received_on->format('d M Y') }} (receipt {{ $grn->grn_number }}).

<x-mail::table>
| Item | Received | Accepted | Rejected |
|:-----|---------:|---------:|---------:|
@foreach ($grn->lines as $l)
| {{ \App\Support\Md::escape($l['name']) }} | {{ rtrim(rtrim(number_format($l['received'], 3), '0'), '.') }} {{ $l['unit'] }} | {{ rtrim(rtrim(number_format($l['accepted'], 3), '0'), '.') }} | {{ rtrim(rtrim(number_format($l['rejected'], 3), '0'), '.') }}@if (! empty($l['reason'])) ({{ \App\Support\Md::escape($l['reason']) }})@endif |
@endforeach
</x-mail::table>

You can upload your invoice for the accepted quantity from the order page.

<x-mail::button :url="$url">
Open the order
</x-mail::button>

Thanks,<br>
GetL1
</x-mail::message>
