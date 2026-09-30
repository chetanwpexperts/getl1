<x-mail::message>
# Auction closed

The live auction for **{{ \App\Support\Md::escape($rfq->title) }}** ({{ $rfq->ref_no }}) has ended.

**Final lowest price (L1):** {{ \App\Support\Money::inr($a->current_l1) }} before GST @if ($winner)from **{{ \App\Support\Md::escape($winner) }}** @endif
<br>**Best sealed quote:** {{ \App\Support\Money::inr($a->start_price) }}
<br>**Saved in the auction:** {{ \App\Support\Money::inr(max(0, $savings)) }}@if ($savingsPct !== null && $savingsPct > 0) ({{ number_format($savingsPct, 2) }}%)@endif
<br>**Live bids:** {{ $a->bid_count }}@if ($a->extensions_used), extended {{ $a->extensions_used }} {{ \Illuminate\Support\Str::plural('time', $a->extensions_used) }}@endif

<x-mail::button :url="$url">
View results
</x-mail::button>

Every bid is recorded with time, user and IP address in the auction record.

Thanks,<br>
GetL1
</x-mail::message>
