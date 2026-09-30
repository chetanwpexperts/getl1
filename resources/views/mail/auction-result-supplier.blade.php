<x-mail::message>
# You finished L{{ $rank }} of {{ $participants }}

The live auction by **{{ \App\Support\Md::escape($buyer->name) }}** for **{{ \App\Support\Md::escape($rfq->title) }}** ({{ $rfq->ref_no }}) has ended.

**Your final price:** {{ \App\Support\Money::inr($amount) }} before GST
@if ($l1 !== null && $rank > 1)
<br>**Lowest price:** {{ \App\Support\Money::inr($l1) }}
@endif

The buyer will now review the results and award the order. You'll hear from them directly.

<x-mail::button :url="$url">
View your result
</x-mail::button>

Thanks,<br>
GetL1
</x-mail::message>
