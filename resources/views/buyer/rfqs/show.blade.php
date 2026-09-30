@extends('layouts.app')

@section('title', $rfq->ref_no)

@section('content')
    @php
        $canManage = in_array($currentRole?->value, ['buyer_admin', 'buyer_user'], true);
        $status = $rfq->displayStatus();
        $deadline = $rfq->quote_deadline?->ist()->format('d M Y, h:i A');
        $input = 'rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20';
        $qtyFmt = fn ($q) => rtrim(rtrim(number_format((float) $q, 3, '.', ','), '0'), '.');
        $inviteStatus = function ($inv) use ($quotedSupplierIds) {
            if ($inv->status->value === 'declined') return 'declined';
            if ($inv->supplier_org_id && in_array((int) $inv->supplier_org_id, array_map('intval', $quotedSupplierIds), true)) return 'quoted';
            return $inv->status->value;
        };
    @endphp

    <x-live-page :url="route('buyer.rfqs.show.live', $rfq->id)" :live="$live" />
    <a href="{{ route('buyer.rfqs.index') }}" class="text-sm text-slate-600 hover:text-slate-900">← RFQs</a>

    <div class="mt-2 flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-2xl font-semibold">{{ $rfq->title }}</h1>
                <x-status-badge :status="$status" />
            </div>
            <p class="mt-1 text-sm text-slate-600">
                {{ $rfq->ref_no }}
                @if ($rfq->category) · {{ $rfq->category->name }} @endif
                @if ($deadline) · Quote deadline <span class="font-medium text-slate-800">{{ $deadline }} IST</span> @endif
            </p>
        </div>

        @if ($canManage && $rfq->isDraft())
            <div class="flex gap-2 text-sm">
                <a href="{{ route('buyer.rfqs.edit', $rfq->id) }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2 font-medium hover:bg-slate-50">Edit</a>
                <form method="POST" action="{{ route('buyer.rfqs.publish', $rfq->id) }}" data-confirm="Publish and send invitations to {{ $rfq->invites->count() }} supplier(s)?">
                    @csrf
                    <button class="rounded-lg bg-emerald-700 px-4 py-2 font-semibold text-white hover:bg-emerald-800">Publish &amp; send invites</button>
                </form>
            </div>
        @endif
    </div>

    @error('rfq') <p class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</p> @enderror

    {{-- Draft checklist --}}
    @if ($rfq->isDraft())
        <div class="mt-5 grid gap-3 sm:grid-cols-3">
            @foreach ([
                ['Items added', $rfq->items->isNotEmpty(), $errors->first('items')],
                ['Quote deadline set', (bool) $rfq->quote_deadline, $errors->first('quote_deadline')],
                ['Suppliers invited ('.$rfq->invites->count().')', $rfq->invites->isNotEmpty(), $errors->first('suppliers')],
            ] as [$label, $done, $err])
                @php
                    $box = $err ? 'border-red-200 bg-red-50 text-red-900'
                        : ($done ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-slate-200 bg-white text-slate-700');
                @endphp
                <div class="rounded-xl border p-4 text-sm {{ $box }}">
                    <span class="font-medium">{{ $err ? '!' : ($done ? '✓' : '○') }} {{ $label }}</span>
                    @if ($err)<p class="mt-1 text-red-700">{{ $err }}</p>@endif
                </div>
            @endforeach
        </div>
        @if ($rfq->invites->isNotEmpty() && $rfq->invites->count() < 3)
            <p class="mt-3 text-sm text-amber-800">Tip: auctions work best with 5 or more suppliers. More bidders usually means a lower L1.</p>
        @endif
    @endif

    {{-- Sealed / unsealed banner --}}
    @if ($status === 'open')
        <div class="mt-5 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900">
            <p class="font-semibold">{{ $quoteCount }} of {{ $rfq->invites->count() }} suppliers have quoted.</p>
            <p class="mt-1">Quotes are sealed. Prices unlock automatically at {{ $deadline }} IST (in <span class="font-semibold tabular-nums" data-countdown-to="{{ $rfq->quote_deadline->getTimestampMs() }}"></span>). This page updates on its own.</p>
        </div>
    @elseif ($status === 'cancelled')
        <div class="mt-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900">This RFQ was cancelled. Invited suppliers were notified.</div>
    @endif

    {{-- Live auction --}}
    @if ($auction)
        @php
            $aStatus = \App\Services\Auction\Standings::effectiveStatus($auction)->value;
        @endphp
        <div class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
            <div>
                <p class="font-semibold">
                    @if ($aStatus === 'scheduled') Live auction scheduled for {{ $auction->starts_at->ist()->format('d M Y, h:i A') }} IST (starts in <span class="tabular-nums" data-countdown-to="{{ $auction->starts_at->getTimestampMs() }}"></span>)
                    @elseif ($aStatus === 'live') Live auction in progress
                    @else Live auction closed
                    @endif
                </p>
                <p class="mt-1">
                    Start price {{ \App\Support\Money::inr($auction->start_price) }}
                    @if ($auction->current_l1 !== null) · Current L1 {{ \App\Support\Money::inr($auction->current_l1) }} @endif
                </p>
            </div>
            <a href="{{ route('buyer.auctions.show', $auction->id) }}" class="rounded-lg bg-emerald-700 px-4 py-2 font-semibold text-white hover:bg-emerald-800">
                {{ $aStatus === 'closed' ? 'View auction results' : 'Open auction console' }}
            </a>
        </div>
    @elseif ($canScheduleAuction && $canManage)
        <div class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4 text-sm">
            <div>
                <p class="font-semibold">Push prices lower with a live auction</p>
                <p class="mt-1 text-slate-600">{{ $quoteCount }} suppliers quoted. They start at their sealed price and bid down in real time.</p>
            </div>
            <a href="{{ route('buyer.auctions.create', $rfq->id) }}" class="rounded-lg bg-emerald-700 px-4 py-2 font-semibold text-white hover:bg-emerald-800">Schedule live auction</a>
        </div>
    @endif

    {{-- Comparison --}}
    @if ($unsealed)
        <section class="mt-6 rounded-xl border border-slate-200 bg-white">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-5 py-3">
                <h2 class="font-semibold">Quote comparison</h2>
                <span class="text-xs text-slate-500">Ranked by landed cost (price + GST + freight)</span>
            </div>
            @if ($comparison->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-slate-600">No quotes were submitted before the deadline.</p>
            @else
                @php
                    $lastTotal = $rfq->items->every(fn ($i) => $i->last_purchase_price !== null)
                        ? $rfq->items->sum(fn ($i) => (float) $i->last_purchase_price * (float) $i->qty) : null;
                    $l1 = $comparison->first();
                @endphp
                @if ($lastTotal && $lastTotal > 0)
                    <p class="border-b border-slate-100 px-5 py-3 text-sm">
                        Best quote (before GST) vs your last purchase price:
                        <span class="font-semibold {{ $l1['basic'] <= $lastTotal ? 'text-emerald-700' : 'text-red-700' }}">
                            {{ \App\Support\Money::inr(abs($lastTotal - $l1['basic'])) }} {{ $l1['basic'] <= $lastTotal ? 'lower' : 'higher' }}
                            ({{ number_format(abs(1 - $l1['basic'] / $lastTotal) * 100, 1) }}%)
                        </span>
                    </p>
                @endif
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[760px] text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3">Rank</th><th class="px-4 py-3">Supplier</th>
                                <th class="px-4 py-3 text-right">Price (ex-GST)</th><th class="px-4 py-3 text-right">GST</th>
                                <th class="px-4 py-3 text-right">Freight</th><th class="px-4 py-3 text-right">Landed total</th><th class="px-4 py-3">Valid till</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($comparison as $row)
                                <tr class="{{ $row['rank'] === 1 ? 'bg-emerald-50' : '' }}">
                                    <td class="px-4 py-3"><span class="rounded px-2 py-0.5 text-xs font-semibold {{ $row['rank'] === 1 ? 'bg-emerald-700 text-white' : 'bg-slate-100 text-slate-700' }}">L{{ $row['rank'] }}</span></td>
                                    <td class="px-4 py-3">
                                        <span class="font-medium">{{ $row['quote']->supplier->name }}</span>
                                        @if ($row['quote']->supplier->isVerified())<span class="ml-1 text-xs text-emerald-700">✓ Verified</span>@endif
                                        @if ($row['quote']->notes)<span class="block text-xs text-slate-500">{{ \Illuminate\Support\Str::limit($row['quote']->notes, 120) }}</span>@endif
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ \App\Support\Money::inr($row['basic']) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ \App\Support\Money::inr($row['gst']) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ \App\Support\Money::inr($row['freight']) }}</td>
                                    <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ \App\Support\Money::inr($row['landed']) }}</td>
                                    <td class="px-4 py-3">{{ $row['quote']->valid_till?->format('d M Y') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <details class="border-t border-slate-100 px-5 py-3 text-sm">
                    <summary class="cursor-pointer font-medium text-slate-700">Item-wise unit prices</summary>
                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full min-w-[640px] text-sm">
                            <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                                <tr><th class="py-2 pr-4">Item</th>@foreach ($comparison as $row)<th class="py-2 pr-4 text-right">L{{ $row['rank'] }}</th>@endforeach</tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($rfq->items as $item)
                                    <tr>
                                        <td class="py-2 pr-4">{{ $item->name }} <span class="text-xs text-slate-500">({{ $qtyFmt($item->qty) }} {{ $item->unit }})</span></td>
                                        @foreach ($comparison as $row)
                                            @php $line = $row['lines'][$item->id] ?? null; @endphp
                                            <td class="py-2 pr-4 text-right tabular-nums">{{ $line ? \App\Support\Money::inr($line['unit_price']) : '—' }}
                                                @if ($line)<span class="block text-xs text-slate-500">GST {{ rtrim(rtrim(number_format($line['gst_rate'], 2), '0'), '.') }}%</span>@endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            @endif
        </section>
    @endif

    {{-- Award, approval and purchase order --}}
    @include('buyer.rfqs._award')

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- Items --}}
            <section class="rounded-xl border border-slate-200 bg-white">
                <h2 class="border-b border-slate-200 px-5 py-3 font-semibold">Items</h2>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[560px] text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr><th class="px-4 py-2">#</th><th class="px-4 py-2">Item</th><th class="px-4 py-2 text-right">Qty</th><th class="px-4 py-2">Needed by</th><th class="px-4 py-2 text-right">Last price</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($rfq->items as $item)
                                <tr>
                                    <td class="px-4 py-2 text-slate-500">{{ $item->line_no }}</td>
                                    <td class="px-4 py-2">{{ $item->name }}@if ($item->spec)<span class="block text-xs text-slate-500">{{ $item->spec }}</span>@endif</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $qtyFmt($item->qty) }} {{ $item->unit }}</td>
                                    <td class="px-4 py-2">{{ $item->delivery_date?->format('d M Y') ?? '—' }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-slate-600">{{ $item->last_purchase_price !== null ? \App\Support\Money::inr($item->last_purchase_price) : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- Suppliers --}}
            <section class="rounded-xl border border-slate-200 bg-white">
                <h2 class="border-b border-slate-200 px-5 py-3 font-semibold">Invited suppliers ({{ $rfq->invites->count() }})</h2>
                @forelse ($rfq->invites as $inv)
                    @php
                        $name = $inv->supplier?->name ?? $inv->listEntry?->displayName() ?? 'Supplier';
                        $phone = $inv->listEntry?->contact_phone;
                        $url = \App\Services\RfqService::inviteUrl($inv);
                        $wa = $phone ? 'https://wa.me/91'.$phone.'?text='.rawurlencode(
                            "Hello, {$currentOrg->name} has invited you to quote for \"{$rfq->title}\" on GetL1. Deadline: {$deadline} IST. Open: {$url}") : null;
                    @endphp
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-3 text-sm last:border-0">
                        <div>
                            <span class="font-medium">{{ $name }}</span>
                            <x-status-badge :status="$inviteStatus($inv)" class="ml-1" />
                            <span class="block text-xs text-slate-500">{{ collect([$inv->listEntry?->contact_phone, $inv->listEntry?->contact_email])->filter()->implode(' · ') ?: 'No contact details' }}</span>
                        </div>
                        <div class="flex items-center gap-3">
                            @if (! $rfq->isDraft() && $status === 'open' && $canManage)
                                <button type="button" data-copy="{{ $url }}" class="text-emerald-700 hover:underline">Copy link</button>
                                @if ($wa)<a href="{{ $wa }}" target="_blank" rel="noopener noreferrer" class="text-emerald-700 hover:underline">WhatsApp</a>@endif
                            @endif
                            @if ($rfq->isDraft() && $canManage)
                                <form method="POST" action="{{ route('buyer.rfqs.invites.destroy', [$rfq->id, $inv->id]) }}">
                                    @csrf @method('DELETE')
                                    <button class="text-slate-500 hover:text-red-700 hover:underline">Remove</button>
                                </form>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="px-5 py-6 text-sm text-slate-600">No suppliers invited yet.</p>
                @endforelse

                @if ($canInvite && $canManage)
                    <div class="border-t border-slate-200 bg-slate-50 px-5 py-4">
                        @if ($available->isEmpty())
                            <p class="text-sm text-slate-600">Everyone in your supplier list is invited. <a href="{{ route('buyer.suppliers.create') }}" class="font-medium text-emerald-700 hover:underline">Add a supplier</a></p>
                        @else
                            <form method="POST" action="{{ route('buyer.rfqs.invites.store', $rfq->id) }}">
                                @csrf
                                <p class="text-sm font-medium">Invite from your supplier list</p>
                                @error('suppliers') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                                <div class="mt-3 grid max-h-64 gap-2 overflow-y-auto sm:grid-cols-2">
                                    @foreach ($available as $s)
                                        <label class="flex items-start gap-2 rounded-lg border border-slate-200 bg-white p-2.5 text-sm hover:border-emerald-300">
                                            <input type="checkbox" name="suppliers[]" value="{{ $s->id }}" class="mt-0.5 rounded border-slate-300">
                                            <span>
                                                <span class="font-medium">{{ $s->company_name }}</span>
                                                @if ($s->supplier?->isVerified())<span class="ml-1 text-xs text-emerald-700">✓ Verified</span>@endif
                                                @if ($s->tag)<span class="block text-xs text-slate-500">{{ $s->tag }}</span>@endif
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                                <button class="mt-3 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Invite selected</button>
                                @if (! $rfq->isDraft())<span class="ml-2 text-xs text-slate-500">Invitations are sent immediately.</span>@endif
                            </form>
                        @endif
                    </div>
                @endif
            </section>
        </div>

        <div class="space-y-6">
            {{-- Terms --}}
            <section class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
                <h2 class="font-semibold">Terms</h2>
                <dl class="mt-3 space-y-2">
                    <div><dt class="text-xs text-slate-500">Payment</dt><dd>{{ $paymentTerms[$rfq->terms['payment'] ?? ''] ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Freight</dt><dd>{{ $freightTerms[$rfq->terms['freight'] ?? ''] ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Delivery</dt><dd>{{ $rfq->terms['delivery'] ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Delivery location</dt><dd>{{ $rfq->delivery_location ?? '—' }}</dd></div>
                    @if (! empty($rfq->terms['other']))<div><dt class="text-xs text-slate-500">Other</dt><dd class="whitespace-pre-line">{{ $rfq->terms['other'] }}</dd></div>@endif
                    @if ($rfq->description)<div><dt class="text-xs text-slate-500">Details</dt><dd class="whitespace-pre-line">{{ $rfq->description }}</dd></div>@endif
                </dl>
            </section>

            {{-- Attachments --}}
            <section class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
                <h2 class="font-semibold">Attachments</h2>
                <ul class="mt-3 space-y-2">
                    @forelse ($rfq->attachments as $att)
                        <li class="flex items-center justify-between gap-2">
                            <a href="{{ route('buyer.rfqs.attachments.download', [$rfq->id, $att->id]) }}" class="truncate text-emerald-700 hover:underline">{{ $att->original_name }}</a>
                            @if ($rfq->isDraft() && $canManage)
                                <form method="POST" action="{{ route('buyer.rfqs.attachments.destroy', [$rfq->id, $att->id]) }}">
                                    @csrf @method('DELETE')
                                    <button class="text-xs text-slate-500 hover:text-red-700">Remove</button>
                                </form>
                            @endif
                        </li>
                    @empty
                        <li class="text-slate-600">No attachments.</li>
                    @endforelse
                </ul>
                @if ($canManage && ($rfq->isDraft() || $status === 'open') && $rfq->attachments->count() < \App\Services\RfqService::MAX_ATTACHMENTS)
                    <form method="POST" action="{{ route('buyer.rfqs.attachments.store', $rfq->id) }}" enctype="multipart/form-data" class="mt-4 space-y-2">
                        @csrf
                        <input type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png,.xlsx,.docx"
                               class="block w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm file:mr-3 file:rounded file:border-0 file:bg-slate-100 file:px-3 file:py-1.5">
                        @error('file') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                        <button class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 font-medium hover:bg-slate-50">Upload</button>
                        <p class="text-xs text-slate-500">Drawings or specs: PDF, image, Excel or Word, max 10 MB.</p>
                    </form>
                @endif
            </section>

            {{-- Extend / cancel --}}
            @if ($canManage && in_array($status, ['draft', 'open', 'closed'], true))
                <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-5 text-sm">
                    @if ($rfq->status->value === 'published')
                        <form method="POST" action="{{ route('buyer.rfqs.extend', $rfq->id) }}" class="space-y-2">
                            @csrf
                            <label for="new_deadline" class="block font-medium">Extend quote deadline</label>
                            <input id="new_deadline" type="datetime-local" name="quote_deadline" required class="{{ $input }} w-full">
                            @error('quote_deadline') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                            <button class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 font-medium hover:bg-slate-50">Extend and notify suppliers</button>
                        </form>
                    @endif
                    <details>
                        <summary class="cursor-pointer text-red-700">Cancel this RFQ</summary>
                        <form method="POST" action="{{ route('buyer.rfqs.cancel', $rfq->id) }}" class="mt-2 space-y-2" data-confirm="Cancel this RFQ? Invited suppliers will be notified.">
                            @csrf
                            <input name="reason" required minlength="5" maxlength="255" placeholder="Reason (shown to suppliers)" class="{{ $input }} w-full">
                            @error('reason') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                            <button class="rounded-lg border border-red-300 px-3 py-1.5 font-medium text-red-700 hover:bg-red-50">Cancel RFQ</button>
                        </form>
                    </details>
                </section>
            @endif

            <p class="text-xs text-slate-500">Created by {{ $rfq->creator?->name }} on {{ $rfq->created_at->ist()->format('d M Y, h:i A') }}.</p>
        </div>
    </div>

    @include('buyer.rfqs._activity')
@endsection
