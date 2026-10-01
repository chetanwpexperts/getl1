<?php

namespace App\Services\Billing;

use App\Enums\OrgRole;
use App\Enums\SubscriptionStatus;
use App\Mail\PaymentReceiptMail;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Subscriptions and auction credits through Razorpay.
 *
 * Money is only ever credited on proof from Razorpay: a valid checkout signature or a valid
 * webhook. Recording a payment is idempotent (unique Razorpay payment id + row lock), so the
 * browser callback and the webhook can both arrive, in any order, any number of times.
 */
class BillingService
{
    public function __construct(private Razorpay $razorpay, private InvoiceService $invoices, private AuditLogger $audit) {}

    /** @return array{amount: float, gst_rate: float, gst: float, total: float} */
    public function price(float $amount): array
    {
        $rate = config('billing.gst_enabled') ? (float) config('billing.gst_rate') : 0.0;
        $gst = Money::round($amount * $rate / 100);

        return ['amount' => Money::round($amount), 'gst_rate' => $rate, 'gst' => $gst, 'total' => Money::round($amount + $gst)];
    }

    // ---------------------------------------------------------------- subscriptions

    /** Create a Razorpay subscription and return what the browser needs to open checkout. */
    public function startSubscription(Organization $org, User $by, Plan $plan, string $cycle): array
    {
        $this->assertCanBuy();
        if (! $plan->is_active || $plan->contact_sales || (float) $plan->price_monthly <= 0 || ! in_array($cycle, ['monthly', 'yearly'], true)) {
            throw ValidationException::withMessages(['plan' => 'This plan can’t be bought online. Please contact us.']);
        }

        $rzpPlanId = $this->razorpayPlanId($plan, $cycle);
        $rzp = $this->razorpay->createSubscription($rzpPlanId, (int) config("billing.cycles.{$cycle}"), [
            'organization_id' => (string) $org->id, 'plan' => $plan->code, 'cycle' => $cycle,
        ]);

        // Drop earlier unpaid attempts so they never become active by accident.
        Subscription::where('organization_id', $org->id)->where('status', SubscriptionStatus::Pending->value)
            ->update(['status' => SubscriptionStatus::Expired->value]);

        Subscription::create([
            'organization_id' => $org->id, 'plan_id' => $plan->id, 'created_by' => $by->id, 'billing_cycle' => $cycle,
            'razorpay_subscription_id' => $rzp['id'], 'status' => SubscriptionStatus::Pending,
        ]);
        $this->audit->log('subscription_checkout_started', $org, after: ['plan' => $plan->code, 'cycle' => $cycle], user: $by, organizationId: $org->id);

        $price = $this->price((float) ($cycle === 'yearly' ? $plan->price_yearly : $plan->price_monthly));

        return $this->checkout($org, $by, [
            'subscription_id' => $rzp['id'],
            'description' => "{$plan->name} plan · ".($cycle === 'yearly' ? 'yearly' : 'monthly'),
            'amount_label' => Money::inr($price['total']).($cycle === 'yearly' ? '/year' : '/month'),
        ]);
    }

    /** Browser came back from checkout with a signed subscription payment. */
    public function confirmSubscription(Organization $org, string $paymentId, string $subscriptionId, string $signature): Subscription
    {
        if (! $this->razorpay->validSubscriptionSignature($paymentId, $subscriptionId, $signature)) {
            \App\Services\SecurityLog::warning('billing_bad_signature', ['organization_id' => $org->id, 'kind' => 'subscription']);
            throw ValidationException::withMessages(['payment' => 'We couldn’t verify this payment. If money was deducted, it will be refunded automatically or reflect within a few minutes.']);
        }

        $sub = Subscription::where('organization_id', $org->id)->where('razorpay_subscription_id', $subscriptionId)->firstOrFail();
        $this->activate($sub, now(), null);
        $this->recordSubscriptionPayment($sub, $paymentId, now(), null);

        return $sub->fresh();
    }

