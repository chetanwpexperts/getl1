<x-mail::message>
# Thanks, {{ \App\Support\Md::escape(\Illuminate\Support\Str::before($lead->name, ' ')) }}

We've received your request for **{{ \App\Support\Md::escape($lead->company) }}**.

@if ($lead->interest === 'supplier')
Someone from GetL1 will contact you within one working day. Suppliers always use GetL1 free: you'll receive buyers' requirements by email and WhatsApp, quote from your phone, and see your rank live in auctions.
@else
Someone from GetL1 will call you within one working day to understand what you buy and set up your account. You can run your first live auction within a week, and the free trial includes everything on the Growth plan.
@endif

If you'd like to share a recent requirement (an Excel indent, a PDF or even a photo of a handwritten list), just reply to this email. We'll show you exactly how it would run on GetL1.

Thanks,<br>
GetL1
</x-mail::message>
