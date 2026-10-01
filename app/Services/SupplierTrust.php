<?php

namespace App\Services;

use App\Enums\InviteStatus;
use App\Models\Award;
use App\Models\Bid;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * A supplier's track record on GetL1, from what actually happened (no ratings typed in by hand):
 * do they answer invitations, take part in auctions, accept the purchase orders they win,
 * and are they verified. Score 0–100; "new" until there is enough history to judge.
 */
class SupplierTrust
{
    public const MIN_INVITES = 3;

    /** @return array{score: ?int, label: string, invited: int, quoted: int, declined: int, response_rate: ?float, auctions: int, active_in_auctions: int, auction_activity: ?float, won: int, po_sent: int, po_accepted: int, po_acceptance: ?float, avg_accept_hours: ?float, verified: bool} */
    public function for(Organization $supplier): array
    {
        $id = $supplier->id;
        $invited = DB::table('rfq_invites')->where('supplier_org_id', $id)->count();
        $declined = DB::table('rfq_invites')->where('supplier_org_id', $id)->where('status', InviteStatus::Declined->value)->count();
        $quoted = DB::table('quotes')->where('supplier_org_id', $id)->whereNotNull('submitted_at')->count();
        $auctions = Bid::where('supplier_org_id', $id)->distinct()->count('auction_id');
        $active = Bid::where('supplier_org_id', $id)->where('kind', Bid::KIND_LIVE)->distinct()->count('auction_id');
        $awards = Award::withoutGlobalScopes()->where('supplier_org_id', $id)->whereNotNull('po_sent_at');
        $poSent = (clone $awards)->count();
        $accepted = (clone $awards)->whereNotNull('supplier_accepted_at')->get(['po_sent_at', 'supplier_accepted_at']);

        $rate = fn (int $a, int $b) => $b > 0 ? round($a / $b * 100, 1) : null;
        $response = $rate($quoted, $invited);
        $activity = $rate($active, $auctions);
        $acceptance = $rate($accepted->count(), $poSent);
        $avgAccept = $accepted->isNotEmpty()
            ? round($accepted->avg(fn ($a) => $a->po_sent_at->diffInMinutes($a->supplier_accepted_at, true)) / 60, 1) : null;
        $verified = $supplier->verified_at !== null;

        $score = null;
        if ($invited >= self::MIN_INVITES) {
            // Weights: answering invitations 35, accepting POs 30, verified 20, active in auctions 15.
            // A part with no history yet counts as neutral (half marks), so nobody is punished for being new.
            $part = fn (?float $pct, int $w) => ($pct ?? 50) / 100 * $w;
            $score = (int) round($part($response, 35) + $part($acceptance, 30) + ($verified ? 20 : 0) + $part($activity, 15));
        }

        return [
            'score' => $score,
            'label' => $score === null ? 'New' : ($score >= 80 ? 'Excellent' : ($score >= 60 ? 'Good' : ($score >= 40 ? 'Fair' : 'Low'))),
            'invited' => $invited, 'quoted' => $quoted, 'declined' => $declined, 'response_rate' => $response,
            'auctions' => $auctions, 'active_in_auctions' => $active, 'auction_activity' => $activity,
            'won' => Award::withoutGlobalScopes()->where('supplier_org_id', $id)->whereNotNull('po_number')->count(),
            'po_sent' => $poSent, 'po_accepted' => $accepted->count(), 'po_acceptance' => $acceptance, 'avg_accept_hours' => $avgAccept,
            'verified' => $verified,
        ];
    }
}
