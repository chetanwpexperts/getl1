<x-mail::message>
# Purchase order {{ $award->po_number }}

**{{ \App\Support\Md::escape($buyer->name) }}** has awarded you **{{ \App\Support\Md::escape($rfq->title) }}** ({{ $rfq->ref_no }}).

**Order value:** {{ \App\Support\Money::inr($award->grand_total) }} (incl. GST{{ (float) $award->freight_total > 0 ? ' and freight' : '' }})<br>
**Taxable value:** {{ \App\Support\Money::inr($award->total) }}

The purchase order is attached as a PDF. Please accept it on GetL1 so the buyer knows you've received it.

<x-mail::button :url="$url">
View and accept the order
</x-mail::button>

Quote the PO number on your invoice and delivery challan.

Thanks,<br>
GetL1
</x-mail::message>
