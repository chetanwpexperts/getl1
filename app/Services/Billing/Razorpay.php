<?php

namespace App\Services\Billing;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Minimal Razorpay REST client (orders, plans, subscriptions) plus signature checks.
 * Amounts are in paise. Secrets never leave the server and are never logged.
 */
class Razorpay
{
    private const BASE = 'https://api.razorpay.com/v1/';

    public function isConfigured(): bool
    {
        return (bool) config('services.razorpay.key_id') && (bool) config('services.razorpay.key_secret');
    }

    public function keyId(): ?string
    {
        return config('services.razorpay.key_id');
    }

    public function isTestMode(): bool
    {
        return str_starts_with((string) $this->keyId(), 'rzp_test_');
    }

    public function createOrder(int $amountPaise, string $receipt, array $notes = []): array
    {
        return $this->post('orders', ['amount' => $amountPaise, 'currency' => 'INR', 'receipt' => $receipt, 'notes' => $notes]);
    }

    public function createPlan(string $period, int $amountPaise, string $name, string $description): array
    {
        return $this->post('plans', [
            'period' => $period, 'interval' => 1,
            'item' => ['name' => $name, 'amount' => $amountPaise, 'currency' => 'INR', 'description' => $description],
        ]);
    }

    public function createSubscription(string $planId, int $totalCount, array $notes = []): array
    {
        return $this->post('subscriptions', ['plan_id' => $planId, 'total_count' => $totalCount, 'customer_notify' => 1, 'notes' => $notes]);
    }

    public function cancelSubscription(string $subscriptionId, bool $atCycleEnd): array
    {
        return $this->post("subscriptions/{$subscriptionId}/cancel", ['cancel_at_cycle_end' => $atCycleEnd ? 1 : 0]);
    }

    /** Checkout success for a one-time order: HMAC(order_id|payment_id). */
    public function validOrderSignature(string $orderId, string $paymentId, string $signature): bool
    {
        return $this->hmacMatches($orderId.'|'.$paymentId, $signature, (string) config('services.razorpay.key_secret'));
    }

    /** Checkout success for a subscription: HMAC(payment_id|subscription_id). */
    public function validSubscriptionSignature(string $paymentId, string $subscriptionId, string $signature): bool
    {
        return $this->hmacMatches($paymentId.'|'.$subscriptionId, $signature, (string) config('services.razorpay.key_secret'));
    }

    /** Webhook: HMAC of the raw request body with the webhook secret. */
    public function validWebhookSignature(string $body, string $signature): bool
    {
        return $this->hmacMatches($body, $signature, (string) config('services.razorpay.webhook_secret'));
    }

    private function hmacMatches(string $payload, string $signature, string $secret): bool
    {
        return $secret !== '' && $signature !== '' && hash_equals(hash_hmac('sha256', $payload, $secret), $signature);
    }

    private function post(string $path, array $data): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Online payments are not set up yet.');
        }

        $response = Http::withBasicAuth((string) config('services.razorpay.key_id'), (string) config('services.razorpay.key_secret'))
            ->acceptJson()->asJson()->timeout(20)->connectTimeout(5)
            ->post(self::BASE.$path, $data);

        if ($response->failed()) {
            $error = $response->json('error.description') ?? 'HTTP '.$response->status();
            Log::warning('razorpay_request_failed', ['path' => $path, 'status' => $response->status(), 'error' => $error]);

            throw new RuntimeException('The payment provider returned an error: '.$error);
        }

        return $response->json();
    }
}
