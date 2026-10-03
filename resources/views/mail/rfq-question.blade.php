<x-mail::message>
# A supplier has a question

**{{ \App\Support\Md::escape($supplier) }}** asked about **{{ \App\Support\Md::escape($rfq->title) }}** ({{ $rfq->ref_no }}):

<x-mail::panel>
{{ \App\Support\Md::escape($question) }}
</x-mail::panel>

Answer it to everyone (other suppliers never see who asked) or only to this supplier. Quotes close {{ $deadline }}.

<x-mail::button :url="$url">
Answer the question
</x-mail::button>

Thanks,<br>
GetL1
</x-mail::message>
