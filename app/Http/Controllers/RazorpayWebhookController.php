<?php

namespace App\Http\Controllers;

use App\Services\Billing\BillingService;
use App\Services\Billing\Razorpay;
use App\Services\SecurityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** Razorpay → GetL1. Only signed requests are processed; each event once. */
class RazorpayWebhookController extends Controller
{
    public function __invoke(Request $request, Razorpay $razorpay, BillingService $billing): JsonResponse
    {
        $body = $request->getContent();
        if (! $razorpay->validWebhookSignature($body, (string) $request->header('X-Razorpay-Signature'))) {
            SecurityLog::warning('razorpay_webhook_bad_signature', ['ip' => $request->ip()]);

            return response()->json(['ok' => false], 400);
        }

        $event = json_decode($body, true);
        if (! is_array($event)) {
            return response()->json(['ok' => false], 400);
        }
        $eventId = (string) ($request->header('X-Razorpay-Event-Id') ?: hash('sha256', $body));
        $result = $billing->handleWebhook($eventId, $event);
        Log::info('razorpay_webhook', ['event' => $event['event'] ?? null, 'result' => $result]);

        return response()->json(['ok' => true]);
    }
}