    /** Stop renewing at the end of the paid period. The plan stays until then. */
    public function cancelSubscription(Organization $org, User $by): ?Subscription
    {
        $sub = app(PlanService::class)->liveSubscription($org);
        if (! $sub || $sub->status === SubscriptionStatus::Trialing || ! $sub->razorpay_subscription_id) {
            return null;
        }
        $this->razorpay->cancelSubscription($sub->razorpay_subscription_id, true);
        $sub->update(['cancel_at_period_end' => true]);
        $this->audit->log('subscription_cancel_requested', $sub, user: $by, organizationId: $org->id);

        return $sub;
    }

    // ---------------------------------------------------------------- auction credits

    public function startCreditPurchase(Organization $org, User $by, int $qty): array
    {
        $qty = max(1, min((int) config('billing.auction_credit_max_qty'), $qty));

        return $this->startOrder($org, $by, Payment::KIND_CREDITS, $qty, (float) config('billing.auction_credit_price') * $qty);
    }

    // ---------------------------------------------------------------- AI reading packs

    /** Buys $packs AI packs. The payment's quantity is the number of reads, fixed at purchase time. */
    public function startAiPackPurchase(Organization $org, User $by, int $packs): array
    {
        $packs = max(1, min((int) config('billing.ai_pack_max_qty'), $packs));

        return $this->startOrder($org, $by, Payment::KIND_AI_CREDITS, $packs * (int) config('billing.ai_pack_reads'),
            (float) config('billing.ai_pack_price') * $packs);
    }

    private function startOrder(Organization $org, User $by, string $kind, int $qty, float $amount): array
    {
        $this->assertCanBuy();
        $price = $this->price($amount);

        $payment = Payment::create([
            'organization_id' => $org->id, 'created_by' => $by->id, 'kind' => $kind, 'quantity' => $qty,
            'amount' => $price['amount'], 'gst_rate' => $price['gst_rate'], 'gst_amount' => $price['gst'], 'total' => $price['total'],
            'status' => 'created',
        ]);
        $order = $this->razorpay->createOrder((int) round($price['total'] * 100), 'pay_'.$payment->id, [
            'organization_id' => (string) $org->id, 'payment_id' => (string) $payment->id,
        ]);
        $payment->update(['razorpay_order_id' => $order['id']]);

        return $this->checkout($org, $by, [
            'order_id' => $order['id'],
            'amount' => (int) round($price['total'] * 100),
            'description' => $payment->description(),
            'amount_label' => Money::inr($price['total']),
        ]);
    }

    public function confirmOrder(Organization $org, string $paymentId, string $orderId, string $signature): Payment
    {
        if (! $this->razorpay->validOrderSignature($orderId, $paymentId, $signature)) {
            \App\Services\SecurityLog::warning('billing_bad_signature', ['organization_id' => $org->id, 'kind' => 'order']);
            throw ValidationException::withMessages(['payment' => 'We couldn’t verify this payment. If money was deducted, it will be refunded automatically or reflect within a few minutes.']);
        }
        $payment = Payment::where('organization_id', $org->id)->where('razorpay_order_id', $orderId)->firstOrFail();

        return $this->markPaid($payment->id, $paymentId);
    }

    // ---------------------------------------------------------------- webhooks

    /** @return string what happened, for the log */
    public function handleWebhook(string $eventId, array $event): string
    {
        try {
            WebhookEvent::create(['provider' => 'razorpay', 'event_id' => $eventId, 'event' => (string) ($event['event'] ?? '')]);
        } catch (QueryException) {
            return 'duplicate';
        }

        $name = $event['event'] ?? '';
        $payload = $event['payload'] ?? [];
        $payment = $payload['payment']['entity'] ?? null;
        $subEntity = $payload['subscription']['entity'] ?? null;

        $result = match (true) {
            $name === 'payment.captured' && ! empty($payment['order_id']) && empty($payment['invoice_id']) => $this->webhookOrderPaid($payment),
            $name === 'payment.failed' && ! empty($payment['order_id']) => $this->webhookOrderFailed($payment),
            in_array($name, ['subscription.activated', 'subscription.charged', 'subscription.resumed'], true) && $subEntity => $this->webhookSubscriptionCharged($subEntity, $payment),
            in_array($name, ['subscription.pending', 'subscription.halted'], true) && $subEntity => $this->webhookSubscriptionStatus($subEntity, SubscriptionStatus::PastDue),
            in_array($name, ['subscription.cancelled', 'subscription.completed'], true) && $subEntity => $this->webhookSubscriptionStatus($subEntity, SubscriptionStatus::Cancelled),
            default => 'ignored',
        };

        WebhookEvent::where('provider', 'razorpay')->where('event_id', $eventId)->update(['processed_at' => now()]);

        return $result;
    }

