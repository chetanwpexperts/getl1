@php $e = fn ($v) => \App\Support\Md::escape((string) $v); @endphp
<x-mail::message>
# New {{ $lead->interest === 'supplier' ? 'supplier' : 'buyer' }} lead

**{{ $e($lead->company) }}**{{ $lead->city ? ', '.$e($lead->city) : '' }}

- Name: {{ $e($lead->name) }}
- Email: {{ $e($lead->email) }}
- Phone: {{ $e($lead->phone) }}
@if ($lead->monthly_spend)
- Monthly purchase: {{ ['under_5l' => 'Under ₹5 lakh', '5l_25l' => '₹5–25 lakh', '25l_1cr' => '₹25 lakh – 1 crore', 'over_1cr' => 'Over ₹1 crore'][$lead->monthly_spend] ?? '' }}
@endif
@if ($lead->source)
- Came from: {{ $e($lead->source) }}
@endif

@if ($lead->message)
> {{ $e(\Illuminate\Support\Str::limit($lead->message, 1000)) }}
@endif

Reply to this email to write to them directly. Try to call within one working day.

GetL1
</x-mail::message>
