@extends('layouts.app')

@section('title', 'RFQs')

@section('content')
    <x-live-page :url="route('supplier.rfqs.live')" :live="$live" />
    <h1 class="text-2xl font-semibold">RFQs</h1>
    <p class="mt-1 text-sm text-slate-600">Requests you've been invited to quote on. Your prices stay sealed until each deadline.</p>

    <div class="mt-5 overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="w-full min-w-[640px] text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr><th class="px-4 py-3">RFQ</th><th class="px-4 py-3">Buyer</th><th class="px-4 py-3">Deadline</th><th class="px-4 py-3">Status</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($invites as $inv)
                    @php
                        $rfq = $inv->rfq;
                        $myAuction = $auctions[$rfq->id] ?? null;
                        $state = match (true) {
                            $rfq->isCancelled() => 'cancelled',
                            $myAuction !== null => ['scheduled' => 'scheduled', 'live' => 'live'][\App\Services\Auction\Standings::effectiveStatus($myAuction)->value] ?? 'evaluating',
                            $inv->status->value === 'declined' => 'declined',
                            in_array($rfq->id, $quotedRfqIds) => 'quoted',
                            ! $rfq->isOpenForQuotes() => 'closed',
                            default => $inv->status->value,
                        };
                        $auctionLabel = match ($state) {
                            'live' => 'Join live auction →',
                            'scheduled' => $myAuction ? 'Auction '.$myAuction->starts_at->ist()->format('d M, h:i A').' IST →' : '',
                            default => 'View auction result →',
                        };
                    @endphp
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3">
                            <a href="{{ route('supplier.rfqs.show', $inv->id) }}" class="font-medium hover:underline">{{ $rfq->title }}</a>
                            <span class="block text-xs text-slate-500">{{ $rfq->ref_no }}</span>
                            @if ($myAuction)
                                <a href="{{ route('supplier.auctions.show', $myAuction->id) }}" class="mt-1 inline-block text-xs font-semibold text-emerald-700 hover:underline">{{ $auctionLabel }}</a>
                            @endif
                        </td>
                        <td class="px-4 py-3">{{ $rfq->organization->name }}<span class="block text-xs text-slate-500">{{ $rfq->organization->city }}</span></td>
                        <td class="px-4 py-3">{{ $rfq->quote_deadline?->ist()->format('d M Y, h:i A') }}</td>
                        <td class="px-4 py-3"><x-status-badge :status="$state" /></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-10 text-center text-slate-600">No invitations yet. Buyers will invite you by email or WhatsApp.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $invites->links() }}</div>
@endsection
