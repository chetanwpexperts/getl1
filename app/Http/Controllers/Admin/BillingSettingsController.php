<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Services\AuditLogger;
use App\Services\BillingSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** GST on/off, GSTIN, rate and the seller details on receipts and invoices. Every change is audited. */
class BillingSettingsController extends Controller
{
    public function edit(BillingSettings $settings): View
    {
        return view('admin.billing', [
            'fields' => BillingSettings::FIELDS,
            'saved' => $settings->saved(),
            'current' => $settings->current(),
            'rates' => BillingSettings::RATES,
            // Auto-renewing Razorpay subscriptions keep the amount they started with until renewed or changed.
            'activeSubscriptions' => Subscription::whereNotNull('razorpay_subscription_id')->whereIn('status', ['active', 'past_due'])->count(),
        ]);
    }

    public function update(Request $request, BillingSettings $settings, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(BillingSettings::rules());
        $wasOn = (bool) config('billing.gst_enabled');
        $on = ! empty($data['gst_enabled']);
        $gstin = filled($data['gstin'] ?? null) ? $data['gstin'] : config('billing.seller.gstin');

        if ($on && ! $gstin) {
            throw ValidationException::withMessages(['gstin' => 'Enter your GSTIN before turning GST on.']);
        }
        if ($on !== $wasOn && empty($data['confirm_gst'])) {
            throw ValidationException::withMessages(['confirm_gst' => $on
                ? 'Tick the box to confirm: GST will be added to every price from now on.'
                : 'Tick the box to confirm: GST will no longer be charged.']);
        }

        [$before, $after] = $settings->save($data, $request->user());
        if (! $after) {
            return back()->with('status', 'Nothing changed.');
        }
        $audit->log('admin_billing_changed', null, before: $before, after: $after + ['reason' => $data['reason']], organizationId: null);

        $msg = match (true) {
            $on && ! $wasOn => 'GST is on. Prices now show "+ GST" and new payments get a tax invoice.',
            ! $on && $wasOn => 'GST is off. New payments get a payment receipt without GST.',
            default => 'Billing details saved. New receipts and invoices use them.',
        };

        return back()->with('status', $msg);
    }
}
