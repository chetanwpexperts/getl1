<x-mail::message>
# Payment received

Thank you. We've received **{{ \App\Support\Money::inr($p->total) }}** for **{{ $p->description() }}**.

**Invoice:** {{ $p->invoice_number }}<br>
**Payment reference:** {{ $p->razorpay_payment_id }}

The invoice is attached. You can also download it any time from Billing.

<x-mail::button :url="$url">
Open Billing
</x-mail::button>

Thanks,<br>
GetL1
</x-mail::message>
