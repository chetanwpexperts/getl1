<?php

namespace App\Services;

use App\Enums\OrganizationType;
use App\Enums\OrgRole;
use App\Enums\SubscriptionStatus;
use App\Models\BuyerSupplier;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OrganizationService
{
    public const TRIAL_DAYS = 14;

    /**
     * Create an organization with the given user as owner.
     * Buyers get a 30-day trial subscription. Suppliers are always free.
     */
    public function createWithOwner(User $owner, array $attributes, OrganizationType $type): Organization
    {
        return DB::transaction(function () use ($owner, $attributes, $type) {
            $org = Organization::create($attributes + ['type' => $type]);

            $role = $type === OrganizationType::Buyer ? OrgRole::BuyerAdmin : OrgRole::SupplierUser;
            $org->users()->attach($owner->id, ['role' => $role->value, 'is_owner' => true]);

            $owner->forceFill(['current_organization_id' => $org->id])->save();

            if ($type === OrganizationType::Buyer) {
                $this->startTrial($org);
            }

            if ($type === OrganizationType::Supplier) {
                $this->linkPendingListEntries($org, $owner);
            }

            return $org;
        });
    }

    public function startTrial(Organization $org): void
    {
        // New buyers try the full Growth plan; afterwards they continue on Free unless they subscribe.
        $plan = Plan::where('code', \App\Services\Billing\PlanService::TRIAL_PLAN)->first();

        if (! $plan) {
            return; // plans not seeded yet (e.g. fresh install); trial can be added later
        }

        $org->subscriptions()->create([
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Trialing,
            'trial_ends_at' => now()->addDays(self::TRIAL_DAYS),
        ]);
    }

    /**
     * When a supplier registers with an email/phone that buyers already added to their
     * private lists, connect those list entries to the new supplier org.
     */
    public function linkPendingListEntries(Organization $supplier, User $owner): int
    {
        return BuyerSupplier::query()
            ->whereNull('supplier_org_id')
            ->where(function ($q) use ($owner, $supplier) {
                $q->where('contact_email', $owner->email);
                if ($owner->phone) {
                    $q->orWhere('contact_phone', $owner->phone);
                }
                // Company name alone is never used to link: two firms can share a name.
            })
            ->update(['supplier_org_id' => $supplier->id]);
    }

    public function addMember(Organization $org, User $user, OrgRole $role): void
    {
        abort_unless(in_array($role, OrgRole::forType($org->type), true), 422, 'Role not allowed for this organization type.');

        $org->users()->syncWithoutDetaching([$user->id => ['role' => $role->value, 'is_owner' => false]]);

        if (! $user->current_organization_id) {
            $user->forceFill(['current_organization_id' => $org->id])->save();
        }
    }
}
