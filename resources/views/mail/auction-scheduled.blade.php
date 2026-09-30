<x-mail::message>
# Live auction scheduled

**{{ \App\Support\Md::escape($buyer->name) }}** will run a live reverse auction for **{{ \App\Support\Md::escape($rfq->title) }}** ({{ $rfq->ref_no }}).

**Starts:** {{ $starts }}<br>
**Duration:** {{ (int) $duration }} minutes (extends automatically if bids come in at the end)

You start at your sealed quote. During the auction you'll see your rank and can lower your price. Other suppliers never see your name or prices.

<x-mail::button :url="$url">
Open the auction
</x-mail::button>

Tip: sign in a few minutes early and keep a stable internet connection.

Thanks,<br>
GetL1
</x-mail::message>
