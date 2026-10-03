@extends('layouts.app')

@section('title', 'Notifications')

@section('content')
    <x-page-header title="Notifications" subtitle="Updates on your RFQs, auctions, orders and payments, newest first.">
        @if ($unread > 0)
            <form method="POST" action="{{ route('notifications.read-all') }}">
                @csrf
                <button class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium shadow-sm hover:bg-slate-50">Mark all as read</button>
            </form>
        @endif
        <a href="{{ route('account.edit') }}#notifications" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium shadow-sm hover:bg-slate-50">Settings</a>
    </x-page-header>


    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <ul class="divide-y divide-slate-100">
            @forelse ($notifications as $n)
                @php
                    $d = $n->data;
                    $cat = \App\Services\Notifier::CATEGORIES[$d['category'] ?? ''][0] ?? null;
                    $org = isset($d['org_id']) && $orgNames->count() > 1 ? ($orgNames[$d['org_id']] ?? null) : null;
                @endphp
                <li>
                    <a href="{{ route('notifications.open', $n->id) }}" class="flex items-start gap-4 px-5 py-4 hover:bg-slate-50 {{ $n->read_at ? '' : 'bg-emerald-50/40' }}">
                        <span class="mt-2 size-2 shrink-0 rounded-full {{ $n->read_at ? 'bg-transparent' : 'bg-emerald-600' }}"></span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm {{ $n->read_at ? 'text-slate-700' : 'font-semibold text-slate-900' }}">{{ $d['title'] ?? '' }}</span>
                            @if (! empty($d['body']))<span class="mt-0.5 block text-sm text-slate-600">{{ $d['body'] }}</span>@endif
                            <span class="mt-1 block text-xs text-slate-400">
                                {{ $n->created_at->ist()->format('d M Y, h:i A') }} IST
                                @if ($cat) · {{ $cat }} @endif
                                @if ($org) · {{ $org }} @endif
                            </span>
                        </span>
                    </a>
                </li>
            @empty
                <li class="px-5 py-14 text-center text-sm text-slate-600">No notifications yet. Updates on your RFQs, auctions, orders and payments will appear here.</li>
            @endforelse
        </ul>
    </div>
    <div class="mt-4">{{ $notifications->links() }}</div>
@endsection
