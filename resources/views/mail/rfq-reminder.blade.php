<x-mail::message>
# Quotes close in {{ $left }}

**{{ \App\Support\Md::escape($buyer->name) }}** hasn't received your quote for **{{ \App\Support\Md::escape($rfq->title) }}** ({{ $rfq->ref_no }}) yet.

**Deadline:** {{ $deadline }}

Quotes aren't accepted after the deadline, and only suppliers who quote can take part in a live auction for this RFQ.

<x-mail::button :url="$url">
Submit your quote
</x-mail::button>

Not interested? Open the RFQ and decline, so the buyer knows.

Thanks,<br>
GetL1
</x-mail::message>
