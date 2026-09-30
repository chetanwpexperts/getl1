<x-mail::message>
@if ($rejected)
# Award rejected

**{{ \App\Support\Md::escape($by?->name ?? 'The approver') }}** rejected the award of **{{ \App\Support\Md::escape($rfq->title) }}** ({{ $rfq->ref_no }}) to **{{ \App\Support\Md::escape($supplier->name) }}**.

**Comment:** {{ \App\Support\Md::escape($award->decision_note) }}

You can award again from the RFQ page.
@else
# Award approved

**{{ \App\Support\Md::escape($by?->name ?? 'The approver') }}** approved the award of **{{ \App\Support\Md::escape($rfq->title) }}** ({{ $rfq->ref_no }}) to **{{ \App\Support\Md::escape($supplier->name) }}**. The purchase order is being sent to the supplier now.
@endif

<x-mail::button :url="$url">
Open the RFQ
</x-mail::button>

Thanks,<br>
GetL1
</x-mail::message>
