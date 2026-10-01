<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Plan;
use App\Services\Billing\BillingService;
use App\Services\Billing\PlanService;
use App\Services\Billing\Razorpay;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BillingController extends Controller
{
    public function __construct(private CurrentOrganization $current, private BillingService $billing, private PlanService $plans) {}

    public function index(Razorpay $razorpay): View
    {
        $org = $this->current->get();

        return view('buyer.billing', [
            'plans' => Plan::where('is_active', true)->orderBy('sort')->get(),
            'current' => $this->plans->current($org),
            'live' => $this->plans->liveSubscription($org),
            'allowance' => $this->plans->auctionAllowance($org),
            'payments' => Payment::with('plan')->where('organization_id', $org->id)->where('status', 'paid')->latest('paid_at')->limit(50)->get(),
            'canPay' => $razorpay->isConfigured(),
            'testMode' => $razorpay->isTestMode(),
            'creditPrice' => $this->billing->price((float) config('billing.auction_credit_price')),
            'gst' => (bool) config('billing.gst_enabled'),
            'canManage' => request()->user()->roleIn($org)?->value === 'buyer_admin',
        ]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'string', 'exists:plans,code'],
            'cycle' => ['required', 'in:monthly,yearly'],
        ]);

        return response()->json($this->billing->startSubscription($this->current->get(), $request->user(),
            Plan::where('code', $data['plan'])->firstOrFail(), $data['cycle']));
    }

    public function confirmSubscription(Request $request): JsonResponse
    {
        $data = $request->validate([
            'razorpay_payment_id' => ['required', 'string', 'max:64'],
            'razorpay_subscription_id' => ['required', 'string', 'max:64'],
            'razorpay_signature' => ['required', 'string', 'max:128'],
        ]);
        $sub = $this->billing->confirmSubscription($this->current->get(), $data['razorpay_payment_id'], $data['razorpay_subscription_id'], $data['razorpay_signature']);
        session()->flash('status', "You're on the {$sub->plan->name} plan. Thank you! The invoice has been emailed.");

        return response()->json(['ok' => true, 'redirect' => route('buyer.billing.index')]);
    }

    public function buyCredits(Request $request): JsonResponse
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:'.config('billing.auction_credit_max_qty')]]);

        return response()->json($this->billing->startCreditPurchase($this->current->get(), $request->user(), (int) $data['quantity']));
    }

    public function confirmCredits(Request $request): JsonResponse
    {
        $data = $request->validate([
            'razorpay_payment_id' => ['required', 'string', 'max:64'],
            'razorpay_order_id' => ['required', 'string', 'max:64'],
            'razorpay_signature' => ['required', 'string', 'max:128'],
        ]);
        $payment = $this->billing->confirmOrder($this->current->get(), $data['razorpay_payment_id'], $data['razorpay_order_id'], $data['razorpay_signature']);
        session()->flash('status', "{$payment->quantity} auction ".($payment->quantity === 1 ? 'credit' : 'credits').' added. Thank you!');

        return response()->json(['ok' => true, 'redirect' => route('buyer.billing.index')]);
    }

    public function cancel(Request $request): RedirectResponse
    {
        $sub = $this->billing->cancelSubscription($this->current->get(), $request->user());

        return back()->with('status', $sub
            ? 'Your plan won’t renew. You keep it until '.$sub->current_period_end?->ist()->format('d M Y').', then move to Free.'
            : 'There is no paid plan to cancel.');
    }

    public function invoice(int $payment): StreamedResponse
    {
        $p = Payment::where('organization_id', $this->current->id())->where('status', 'paid')->findOrFail($payment);
        abort_unless($p->invoice_pdf_path && Storage::disk('local')->exists($p->invoice_pdf_path), 404);

        return Storage::disk('local')->download($p->invoice_pdf_path, str_replace('/', '-', $p->invoice_number).'.pdf', ['Content-Type' => 'application/pdf']);
    }
}
