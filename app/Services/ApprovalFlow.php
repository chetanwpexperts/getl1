<?php

namespace App\Services;

use App\Enums\AwardStatus;
use App\Enums\OrgRole;
use App\Models\ApprovalRule;
use App\Models\Award;
use App\Models\AwardApprovalStep;
use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Which approvals an award needs, and who may give the next one.
 *
 * With approval rules: every level whose amount or condition applies, in order. Without rules: the
 * single "approval needed from ₹X" setting on the company profile, as before. A level nobody else
 * can approve (only the person who made the award has the right) is skipped and noted.
 */
class ApprovalFlow
{
    public const DECIDER_ROLES = [OrgRole::BuyerAdmin, OrgRole::Approver];

    /**
     * The levels this award needs, worked out so that every level can really be decided:
     * a named approver gets their own level; "any approver" levels each need a different person
     * (nobody approves two levels, nor their own award). Levels that can't be staffed are left out
     * and returned in $skipped, so the award's audit entry says so.
     *
     * @param  array{amount: float, off_l1: bool, quotes: int, supplier_ids: list<int>, awarded_by: int}  $ctx
     * @return list<array{name: string, why: string, approver_user_id: ?int}>
     */
    public static function plan(Organization $buyer, array $ctx, ?array &$skipped = null): array
    {
        $skipped = [];
        $rules = ApprovalRule::withoutGlobalScopes()->where('organization_id', $buyer->id)->orderBy('position')->orderBy('id')->get();
        $pool = self::eligible($buyer->id, null, $ctx['awarded_by'])->pluck('id')->all();

        if ($rules->isEmpty()) {
            $limit = (float) ($buyer->award_approval_limit ?? 0);
            if ($pool && $buyer->users()->wherePivot('role', OrgRole::Approver->value)->exists() && $ctx['amount'] >= $limit) {
                return [['name' => 'Approval', 'why' => $limit > 0 ? 'Award from '.Money::inr($limit, 0) : 'Every award', 'approver_user_id' => null]];
            }

            return [];
        }

        $applies = [];
        $newSupplier = null;
        foreach ($rules as $r) {
            $why = [];
            if ($r->min_amount !== null && $ctx['amount'] >= (float) $r->min_amount) {
                $why[] = (float) $r->min_amount > 0 ? Money::inr($ctx['amount'], 0).' is from '.Money::inr($r->min_amount, 0) : 'Every award';
            }
            if ($r->when_not_l1 && $ctx['off_l1']) {
                $why[] = 'Not given to L1';
            }
            if ($r->when_single_quote && $ctx['quotes'] <= 1) {
                $why[] = 'Only one quote';
            }
            if ($r->when_new_supplier) {
                $newSupplier ??= self::isNewSupplier($buyer->id, $ctx['supplier_ids']);
                if ($newSupplier) {
                    $why[] = 'First order with this supplier';
                }
            }
            if ($why) {
                $named = $r->approver_user_id && in_array($r->approver_user_id, $pool, true) ? (int) $r->approver_user_id : null;
                if ($r->approver_user_id && ! $named) {
                    $why[] = 'named approver unavailable, so any approver';
                }
                $applies[] = ['name' => $r->name, 'why' => $why, 'approver_user_id' => $named];
            }
        }

        // A person named on two levels decides only the first of them.
        $named = [];
        foreach ($applies as $i => $a) {
            if ($a['approver_user_id']) {
                if (isset($named[$a['approver_user_id']])) {
                    $skipped[] = $a['name'].' (same approver as an earlier level)';
                    unset($applies[$i]);
                    continue;
                }
                $named[$a['approver_user_id']] = true;
            }
        }
        // "Any approver" levels need different people, none of them named on another level.
        $free = count(array_diff($pool, array_keys($named)));
        foreach ($applies as $i => $a) {
            if (! $a['approver_user_id']) {
                if ($free <= 0) {
                    $skipped[] = $a['name'].' (not enough different approvers)';
                    unset($applies[$i]);
                    continue;
                }
                $free--;
            }
        }

        return array_values(array_map(fn ($a) => ['name' => $a['name'], 'why' => mb_substr(implode(' · ', $a['why']), 0, 255), 'approver_user_id' => $a['approver_user_id']], $applies));
    }

