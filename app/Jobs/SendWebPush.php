<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Sends one notification to every device a user allowed. Devices the push service reports as gone
 * (404/410) are removed. Never throws for one bad device.
 */
class SendWebPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $userId, public array $payload) {}

    public function handle(): void
    {
        $public = config('webpush.public_key');
        $private = config('webpush.private_key');
        if (! $public || ! $private) {
            return;
        }
        $subs = PushSubscription::where('user_id', $this->userId)->get();
        if ($subs->isEmpty()) {
            return;
        }

        $push = new WebPush(['VAPID' => ['subject' => config('webpush.subject'), 'publicKey' => $public, 'privateKey' => $private]], ['TTL' => 86400, 'urgency' => 'normal']);
        $body = json_encode($this->payload, JSON_UNESCAPED_UNICODE);
        $subs = $subs->filter(fn ($s) => PushSubscription::allowedEndpoint($s->endpoint));
        foreach ($subs as $s) {
            $push->queueNotification(Subscription::create([
                'endpoint' => $s->endpoint, 'publicKey' => $s->public_key, 'authToken' => $s->auth_token, 'contentEncoding' => $s->content_encoding,
            ]), $body);
        }
        foreach ($push->flush() as $report) {
            $sub = $subs->firstWhere('endpoint', $report->getEndpoint());
            if ($report->isSuccess()) {
                $sub?->forceFill(['last_used_at' => now()])->save();
            } elseif ($report->isSubscriptionExpired()) {
                $sub?->delete();
            } else {
                Log::warning('webpush_failed', ['user_id' => $this->userId, 'reason' => mb_substr((string) $report->getReason(), 0, 200)]);
            }
        }
    }
}
