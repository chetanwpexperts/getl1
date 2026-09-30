<x-mail::message>
# Your live auction starts soon

**{{ \App\Support\Md::escape($buyer->name) }}**'s live auction for **{{ \App\Support\Md::escape($rfq->title) }}** ({{ $rfq->ref_no }}) starts at **{{ $starts }}**.

You start at your sealed quote. Sign in a few minutes early and keep a stable internet connection.

<x-mail::button :url="$url">
Open the auction room
</x-mail::button>

Thanks,<br>
GetL1
</x-mail::message>
