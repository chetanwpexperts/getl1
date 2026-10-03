<?php

namespace App\Services\Suppliers;

use App\Enums\AwardStatus;
use App\Models\Award;
use App\Models\GoodsReceipt;
use App\Models\Organization;
use App\Models\Quote;
use App\Models\RfqInvite;
use App\Models\SupplierInvoice;
use App\Support\IndianIds;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * What a buyer needs to judge a supplier: registration checks worked out from its details, and a
 * scorecard built from this buyer's own dealings with it over the last 12 months (quotes, POs,
 * deliveries, rejections, invoices). Nothing is shared between buyers.
 */
class SupplierProfile
{
    public const MONTHS = 12;

    /** Weights of each part of the score; parts without data are left out and the rest re-weighted. */
    public const WEIGHTS = ['delivery' => 35, 'quality' => 30, 'response' => 15, 'acceptance' => 10, 'invoices' => 10];

    /**
     * Registration checks. status: ok | warn | missing.
     *
     * @return list<array{key: string, label: string, status: string, detail: string}>
     */
    public static function checks(Organization $s): array
    {
        $gstin = strtoupper(trim((string) $s->gstin));
        $validGstin = $gstin !== '' && IndianIds::isValidGstin($gstin);
        $checks = [];

        $checks[] = match (true) {
            $gstin === '' => ['key' => 'gstin', 'label' => 'GSTIN', 'status' => 'missing', 'detail' => 'Not added to the supplier’s profile yet'],
            ! $validGstin => ['key' => 'gstin', 'label' => 'GSTIN', 'status' => 'warn', 'detail' => "{$gstin} is not a valid GSTIN (the check digit doesn’t match)"],
            default => ['key' => 'gstin', 'label' => 'GSTIN', 'status' => 'ok', 'detail' => "{$gstin} · format and check digit correct"],
        };

        if ($validGstin) {
            $state = IndianIds::gstinState($gstin);
            $checks[] = ! $s->state
                ? ['key' => 'state', 'label' => 'GST state', 'status' => 'missing', 'detail' => "GSTIN is registered in {$state}; no state on the profile"]
                : (IndianIds::sameState($state, $s->state)
                    ? ['key' => 'state', 'label' => 'GST state', 'status' => 'ok', 'detail' => "{$state}, same as the address"]
                    : ['key' => 'state', 'label' => 'GST state', 'status' => 'warn', 'detail' => "GSTIN is from {$state}, address says {$s->state}. Check the place of supply for GST"]);
        }

        $pan = strtoupper(trim((string) $s->pan));
        $checks[] = match (true) {
            $pan === '' && $validGstin => ['key' => 'pan', 'label' => 'PAN', 'status' => 'ok', 'detail' => IndianIds::panFromGstin($gstin).' (from the GSTIN)'],
            $pan === '' => ['key' => 'pan', 'label' => 'PAN', 'status' => 'missing', 'detail' => 'Not added yet'],
            ! IndianIds::isValidPan($pan) => ['key' => 'pan', 'label' => 'PAN', 'status' => 'warn', 'detail' => "{$pan} is not a valid PAN"],
            $validGstin && IndianIds::panFromGstin($gstin) !== $pan => ['key' => 'pan', 'label' => 'PAN', 'status' => 'warn', 'detail' => "{$pan} doesn’t match the PAN inside the GSTIN (".IndianIds::panFromGstin($gstin).')'],
            default => ['key' => 'pan', 'label' => 'PAN', 'status' => 'ok', 'detail' => $pan.($validGstin ? ', matches the GSTIN' : '')],
        };

        $udyam = strtoupper(trim((string) $s->udyam_no));
        if ($udyam !== '') {
            $checks[] = IndianIds::isValidUdyam($udyam)
                ? ['key' => 'udyam', 'label' => 'MSME (Udyam)', 'status' => 'ok', 'detail' => "{$udyam}. Pay within 45 days of accepting goods"]
                : ['key' => 'udyam', 'label' => 'MSME (Udyam)', 'status' => 'warn', 'detail' => "{$udyam} is not in the Udyam format"];
        }

        $checks[] = $s->verified_at
            ? ['key' => 'kyc', 'label' => 'Documents', 'status' => 'ok', 'detail' => 'Checked by GetL1 on '.$s->verified_at->ist()->format('d M Y')]
            : ['key' => 'kyc', 'label' => 'Documents', 'status' => 'missing', 'detail' => 'Not yet checked by GetL1'];

        return $checks;
    }

