<x-mail::message>
@if ($inv->status === 'approved')
# Invoice approved

**{{ \App\Support\Md::escape($buyer->name) }}** approved your invoice **{{ $inv->invoice_number }}** ({{ \App\Support\Money::inr($inv->total_amount) }}) against {{ $award->po_number }}.
@if ($inv->due_date)

**Payment due by:** {{ $inv->due_date->format('d M Y') }}@if ($inv->is_msme) (as an MSME supplier, you're entitled to payment within 45 days of acceptance)@endif
@endif
@elseif ($inv->status === 'disputed')
# Invoice needs correction

**{{ \App\Support\Md::escape($buyer->name) }}** could not approve invoice **{{ $inv->invoice_number }}** against {{ $award->po_number }}.

**Reason:** {{ \App\Support\Md::escape($inv->review_note) }}

Please upload a corrected invoice (with a new invoice number or a credit note, as GST rules require) from the order page.
@else
# Invoice paid

**{{ \App\Support\Md::escape($buyer->name) }}** marked invoice **{{ $inv->invoice_number }}** as paid: **{{ \App\Support\Money::inr($inv->paid_amount) }}** on {{ $inv->paid_on->format('d M Y') }}@if ($inv->payment_ref) (reference {{ \App\Support\Md::escape($inv->payment_ref) }})@endif.
@if ((float) $inv->paid_amount < (float) $inv->total_amount - 1)

The amount is less than the invoice total ({{ \App\Support\Money::inr($inv->total_amount) }}), usually because of TDS. Contact the buyer if it doesn't match your records.
@endif
@endif

<x-mail::button :url="$url">
Open the order
</x-mail::button>

Thanks,<br>
GetL1
</x-mail::message>
