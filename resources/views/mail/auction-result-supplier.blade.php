<x-mail::message>
@if ($items)
# You are L1 on {{ $items->where('rank', 1)->count() }} of {{ $items->count() }} items

The item-by-item auction by **{{ \App\Support\Md::escape($buyer->name) }}** for **{{ \App\Support\Md::escape($rfq->title) }}** ({{ $rfq->ref_no }}) has ended.

<x-mail::table>
| Item | Your rank | Your rate (before GST) |
|:-----|:---------:|-----------------------:|
@foreach ($items as $it)
| {{ \App\Support\Md::escape($it['name']) }} | L{{ $it['rank'] }} | {{ \App\Support\Money::inr($it['rate']) }}/{{ $it['unit'] }}@if ($it['l1'] !== null && $it['rank'] > 1) (L1 {{ \App\Support\Money::inr($it['l1']) }})@endif |
@endforeach
</x-mail::table>

The buyer can award each item separately. You'll hear from them directly.
@else
# You finished L{{ $rank }} of {{ $participants }}

The live auction by **{{ \App\Support\Md::escape($buyer->name) }}** for **{{ \App\Support\Md::escape($rfq->title) }}** ({{ $rfq->ref_no }}) has ended.

**Your final price:** {{ \App\Support\Money::inr($amount) }} before GST
@if ($l1 !== null && $rank > 1)
<br>**Lowest price:** {{ \App\Support\Money::inr($l1) }}
@endif

The buyer will now review the results and award the order. You'll hear from them directly.
@endif

<x-mail::button :url="$url">
View your result
</x-mail::button>

Thanks,<br>
GetL1
</x-mail::message>
