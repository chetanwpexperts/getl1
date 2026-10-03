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
     * @param  array{amount: float, off_l1: bool, quotes: int, supplier_ids: list<int>, awarded_by: int}  $ctx
     * @return list<array{name: string, why: string, approver_user_id: ?int}>
     */
    public static function plan(Organization $buyer, array $ctx): array
    {
        $rules = ApprovalRule::withoutGlobalScopes()->where('organization_id', $buyer->id)->orderBy('position')->orderBy('id')->get();
        $steps = [];

        if ($rules->isEmpty()) {
            $limit = (float) ($buyer->award_approval_limit ?? 0);
            if (self::eligible($buyer->id, null, $ctx['awarded_by'])->isNotEmpty()
                && $buyer->users()->wherePivot('role', OrgRole::Approver->value)->exists() && $ctx['amount'] >= $limit) {
                $steps[] = ['name' => 'Approval', 'why' => $limit > 0 ? 'Award from '.Money::inr($limit, 0) : 'Every award', 'approver_user_id' => null];
            }

            return $steps;
        }

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
            if (! $why) {
                continue;
            }
            // A named approver who has left or lost approval rights: anyone with approval rights instead.
            $named = $r->approver_user_id && User::find($r->approver_user_id)?->hasRoleIn($buyer->id, ...self::DECIDER_ROLES) ? $r->approver_user_id : null;
            if (self::eligible($buyer->id, $named, $ctx['awarded_by'])->isEmpty()) {
                $named = null; // the named person made the award: anyone else with rights
                if (self::eligible($buyer->id, null, $ctx['awarded_by'])->isEmpty()) {
                    continue; // nobody else can approve: skipped (recorded in the award's audit entry)
                }
            }
            $steps[] = ['name' => $r->name, 'why' => mb_substr(implode(' · ', $why), 0, 255), 'approver_user_id' => $named];
        }

        return $steps;
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

    /** Who may approve the award right now. */
    public static function approversNow(Award $award): Collection
    {
        $step = self::current($award);
        if (! $step) {
            return $award->isPending() ? self::eligible($award->organization_id, null, $award->awarded_by) : collect(); // older awards without steps
        }
        $done = self::steps($award)->where('status', AwardApprovalStep::APPROVED)->pluck('decided_by')->all();

        // Someone who approved an earlier level doesn't approve a later one too.
        $people = self::eligible($award->organization_id, $step->approver_user_id, $award->awarded_by)->reject(fn ($u) => in_array($u->id, $done, true));

        return $people->isEmpty() && $step->approver_user_id
            ? self::eligible($award->organization_id, null, $award->awarded_by)->reject(fn ($u) => in_array($u->id, $done, true))
            : $people->values();
    }

    public static function canDecide(Award $award, User $user): bool
    {
        return $award->isPending() && $award->awarded_by !== $user->id && self::approversNow($award)->contains('id', $user->id);
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
