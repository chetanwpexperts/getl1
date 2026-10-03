<x-mail::message>
# Invoice to review

**{{ \App\Support\Md::escape($inv->supplier->name) }}** uploaded invoice **{{ $inv->invoice_number }}** dated {{ $inv->invoice_date->format('d M Y') }} against **{{ $award->po_number }}**.

**Total:** {{ \App\Support\Money::inr($inv->total_amount) }} ({{ \App\Support\Money::inr($inv->taxable_amount) }} + GST {{ \App\Support\Money::inr($inv->gst_amount) }})
@if ($inv->due_date)
<br>**Pay by:** {{ $inv->due_date->format('d M Y') }}@if ($inv->is_msme) (MSME supplier: 45-day rule)@endif
@endif

@foreach ($checks as $c)
{{ $c['ok'] ? '✓' : '✗' }} **{{ $c['label'] }}**: {{ \App\Support\Md::escape($c['detail']) }}<br>
@endforeach

<x-mail::button :url="$url">
Review the invoice
</x-mail::button>

Thanks,<br>
GetL1
</x-mail::message>
