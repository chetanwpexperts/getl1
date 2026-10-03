<x-mail::message>
# Live auction scheduled

**{{ \App\Support\Md::escape($buyer->name) }}** will run a live {{ $auction->isJapanese() ? 'Japanese' : 'reverse' }} auction for **{{ \App\Support\Md::escape($rfq->title) }}** ({{ $rfq->ref_no }}).

**Starts:** {{ $starts }}<br>
@if ($auction->isJapanese())
**How it works:** rounds of {{ $auction->round_seconds }} seconds starting at {{ \App\Support\Money::inr($auction->opening_price) }} (before GST). The price drops every round; accept it to stay in, or you drop out. The last supplier still accepting wins.
@elseif ($auction->isPerItem())
**Duration:** {{ (int) $duration }} minutes (extends automatically if bids come in at the end)

Item by item: you start at the rates in your sealed quote and can lower the rate of any item. Each item is ranked on its own, so you can win some items even if your total isn't the lowest.
@else
**Duration:** {{ (int) $duration }} minutes (extends automatically if bids come in at the end)

You start at your sealed quote. During the auction you'll see your rank and can lower your price.
@endif

Other suppliers never see your name or prices.

<x-mail::button :url="$url">
Open the auction
</x-mail::button>

New to live auctions? [Try a 5-minute practice auction]({{ $practiceUrl }}): same screen, simulated competitors, nothing is saved.

Tip: sign in a few minutes early and keep a stable internet connection.

Thanks,<br>
GetL1
</x-mail::message>
