<x-mail::message>
@if ($kind === 'cancelled')
# RFQ cancelled

**{{ \App\Support\Md::escape($buyer->name) }}** has cancelled **{{ \App\Support\Md::escape($rfq->title) }}** ({{ $rfq->ref_no }}). No further action is needed.

@if ($reason)
**Reason:** {{ \App\Support\Md::escape($reason) }}
@endif
@else
# Deadline extended

**{{ \App\Support\Md::escape($buyer->name) }}** has extended the quote deadline for **{{ \App\Support\Md::escape($rfq->title) }}** ({{ $rfq->ref_no }}).

**New deadline:** {{ $deadline }}

<x-mail::button :url="$url">
View RFQ
</x-mail::button>
@endif

Thanks,<br>
GetL1
</x-mail::message>