    private function webhookOrderPaid(array $payment): string
    {
        $local = Payment::where('razorpay_order_id', $payment['order_id'])->first();
        if (! $local) {
            return 'unknown order';
        }
        $this->markPaid($local->id, $payment['id']);

        return 'order paid';
    }

    private function webhookOrderFailed(array $payment): string
    {
        Payment::where('razorpay_order_id', $payment['order_id'])->where('status', 'created')
            ->update(['status' => 'failed', 'failure_reason' => mb_substr((string) ($payment['error_description'] ?? 'Payment failed'), 0, 250)]);

        return 'order failed';
    }

    private function webhookSubscriptionCharged(array $s, ?array $payment): string
    {
        $sub = Subscription::where('razorpay_subscription_id', $s['id'] ?? '')->first();
        if (! $sub) {
            return 'unknown subscription';
        }
        $start = isset($s['current_start']) ? Carbon::createFromTimestamp($s['current_start']) : now();
        $end = isset($s['current_end']) ? Carbon::createFromTimestamp($s['current_end']) : null;
        $this->activate($sub, $start, $end);
        if ($payment && ! empty($payment['id'])) {
            $this->recordSubscriptionPayment($sub, $payment['id'], $start, $end);
        }

        return 'subscription active';
    }

    private function webhookSubscriptionStatus(array $s, SubscriptionStatus $status): string
    {
        $sub = Subscription::where('razorpay_subscription_id', $s['id'] ?? '')->first();
        if (! $sub) {
            return 'unknown subscription';
        }
        $sub->update(['status' => $status] + ($status === SubscriptionStatus::Cancelled ? ['cancelled_at' => now()] : []));
        $this->audit->log('subscription_'.$status->value, $sub, user: null, organizationId: $sub->organization_id);

        return 'subscription '.$status->value;
    }

    // ---------------------------------------------------------------- internals

    /** The new subscription takes over; any other live paid subscription stops (no double billing). */
    private function activate(Subscription $sub, Carbon $start, ?Carbon $end): void
    {
        DB::transaction(function () use ($sub, $start, $end) {
            $sub = Subscription::whereKey($sub->id)->lockForUpdate()->firstOrFail();
            $wasActive = $sub->status === SubscriptionStatus::Active;
            $end ??= $start->copy()->add($sub->billing_cycle === 'yearly' ? '1 year' : '1 month');
            // Never move the paid-until date backwards (webhooks can arrive out of order).
            $paidUntil = $sub->current_period_end && $sub->current_period_end->greaterThan($end) ? $sub->current_period_end : $end;
            $sub->update(['status' => SubscriptionStatus::Active, 'current_period_start' => $start, 'current_period_end' => $paidUntil]);

            if (! $wasActive) {
                $others = Subscription::where('organization_id', $sub->organization_id)->where('id', '!=', $sub->id)
                    ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value, SubscriptionStatus::Trialing->value])->get();
                foreach ($others as $old) {
                    $old->update(['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => now()]);
                    if ($old->razorpay_subscription_id) {
                        DB::afterCommit(function () use ($old) {
                            try {
                                $this->razorpay->cancelSubscription($old->razorpay_subscription_id, false);
                            } catch (Throwable $e) {
                                Log::warning('razorpay_old_subscription_cancel_failed', ['subscription_id' => $old->id, 'error' => $e->getMessage()]);
                            }
                        });
                    }
                }
                $this->audit->log('subscription_activated', $sub, after: ['plan' => $sub->plan?->code, 'cycle' => $sub->billing_cycle],
                    user: null, organizationId: $sub->organization_id);
            }
        });
    }

