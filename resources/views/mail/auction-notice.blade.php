@php $t = fn ($v) => \App\Support\Md::escape((string) $v); @endphp
<x-mail::message>
@switch($kind)
@case('paused')
# Bidding is paused

The live auction for **{{ $t($rfq?->title) }}** has been paused by the GetL1 team for a technical check. The clock is stopped and no bids are accepted until it resumes. Nobody loses time: it restarts with the same time left.
@break
@case('resumed')
# Bidding has resumed

The live auction for **{{ $t($rfq?->title) }}** is running again. It now ends at **{{ $auction->ends_at->ist()->format('h:i A') }}** IST.
@break
@case('extended')
# More time added

The GetL1 team added **{{ $minutes }} minutes** to the live auction for **{{ $t($rfq?->title) }}**. It now ends at **{{ $auction->ends_at->ist()->format('h:i A') }}** IST.
@break
@default
# Auction cancelled

The live auction for **{{ $t($rfq?->title) }}** was cancelled by the GetL1 team. Its result will not be used.
@if (! $forSupplier)
Your sealed quotes are unchanged, and you can schedule a new auction from the RFQ. {{ $auction->paid_with_credit ? 'The auction credit has been returned to your account.' : 'It does not count towards your monthly auctions.' }}
@else
The buyer may schedule a new auction. You'll be invited again by email if they do.
@endif
@endswitch

@if ($reason)
**Reason:** {{ $t($reason) }}
@endif

<x-mail::button :url="$url">
Open the auction
</x-mail::button>

Questions? Reply to this email.

GetL1
</x-mail::message>
