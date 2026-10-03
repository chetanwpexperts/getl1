<x-mail::message>
# Counter-offer {{ $accepted ? 'accepted' : 'declined' }}

**{{ \App\Support\Md::escape($supplier) }}** has {{ $accepted ? 'accepted' : 'declined' }} your counter-offer of **{{ \App\Support\Money::inr($offer->offered_amount) }}** (before GST) on **{{ \App\Support\Md::escape($rfq->title) }}** ({{ $rfq->ref_no }}).

@if ($offer->response_note)
**Their note:** {{ \App\Support\Md::escape($offer->response_note) }}

@endif
@if ($accepted)
Their price in the award list is now {{ \App\Support\Money::inr($offer->offered_amount) }}. Award when you're ready and the purchase order goes out at this price.
@else
Their price stays at {{ \App\Support\Money::inr($offer->current_amount) }}.
@endif

<x-mail::button :url="$url">
Open the RFQ
</x-mail::button>

Thanks,<br>
GetL1
</x-mail::message>
