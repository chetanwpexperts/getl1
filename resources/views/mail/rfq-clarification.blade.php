<x-mail::message>
# {{ $question ? ($mine ? 'Your question was answered' : 'A question was answered') : 'Clarification from the buyer' }}

**{{ \App\Support\Md::escape($buyer->name) }}** {{ $question ? 'answered a question' : 'posted a clarification' }} on **{{ \App\Support\Md::escape($rfq->title) }}** ({{ $rfq->ref_no }}).

@if ($question)
**Question:** {{ \App\Support\Md::escape($question) }}

@endif
<x-mail::panel>
{{ \App\Support\Md::escape($answer) }}
</x-mail::panel>

@if ($private)
This answer was sent only to you.
@else
Every supplier on this RFQ received the same {{ $question ? 'answer' : 'clarification' }}.
@endif
Quotes close {{ $deadline }}. You can revise your quote until then.

<x-mail::button :url="$url">
View RFQ
</x-mail::button>

Thanks,<br>
GetL1
</x-mail::message>
