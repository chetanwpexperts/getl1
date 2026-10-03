{{-- The bell: unread count, latest alerts, and live pop-ups (resources/js/notifications.js). --}}
@php $unread = auth()->user()->visibleNotifications()->whereNull('read_at')->count(); @endphp
<details class="relative" data-dropdown data-bell
         data-user="{{ auth()->id() }}" data-since="{{ now()->getTimestampMs() }}" data-feed="{{ route('notifications.feed') }}" data-read-all="{{ route('notifications.read-all') }}"
         data-devices="{{ route('notifications.devices.store') }}" data-vapid="{{ config('webpush.public_key') }}">
    <summary class="relative flex size-9 cursor-pointer list-none items-center justify-center rounded-full text-slate-500 hover:bg-slate-100 hover:text-slate-800 [&::-webkit-details-marker]:hidden" aria-label="Notifications">
        <x-icon name="bell" class="size-[22px]" />
        <span data-bell-count @class(['absolute -right-0.5 -top-0.5 min-w-[18px] rounded-full bg-red-600 px-1 text-center text-[11px] font-semibold leading-[18px] text-white ring-2 ring-white', 'hidden' => ! $unread])>{{ $unread > 99 ? '99+' : $unread }}</span>
    </summary>
    <div class="fixed inset-x-2 top-16 z-40 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl sm:absolute sm:inset-x-auto sm:right-0 sm:top-full sm:mt-2 sm:w-96">
        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
            <p class="text-sm font-semibold">Notifications</p>
            <button type="button" data-bell-read-all class="text-xs font-medium text-emerald-700 hover:underline">Mark all as read</button>
        </div>
        <ul data-bell-list class="max-h-[60vh] divide-y divide-slate-100 overflow-y-auto text-sm" aria-live="polite">
            <li class="px-4 py-8 text-center text-slate-500">Loading…</li>
        </ul>
        <div data-push-offer hidden class="border-t border-slate-100 bg-slate-50 px-4 py-3 text-xs text-slate-600">
            <p>Get alerts on this device even when GetL1 is closed.</p>
            <button type="button" data-push-enable class="mt-2 rounded-lg bg-emerald-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-800">Turn on device alerts</button>
            <p data-push-msg class="mt-2 hidden text-slate-600"></p>
        </div>
        <a href="{{ route('notifications.index') }}" class="block border-t border-slate-100 px-4 py-2.5 text-center text-sm font-medium text-slate-700 hover:bg-slate-50">See all</a>
    </div>
</details>
