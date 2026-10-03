<x-mail::message>
# MSME payments to watch

These invoices from MSME suppliers for **{{ \App\Support\Md::escape($buyer->name) }}** are due within 7 days or already overdue. Under the MSMED Act they must be paid within 45 days of accepting the goods; paying later also means the expense can't be deducted this year (Income Tax Act section 43B(h)).

<x-mail::table>
| Supplier | Invoice | Amount | Pay by |
|:---------|:--------|-------:|:-------|
@foreach ($invoices as $i)
| {{ \App\Support\Md::escape($i->supplier->name) }} | {{ $i->invoice_number }} | {{ \App\Support\Money::inr($i->total_amount) }} | {{ $i->due_date->format('d M Y') }}{{ $i->isOverdue() ? ' (overdue)' : '' }} |
@endforeach
</x-mail::table>

<x-mail::button :url="$url">
Open payments
</x-mail::button>

Please confirm the tax position with your CA.

Thanks,<br>
GetL1
</x-mail::message>
