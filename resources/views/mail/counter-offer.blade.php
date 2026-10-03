<x-mail::message>
# Counter-offer: {{ \App\Support\Money::inr($offer->offered_amount) }}

**{{ \App\Support\Md::escape($buyer->name) }}** would like to place the order for **{{ \App\Support\Md::escape($rfq->title) }}** ({{ $rfq->ref_no }}) with you at a slightly lower price.

**Your current price:** {{ \App\Support\Money::inr($offer->current_amount) }} before GST
<br>**Counter-offer:** {{ \App\Support\Money::inr($offer->offered_amount) }} before GST ({{ number_format($offer->savingPct(), 2) }}% lower)
<br>**Valid until:** {{ $expires }}

@if ($offer->message)
<x-mail::panel>
{{ \App\Support\Md::escape($offer->message) }}
</x-mail::panel>
@endif

If you accept, this becomes your price for the award. If you decline or let it expire, your current price stays as it is.

<x-mail::button :url="$url">
Review the counter-offer
</x-mail::button>

Thanks,<br>
GetL1
</x-mail::message>
