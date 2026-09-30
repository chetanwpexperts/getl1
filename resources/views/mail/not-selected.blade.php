<x-mail::message>
# Thank you for your quote

**{{ \App\Support\Md::escape($buyer->name) }}** has completed **{{ \App\Support\Md::escape($rfq->title) }}** ({{ $rfq->ref_no }}) and placed the order with another supplier this time.

Thank you for taking part. Buyers invite suppliers who respond quickly and price competitively, so we hope to see you on the next one.

Thanks,<br>
GetL1
</x-mail::message>
