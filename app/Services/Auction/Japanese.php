<?php

namespace App\Services\Auction;

use App\Models\Auction;
use App\Models\Bid;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Japanese (falling-price) reverse auction, worked out from the server clock and the recorded
 * acceptances alone, so the result never depends on a background job running on time.
 *
 * - Round n lasts round_seconds; its price is opening − (n−1) × drop, never below the floor
 *   (opening − max_decrement_pct %).
 * - Every participant is in for round 1. To stay in, a supplier accepts the round's price before
 *   the round ends; not accepting means dropping out for good.
 * - It ends when a round finishes with one or no acceptance, or when the floor round finishes.
 *   Winner: the last supplier standing; if nobody accepted the last round, those who accepted the
 *   round before, by who accepted first; at the floor, whoever accepted it first.
 * - Pauses by GetL1 stop the round clock (paused_seconds), so nobody loses round time.
 */
class Japanese
{
    public const ROUND_SECONDS = [30, 45, 60, 90, 120];

    public static function step(Auction $a): float
    {
        $opening = (float) $a->opening_price;

        return $a->min_decrement_type === 'percent'
            ? max(0.01, round($opening * (float) $a->min_decrement_value / 100, 2))
            : max(0.01, (float) $a->min_decrement_value);
    }

    public static function floor(Auction $a): float
    {
        return round((float) $a->opening_price * (1 - (float) $a->max_decrement_pct / 100), 2);
    }

    /** Rounds until the price reaches the floor (the floor round is the last). */
    public static function maxRounds(Auction $a): int
    {
        $span = (float) $a->opening_price - self::floor($a);

        return max(1, (int) ceil(round($span / self::step($a), 6)) + 1);
    }

    public static function price(Auction $a, int $round): float
    {
        return max(self::floor($a), round((float) $a->opening_price - ($round - 1) * self::step($a), 2));
    }

    /** Seconds of round clock elapsed (stops while paused). */
    public static function clock(Auction $a, ?CarbonInterface $now = null): float
    {
        $at = $a->paused_at ?? ($now ?? now());

        return max(0.0, ($at->getTimestampMs() - $a->starts_at->getTimestampMs()) / 1000 - (int) $a->paused_seconds);
    }

    /** The round the clock is in (may be past the last round once everything has run out). */
    public static function roundAt(Auction $a, ?CarbonInterface $now = null): int
    {
        return (int) floor(self::clock($a, $now) / max(1, (int) $a->round_seconds)) + 1;
    }

    /** When a round ends, on the current clock (valid for the running round and later ones). */
    public static function roundEndsAt(Auction $a, int $round): Carbon
    {
        return Carbon::createFromTimestampMs($a->starts_at->getTimestampMs() + ((int) $a->paused_seconds + $round * (int) $a->round_seconds) * 1000);
    }

    /**
     * @return array{round:int, price:float, round_ends_at:Carbon, in:Collection, accepted:Collection,
     *               finished:bool, final_round:?int, finished_at:?Carbon, standings:Collection}
     */
    public static function state(Auction $a, ?CarbonInterface $now = null): array
    {
        $participants = Bid::where('auction_id', $a->id)->where('kind', Bid::KIND_SEALED)
            ->orderBy('created_at')->orderBy('id')->get(['supplier_org_id', 'amount', 'created_at'])->keyBy('supplier_org_id');
        $accepts = Bid::where('auction_id', $a->id)->where('kind', Bid::KIND_LIVE)->whereNotNull('round')
            ->orderBy('created_at')->orderBy('id')->get(['supplier_org_id', 'round', 'amount', 'created_at']);
        $byRound = $accepts->groupBy('round');

        $max = self::maxRounds($a);
        $current = min(self::roundAt($a, $now), $max + 1);
        $in = $participants->keys()->values();
        $finishedRound = null;

        if ($a->starts_at->lte($now ?? now())) {
            // Walk the rounds that have ended.
            for ($k = 1; $k < $current; $k++) {
                $acceptedK = ($byRound[$k] ?? collect())->pluck('supplier_org_id')->intersect($in)->values();
                if ($acceptedK->count() <= 1 || $k === $max) {
                    $finishedRound = $k;
                    break;
                }
                $in = $acceptedK;
            }
        }

        $round = $finishedRound ?? min($current, $max);
        $acceptedNow = ($byRound[$round] ?? collect())->pluck('supplier_org_id')->values();

        return [
            'round' => $round,
            'price' => self::price($a, $round),
            'round_ends_at' => self::roundEndsAt($a, $round),
            'in' => $in,
            'accepted' => $acceptedNow,
            'finished' => $finishedRound !== null,
            'final_round' => $finishedRound,
            'finished_at' => $finishedRound !== null ? self::roundEndsAt($a, $finishedRound) : null,
            'standings' => self::rank($a, $participants, $accepts),
        ];
    }

    /**
     * Ranking: furthest round accepted first; within a round, who accepted first. Each supplier's
     * price is the last round price it accepted (its sealed quote if it never accepted one).
     */
    private static function rank(Auction $a, Collection $participants, Collection $accepts): Collection
    {
        $last = $accepts->groupBy('supplier_org_id')->map(fn ($rows) => $rows->sortBy('round')->last());

        return $participants->map(function ($sealed, $orgId) use ($last, $accepts) {
            $l = $last[$orgId] ?? null;

            return [
                'supplier_org_id' => (int) $orgId,
                'amount' => $l ? (float) $l->amount : (float) $sealed->amount,
                'at' => $l ? $l->created_at : $sealed->created_at,
                'round' => $l ? (int) $l->round : 0,
                'bids' => $accepts->where('supplier_org_id', $orgId)->count(),
            ];
        })
            ->sort(fn ($x, $y) => [$y['round'], $x['round'] === 0 ? $x['amount'] : 0, $x['at']->format('U.u')]
                <=> [$x['round'], $y['round'] === 0 ? $y['amount'] : 0, $y['at']->format('U.u')])
            ->values()
            ->map(fn ($row, $i) => $row + ['rank' => $i + 1]);
    }
}
