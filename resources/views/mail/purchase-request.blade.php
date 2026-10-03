@php $e = fn ($v) => \App\Support\Md::escape((string) $v); @endphp
<x-mail::message>
@if ($kind === 'submitted')
# Purchase request to approve

**{{ $e($pr->requester?->name) }}**{{ $pr->department ? ' ('.$e($pr->department).')' : '' }} has asked for the following. Please approve or reject it.
@elseif ($pr->status === \App\Models\PurchaseRequest::APPROVED)
# Your request was approved

**{{ $e($pr->decider?->name) }}** approved your request. The purchase team will now get quotes from suppliers, and you'll be told when it's ordered.
@else
# Your request was rejected

**{{ $e($pr->decider?->name) }}** rejected your request.

**Reason:** {{ $e($pr->decision_note) }}
@endif

**{{ $pr->pr_number }}: {{ $e($pr->title) }}**@if ($pr->needed_by) · needed by {{ $pr->needed_by->format('d M Y') }}@endif

<x-mail::table>
| Item | Quantity |
|:-----|---------:|
@foreach ($pr->items as $i)
| {{ $e($i['name']) }}@if (! empty($i['spec'])) ({{ $e($i['spec']) }})@endif | {{ rtrim(rtrim(number_format($i['qty'], 3), '0'), '.') }} {{ $i['unit'] }} |
@endforeach
</x-mail::table>

@if ($kind === 'submitted' && $pr->notes)
**Note:** {{ $e($pr->notes) }}
@endif

<x-mail::button :url="$url">
{{ $kind === 'submitted' ? 'Review the request' : 'Open the request' }}
</x-mail::button>

Thanks,<br>
GetL1
</x-mail::message>
