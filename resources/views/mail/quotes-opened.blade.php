<x-mail::message>
@if ($s['quoted'] > 0)
# {{ $s['quoted'] }} of {{ $s['invited'] }} suppliers quoted

The quote deadline for **{{ \App\Support\Md::escape($rfq->title) }}** ({{ $rfq->ref_no }}) has passed and prices are now open.

**Lowest quote (L1):** {{ \App\Support\Md::escape($s['l1']['supplier']) }}, {{ \App\Support\Money::inr($s['l1']['basic']) }} before GST ({{ \App\Support\Money::inr($s['l1']['landed']) }} landed)
@if ($s['last_total'])
<br>**vs your last purchase price:** {{ $s['l1']['basic'] <= $s['last_total'] ? \App\Support\Money::inr($s['last_total'] - $s['l1']['basic']).' lower' : \App\Support\Money::inr($s['l1']['basic'] - $s['last_total']).' higher' }}
@endif
@if ($s['declined'])
<br>**Declined:** {{ $s['declined'] }}
@endif

<x-mail::button :url="$url">
Compare quotes
</x-mail::button>

@if ($s['can_auction'])
**Next step:** run a live auction and let suppliers compete below their sealed price. Most auctions bring the price down further. [Schedule a live auction]({{ $auctionUrl }})
@endif
@else
# No quotes received

The quote deadline for **{{ \App\Support\Md::escape($rfq->title) }}** ({{ $rfq->ref_no }}) passed without any quotes.
@if ($s['declined'])
{{ $s['declined'] }} of {{ $s['invited'] }} suppliers declined.
@endif

Consider inviting more suppliers or creating the RFQ again with a later deadline.

<x-mail::button :url="$url">
Open the RFQ
</x-mail::button>
@endif

Thanks,<br>
GetL1
</x-mail::message>
