<?php

namespace App\Services\Billing;

use App\Enums\AuctionStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Auction;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Support\Carbon;

/**
 * Which plan a buyer company is on right now, and what it may do.
 *
 * - Active (or briefly past due) subscription → that plan.
 * - Trial running → the trial plan (Growth).
 * - Otherwise → Free. Nobody is ever locked out; they just get Free limits.
 * If plans haven't been set up at all, no limits apply (fresh install / tests).
 */
class PlanService
{
    public const TRIAL_PLAN = 'growth';
    public const FREE_PLAN = 'free';
    public const GRACE_DAYS = 3; // a failed renewal keeps the plan this long while Razorpay retries

    public function current(Organization $org): ?Plan
    {
        $sub = $this->liveSubscription($org);

        return $sub?->plan ?? Plan::where('code', self::FREE_PLAN)->first();
    }

    /** The subscription that currently gives this company its plan, if any. */
    public function liveSubscription(Organization $org): ?Subscription
    {
        $subs = Subscription::with('plan')->where('organization_id', $org->id)
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value, SubscriptionStatus::Trialing->value])
            ->latest('id')->get();

        foreach ($subs as $sub) {
            if ($sub->status === SubscriptionStatus::Trialing && $sub->trial_ends_at?->isFuture()) {
                return $sub;
            }
            if ($sub->status === SubscriptionStatus::Active
                && ($sub->current_period_end === null || $sub->current_period_end->copy()->addDays(self::GRACE_DAYS)->isFuture())) {
                return $sub;
            }
            if ($sub->status === SubscriptionStatus::PastDue
                && ($sub->current_period_end === null || $sub->current_period_end->copy()->addDays(self::GRACE_DAYS)->isFuture())) {
                return $sub;
            }
        }

        return null;
    }

    public function hasFeature(Organization $org, string $feature): bool
    {
        $plan = $this->current($org);

        return $plan === null || in_array($feature, $plan->features ?? [], true);
    }

    /** Start of the current month in India, as UTC. */
    public function monthStart(): Carbon
    {
        return now()->setTimezone(config('app.display_timezone'))->startOfMonth()->utc();
    }

    /** Live auctions scheduled this month that count against the plan (cancelled and credit-paid ones don't). */
    public function auctionsUsed(Organization $org): int
    {
        return Auction::withoutGlobalScopes()->where('organization_id', $org->id)
            ->where('created_at', '>=', $this->monthStart())
            ->where('status', '!=', AuctionStatus::Cancelled->value)
            ->where('paid_with_credit', false)
            ->count();
    }

    /** @return array{plan: ?Plan, limit: ?int, used: int, left: ?int, credits: int} */
    public function auctionAllowance(Organization $org): array
    {
        $plan = $this->current($org);
        $limit = $plan?->max_auctions_month;
        $used = $this->auctionsUsed($org);

        return [
            'plan' => $plan,
            'limit' => $limit,
            'used' => $used,
            'left' => $plan === null || $limit === null ? null : max(0, $limit - $used),
            'credits' => (int) $org->auction_credits,
        ];
    }
}
