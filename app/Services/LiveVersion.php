<?php

namespace App\Services;

use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\RfqAttachment;
use App\Models\RfqInvite;
use App\Services\Auction\Standings;

/**
 * A short fingerprint of everything a page shows. Pages poll it and refresh themselves
 * when it changes (a quote arrives, the deadline passes, an auction starts), and the next
 * time something is due to change ("refresh_at") so the switch happens on the second.
 *
 * Only a hash leaves the server, never the data behind it (sealed quotes stay sealed).
 */
class LiveVersion
{
    /** @return array{v:string, refresh_at:?int} */
    public static function buyerRfq(Rfq $rfq): array
    {
        $auction = self::activeAuction($rfq->id);

        return self::pack([
            $rfq->status->value, $rfq->displayStatus(), $rfq->quote_deadline?->getTimestamp(), $rfq->updated_at?->getTimestamp(),
            Quote::where('rfq_id', $rfq->id)->whereNotNull('submitted_at')->orderBy('id')->get(['id', 'submitted_at'])
                ->map(fn ($q) => $q->id.'@'.$q->submitted_at->getTimestamp())->implode(','),
            RfqInvite::where('rfq_id', $rfq->id)->orderBy('id')->get(['id', 'status'])->map(fn ($i) => $i->id.$i->status->value)->implode(','),
            RfqAttachment::where('rfq_id', $rfq->id)->count(),
            self::auctionPart($auction),
            self::awardPart($rfq->id),
        ], [$rfq->isOpenForQuotes() ? $rfq->quote_deadline : null, ...self::auctionTimes($auction)]);
    }

    /** @return array{v:string, refresh_at:?int} */
    public static function supplierRfq(RfqInvite $invite): array
    {
        $rfq = $invite->rfq;
        $auction = self::activeAuction($rfq->id, $invite->supplier_org_id);

        return self::pack([
            $rfq->status->value, $rfq->displayStatus(), $rfq->quote_deadline?->getTimestamp(), $rfq->updated_at?->getTimestamp(),
            $invite->status->value,
            Quote::where('rfq_id', $rfq->id)->where('supplier_org_id', $invite->supplier_org_id)->value('submitted_at'),
            RfqAttachment::where('rfq_id', $rfq->id)->count(),
            self::auctionPart($auction, false),
            self::awardPart($rfq->id, $invite->supplier_org_id),
        ], [$rfq->isOpenForQuotes() ? $rfq->quote_deadline : null, ...self::auctionTimes($auction)]);
    }

    /** @return array{v:string, refresh_at:?int} */
    public static function buyerIndex(int $orgId): array
    {
        $rfqs = Rfq::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')
            ->get(['id', 'status', 'quote_deadline', 'updated_at']);
        $ids = $rfqs->pluck('id');

        return self::pack([
            $rfqs->map(fn ($r) => $r->id.$r->displayStatus().$r->updated_at?->getTimestamp())->implode(','),
            RfqInvite::whereIn('rfq_id', $ids)->count(),
            Auction::withoutGlobalScopes()->whereIn('rfq_id', $ids)->get()->map(fn ($a) => self::auctionPart($a))->implode(','),
        ], $rfqs->filter(fn ($r) => $r->isOpenForQuotes())->pluck('quote_deadline')->all());
    }

    /** @return array{v:string, refresh_at:?int} */
    public static function supplierIndex(int $orgId): array
    {
        $invites = RfqInvite::with('rfq')->where('supplier_org_id', $orgId)->get();
        $auctions = Auction::withoutGlobalScopes()
            ->whereIn('id', Bid::where('supplier_org_id', $orgId)->select('auction_id'))->get();

        return self::pack([
            $invites->map(fn ($i) => $i->id.$i->status->value.($i->rfq ? $i->rfq->displayStatus().$i->rfq->updated_at?->getTimestamp() : ''))->implode(','),
            Quote::where('supplier_org_id', $orgId)->whereNotNull('submitted_at')->count(),
            $auctions->map(fn ($a) => self::auctionPart($a, false))->implode(','),
        ], [
            ...$invites->map(fn ($i) => $i->rfq?->isOpenForQuotes() ? $i->rfq->quote_deadline : null)->all(),
            ...$auctions->flatMap(fn ($a) => self::auctionTimes($a))->all(),
        ]);
    }

    private static function activeAuction(int $rfqId, ?int $participantOrgId = null): ?Auction
    {
        return Auction::withoutGlobalScopes()->where('rfq_id', $rfqId)
            ->where('status', '!=', AuctionStatus::Cancelled->value)
            ->when($participantOrgId, fn ($q) => $q->whereIn('id', Bid::where('supplier_org_id', $participantOrgId)->select('auction_id')))
            ->latest('id')->first();
    }

    private static function awardPart(int $rfqId, ?int $supplierOrgId = null): string
    {
        return \App\Models\Award::withoutGlobalScopes()->where('rfq_id', $rfqId)
            ->when($supplierOrgId, fn ($q) => $q->where('supplier_org_id', $supplierOrgId))
            ->orderBy('id')->get(['id', 'status', 'po_number', 'supplier_accepted_at'])
            ->map(fn ($a) => $a->id.$a->status->value.$a->po_number.$a->supplier_accepted_at)->implode(',') ?: '-';
    }

    private static function auctionPart(?Auction $a, bool $withPrice = true): string
    {
        if (! $a) {
            return '-';
        }

        return $a->id.':'.Standings::effectiveStatus($a)->value.($a->isPaused() ? ':p' : '').':'.$a->starts_at->getTimestamp()
            .':'.$a->ends_at->getTimestamp().($withPrice ? ':'.$a->current_l1 : '');
    }

    /** Start and end of a scheduled or live auction: moments the page must change. */
    private static function auctionTimes(?Auction $a): array
    {
        if (! $a) {
            return [];
        }

        return match (Standings::effectiveStatus($a)) {
            AuctionStatus::Scheduled => [$a->starts_at],
            AuctionStatus::Live => [$a->ends_at],
            default => [],
        };
    }

    private static function pack(array $parts, array $moments): array
    {
        $next = collect($moments)->filter(fn ($m) => $m && $m->isFuture())->min(fn ($m) => $m->getTimestampMs());

        return ['v' => substr(hash('sha256', json_encode($parts)), 0, 16), 'refresh_at' => $next];
    }
}
