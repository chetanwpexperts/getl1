<x-mail::message>
# You're invited to quote

**{{ \App\Support\Md::escape($buyer->name) }}**{{ $buyer->city ? ', '.\App\Support\Md::escape($buyer->city) : '' }} has invited you to quote for:

**{{ \App\Support\Md::escape($rfq->title) }}** ({{ $rfq->ref_no }})

<x-mail::table>
| Item | Quantity |
|:-----|---------:|
@foreach ($items as $item)
| {{ \App\Support\Md::escape($item->name) }} | {{ rtrim(rtrim(number_format((float) $item->qty, 3, '.', ','), '0'), '.') }} {{ $item->unit }} |
@endforeach
</x-mail::table>

**Quote deadline:** {{ $deadline }}

Your quote is sealed: the buyer sees prices only after the deadline.

<x-mail::button :url="$url">
View RFQ and quote
</x-mail::button>

This link is for your company only. If you weren't expecting it, you can ignore this email.

Thanks,<br>
GetL1
</x-mail::message>
