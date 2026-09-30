<x-mail::message>
# Purchase order accepted

**{{ \App\Support\Md::escape($supplier->name) }}** accepted purchase order **{{ $award->po_number }}** ({{ \App\Support\Money::inr($award->grand_total) }}) on {{ $award->supplier_accepted_at?->ist()->format('d M Y, h:i A') }} IST.

<x-mail::button :url="$url">
View the order
</x-mail::button>

Thanks,<br>
GetL1
</x-mail::message>
