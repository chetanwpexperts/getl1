@php $e = fn ($v) => \App\Support\Md::escape((string) $v); @endphp
<x-mail::message>
# You've been added to {{ $e($org->name) }}

Hello {{ $e(\Illuminate\Support\Str::before($member->name, ' ')) }},

{{ $e($by->name) }} added you to **{{ $e($org->name) }}** on GetL1{{ $role ? ' as '.$e($role) : '' }}. GetL1 is where the team runs RFQs, live reverse auctions and purchase orders.

@if ($link)
<x-mail::button :url="$link">
Set your password
</x-mail::button>

This link works once and expires in 7 days. Your login email is **{{ $e($member->email) }}**.
@else
<x-mail::button :url="route('login')">
Sign in to GetL1
</x-mail::button>

Sign in with your usual GetL1 login and switch to {{ $e($org->name) }} from the company menu at the top.
@endif

If you weren't expecting this, you can ignore this email.
</x-mail::message>