    /** ok when every check is ok; warn when any is wrong; missing otherwise. */
    public static function checksSummary(array $checks): string
    {
        $st = array_column($checks, 'status');

        return in_array('warn', $st, true) ? 'warn' : (in_array('missing', $st, true) ? 'missing' : 'ok');
    }

    /** Cached for an hour: used in lists. The supplier page always works it out fresh. */
    public static function cachedScore(int $buyerId, int $supplierId): array
    {
        return Cache::remember("supplier_score:{$buyerId}:{$supplierId}", 3600, fn () => self::score($buyerId, $supplierId));
    }

    /**
     * @return array{score: ?int, grade: ?string, parts: array<string, array{score: ?int, label: string, detail: string}>, pos: int}
     */
    public static function score(int $buyerId, int $supplierId): array
    {
        $since = now()->subMonths(self::MONTHS);
        $tz = config('app.display_timezone');

        // Response: RFQs it was invited to vs quoted on.
        // Only RFQs whose quote deadline has passed: nobody is marked down while an RFQ is still open.
        $closedRfqs = fn ($q) => $q->where('rfqs.organization_id', $buyerId)->whereNotNull('rfqs.published_at')
            ->where('rfqs.published_at', '>=', $since)->where('rfqs.quote_deadline', '<=', now());
        $invited = RfqInvite::query()->join('rfqs', 'rfqs.id', '=', 'rfq_invites.rfq_id')->where($closedRfqs)
            ->where('rfq_invites.supplier_org_id', $supplierId)->count();
        $quoted = Quote::query()->join('rfqs', 'rfqs.id', '=', 'quotes.rfq_id')->where($closedRfqs)
            ->where('quotes.supplier_org_id', $supplierId)->whereNotNull('quotes.submitted_at')->count();

        $pos = Award::withoutGlobalScopes()->where('organization_id', $buyerId)->where('supplier_org_id', $supplierId)
            ->where('status', AwardStatus::PoSent->value)->where('po_sent_at', '>=', $since)->get();
        $grns = GoodsReceipt::withoutGlobalScopes()->whereIn('award_id', $pos->pluck('id'))->get()->groupBy('award_id');
        $invoices = SupplierInvoice::withoutGlobalScopes()->whereIn('award_id', $pos->pluck('id'))->get();

        // PO acceptance: hours from PO to the supplier accepting it.
        $accepted = $pos->filter(fn ($a) => $a->supplier_accepted_at);
        $avgHours = $accepted->isNotEmpty() ? $accepted->avg(fn ($a) => max(0, $a->po_sent_at->diffInMinutes($a->supplier_accepted_at)) / 60) : null;

        // Delivery: complete by the latest "needed by" date on the PO. POs without dates are left out.
        $today = now($tz)->toDateString();
        $onTime = $late = 0;
        $received = $rejected = 0.0;
        foreach ($pos as $a) {
            $due = collect($a->lines['items'] ?? [])->pluck('needed_by')->filter()->max();
            $receipts = $grns->get($a->id, collect())->sortBy(fn ($g) => $g->received_on->toDateString().'|'.str_pad((string) $g->id, 12, '0', STR_PAD_LEFT));
            foreach ($receipts as $g) {
                foreach ($g->lines ?? [] as $l) {
                    $received += (float) ($l['received'] ?? 0);
                    $rejected += (float) ($l['rejected'] ?? 0);
                }
            }
            if (! $due) {
                continue;
            }
            // Delivered on the day the last ordered quantity arrived, counting accepted goods only:
            // rejected goods have to be replaced before the order is really delivered.
            $ordered = collect($a->lines['items'] ?? [])->mapWithKeys(fn ($l) => [(int) $l['rfq_item_id'] => (float) $l['qty']]);
            $got = [];
            $doneOn = null;
            foreach ($receipts as $g) {
                foreach ($g->lines ?? [] as $l) {
                    $id = (int) ($l['rfq_item_id'] ?? 0);
                    $got[$id] = ($got[$id] ?? 0) + (float) ($l['accepted'] ?? max(0, (float) ($l['received'] ?? 0) - (float) ($l['rejected'] ?? 0)));
                }
                if ($ordered->every(fn ($qty, $id) => ($got[$id] ?? 0) + 0.0005 >= $qty)) {
                    $doneOn = $g->received_on->toDateString();
                    break;
                }
            }
            $complete = $doneOn !== null;
            if ($complete && $doneOn <= $due) {
                $onTime++;
            } elseif ($complete || $today > $due) {
                $late++; // finished late, or still open after the date
            }
        }

        $parts = [];
        $rate = fn ($a, $b) => $b > 0 ? (int) round($a / $b * 100) : null;

        $d = $rate($onTime, $onTime + $late);
        $parts['delivery'] = ['score' => $d, 'label' => 'On-time delivery',
            'detail' => $d === null ? 'No deliveries with a due date yet' : "{$onTime} of ".($onTime + $late).' POs delivered in full by the date needed'];

        $rejPct = $received > 0 ? $rejected / $received * 100 : null;
        $parts['quality'] = ['score' => $rejPct === null ? null : (int) max(0, min(100, round(100 - $rejPct * 5))), 'label' => 'Quality',
            'detail' => $rejPct === null ? 'No goods received yet' : rtrim(rtrim(number_format($rejPct, 1), '0'), '.').'% of goods received were rejected'];

        $r = $invited > 0 ? (int) min(100, $rate($quoted, $invited)) : null;
        $parts['response'] = ['score' => $r, 'label' => 'Quote response',
            'detail' => $r === null ? 'Not invited to any RFQ yet' : "Quoted on {$quoted} of {$invited} RFQs"];

        $parts['acceptance'] = ['score' => $avgHours === null ? null : (int) max(30, min(100, round(100 - max(0, $avgHours - 24) * 0.8))), 'label' => 'PO acceptance',
            'detail' => $avgHours === null ? 'No POs accepted yet' : 'Accepts POs in '.self::hours($avgHours).' on average'];

        $disputed = $invoices->where('status', SupplierInvoice::DISPUTED)->count();
        $parts['invoices'] = ['score' => $invoices->isEmpty() ? null : (int) max(0, round(100 - $disputed / $invoices->count() * 200)), 'label' => 'Invoice accuracy',
            'detail' => $invoices->isEmpty() ? 'No invoices yet' : "{$disputed} of {$invoices->count()} ".($invoices->count() === 1 ? 'invoice' : 'invoices').' disputed'];

        // Only scored once there is a delivery to judge: a score from quotes and PO clicks alone would mislead.
        $total = null;
        if ($pos->isNotEmpty() && ($parts['delivery']['score'] !== null || $parts['quality']['score'] !== null)) {
            $sum = $w = 0;
            foreach (self::WEIGHTS as $k => $weight) {
                if ($parts[$k]['score'] !== null) {
                    $sum += $parts[$k]['score'] * $weight;
                    $w += $weight;
                }
            }
            $total = $w > 0 ? (int) round($sum / $w) : null;
        }

        return ['score' => $total, 'grade' => self::grade($total), 'parts' => $parts, 'pos' => $pos->count()];
    }

    public static function grade(?int $score): ?string
    {
        return match (true) {
            $score === null => null,
            $score >= 85 => 'Excellent',
            $score >= 70 => 'Good',
            $score >= 50 => 'Fair',
            default => 'Poor',
        };
    }

    private static function hours(float $h): string
    {
        return $h < 1 ? 'under an hour' : ($h < 48 ? round($h).' '.(round($h) == 1 ? 'hour' : 'hours') : round($h / 24, 1).' days');
    }
}