    private function recordSubscriptionPayment(Subscription $sub, string $razorpayPaymentId, Carbon $start, ?Carbon $end): void
    {
        if (Payment::where('razorpay_payment_id', $razorpayPaymentId)->exists()) {
            return;
        }
        $plan = $sub->plan;
        $price = $this->price((float) ($sub->billing_cycle === 'yearly' ? $plan->price_yearly : $plan->price_monthly));

        try {
            $payment = Payment::create([
                'organization_id' => $sub->organization_id, 'subscription_id' => $sub->id, 'plan_id' => $plan->id,
                'created_by' => $sub->created_by, 'kind' => Payment::KIND_SUBSCRIPTION, 'billing_cycle' => $sub->billing_cycle,
                'amount' => $price['amount'], 'gst_rate' => $price['gst_rate'], 'gst_amount' => $price['gst'], 'total' => $price['total'],
                'status' => 'created', 'razorpay_subscription_id' => $sub->razorpay_subscription_id,
                'period_start' => $start, 'period_end' => $end ?? $sub->fresh()->current_period_end,
            ]);
        } catch (QueryException) {
            return;
        }
        $this->markPaid($payment->id, $razorpayPaymentId);
    }

    /** Mark paid exactly once: credits added, invoice issued, receipt emailed. */
    private function markPaid(int $paymentId, string $razorpayPaymentId): Payment
    {
        $done = DB::transaction(function () use ($paymentId, $razorpayPaymentId) {
            $p = Payment::whereKey($paymentId)->lockForUpdate()->firstOrFail();
            if ($p->isPaid()) {
                return null;
            }
            $org = Organization::whereKey($p->organization_id)->lockForUpdate()->firstOrFail();

            $p->fill(['status' => 'paid', 'paid_at' => now(), 'razorpay_payment_id' => $razorpayPaymentId, 'failure_reason' => null,
                'billed_to' => ['name' => $org->name, 'gstin' => $org->gstin, 'address' => $org->address, 'city' => $org->city,
                    'state' => $org->state, 'pincode' => $org->pincode, 'email' => $org->email]]);
            $p->invoice_number = $this->invoices->nextNumber();
            $p->save();

            if ($p->kind === Payment::KIND_CREDITS) {
                $org->increment('auction_credits', $p->quantity);
            } elseif ($p->kind === Payment::KIND_AI_CREDITS) {
                $org->increment('ai_credits', $p->quantity);
            }
            $this->audit->log('payment_received', $p, after: ['kind' => $p->kind, 'total' => (float) $p->total, 'invoice' => $p->invoice_number],
                user: null, organizationId: $p->organization_id);

            return $p;
        });

        $payment = Payment::findOrFail($paymentId);
        if ($done) {
            $this->invoices->store($payment);
            foreach ($this->billingContacts($payment->organization_id) as $user) {
                Mail::to($user->email)->queue(new PaymentReceiptMail($payment->fresh()));
            }
        }

        return $payment;
    }

    private function billingContacts(int $orgId)
    {
        return Organization::findOrFail($orgId)->users()->wherePivot('role', OrgRole::BuyerAdmin->value)->get();
    }

    /** Razorpay plan matching this price (incl. GST); a new one is made if the price changed. */
    private function razorpayPlanId(Plan $plan, string $cycle): string
    {
        $price = $this->price((float) ($cycle === 'yearly' ? $plan->price_yearly : $plan->price_monthly));
        $paise = (int) round($price['total'] * 100);
        $idCol = "razorpay_plan_id_{$cycle}";
        $amtCol = "razorpay_plan_amount_{$cycle}";

        if ($plan->{$idCol} && (int) $plan->{$amtCol} === $paise) {
            return $plan->{$idCol};
        }

        $rzp = $this->razorpay->createPlan($cycle, $paise, "GetL1 {$plan->name} ({$cycle})", "GetL1 {$plan->name} plan, billed {$cycle}");
        $plan->forceFill([$idCol => $rzp['id'], $amtCol => $paise])->save();

        return $rzp['id'];
    }

    private function checkout(Organization $org, User $by, array $extra): array
    {
        return $extra + [
            'key' => $this->razorpay->keyId(),
            'name' => 'GetL1',
            'prefill' => ['name' => $by->name, 'email' => $by->email, 'contact' => $by->phone],
            'notes' => ['organization' => $org->name],
            'theme' => ['color' => '#047857'],
        ];
    }

    private function assertCanBuy(): void
    {
        if (! $this->razorpay->isConfigured()) {
            throw ValidationException::withMessages(['plan' => 'Online payments are being set up. Please contact us to upgrade.']);
        }
    }
}