    /** Create the steps for a new award (the first award of a split carries them). */
    public static function start(Award $lead, array $plan): void
    {
        foreach (array_values($plan) as $i => $s) {
            AwardApprovalStep::create([
                'organization_id' => $lead->organization_id, 'award_id' => $lead->id, 'group_key' => $lead->group_key,
                'position' => $i + 1, 'name' => $s['name'], 'why' => $s['why'], 'approver_user_id' => $s['approver_user_id'],
                'status' => AwardApprovalStep::PENDING,
            ]);
        }
    }

    /** All steps of an award or its split, in order. */
    public static function steps(Award $award): Collection
    {
        $q = AwardApprovalStep::withoutGlobalScopes()->with(['approver:id,name', 'decider:id,name']);
        $award->group_key ? $q->where('group_key', $award->group_key) : $q->where('award_id', $award->id);

        return $q->orderBy('position')->get();
    }

    public static function current(Award $award): ?AwardApprovalStep
    {
        return self::steps($award)->firstWhere('status', AwardApprovalStep::PENDING);
    }

    /** People who may decide a step: the named person, or anyone with approval rights; never the awarder. */
    public static function eligible(int $orgId, ?int $namedUserId, int $awardedBy): Collection
    {
        $q = Organization::findOrFail($orgId)->users()
            ->wherePivotIn('role', array_map(fn ($r) => $r->value, self::DECIDER_ROLES))
            ->where('users.id', '!=', $awardedBy)->whereNull('users.locked_at');
        if ($namedUserId) {
            $q->where('users.id', $namedUserId);
        }

        return $q->get();
    }

    /**
     * Who may approve the award's current level right now: the named person; or, for "any
     * approver" levels, approvers who haven't decided an earlier level and aren't named on a later
     * one. If that leaves nobody (people left or lost the role), the company's admins take over, so
     * an award never gets stuck.
     */
    public static function approversNow(Award $award): Collection
    {
        if (! $award->isPending()) {
            return collect();
        }
        $steps = self::steps($award);
        $step = $steps->firstWhere('status', AwardApprovalStep::PENDING);
        $all = self::eligible($award->organization_id, null, $award->awarded_by);
        if (! $step) {
            return $all; // awards made before approval levels existed
        }
        $done = $steps->where('status', AwardApprovalStep::APPROVED)->pluck('decided_by')->filter()->all();
        $later = $steps->where('status', AwardApprovalStep::PENDING)->where('position', '>', $step->position)->pluck('approver_user_id')->filter()->all();
        $notDone = $all->reject(fn ($u) => in_array($u->id, $done, true));

        $people = $step->approver_user_id
            ? $notDone->where('id', $step->approver_user_id)
            : $notDone->reject(fn ($u) => in_array($u->id, $later, true));
        if ($people->isNotEmpty()) {
            return $people->values();
        }
        $admins = fn ($c) => $c->filter(fn ($u) => $u->hasRoleIn($award->organization_id, OrgRole::BuyerAdmin));

        return ($admins($notDone)->isNotEmpty() ? $admins($notDone) : $admins($all))->values();
    }

    public static function canDecide(Award $award, User $user): bool
    {
        return $award->isPending() && $award->awarded_by !== $user->id && self::approversNow($award)->contains('id', $user->id);
    }

    /** Any admin (other than the person who made it) can always turn an award down: nothing stays stuck. */
    public static function canReject(Award $award, User $user): bool
    {
        return self::canDecide($award, $user)
            || ($award->isPending() && $award->awarded_by !== $user->id && $user->hasRoleIn($award->organization_id, OrgRole::BuyerAdmin));
    }

    private static function isNewSupplier(int $buyerId, array $supplierIds): bool
    {
        foreach ($supplierIds as $id) {
            if (! Award::withoutGlobalScopes()->where('organization_id', $buyerId)->where('supplier_org_id', $id)->where('status', AwardStatus::PoSent->value)->exists()) {
                return true;
            }
        }

        return false;
    }
}
