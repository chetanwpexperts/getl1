<x-mail::message>
# Your approval is needed

**{{ \App\Support\Md::escape($by?->name ?? 'A colleague') }}** wants to award **{{ \App\Support\Md::escape($rfq->title) }}** ({{ $rfq->ref_no }}) to **{{ \App\Support\Md::escape($supplier->name) }}**.

**Amount:** {{ \App\Support\Money::inr($award->total) }} before GST ({{ \App\Support\Money::inr($award->grand_total) }} total)<br>
**Rank:** L{{ $award->rank }}{{ $award->source === 'auction' ? ' after the live auction' : ' on sealed quotes' }}
@if ($award->reason)
<br>**Reason for not choosing L1:** {{ \App\Support\Md::escape($award->reason) }}
@endif

<x-mail::button :url="$url">
Review and approve
</x-mail::button>

The purchase order goes to the supplier automatically once you approve.

Thanks,<br>
GetL1
</x-mail::message>
