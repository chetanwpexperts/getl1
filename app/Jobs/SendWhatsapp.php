<?php

namespace App\Jobs;

use App\Models\WhatsappMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Sends one logged WhatsApp template message through Meta's Cloud API and records the result. */
class SendWhatsapp implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public int $messageId) {}

    public function handle(): void
    {
        $msg = WhatsappMessage::find($this->messageId);
        if (! $msg || $msg->status !== 'queued' || ! \App\Services\Whatsapp::enabled()) {
            return;
        }
        [$name, $vars] = config('whatsapp.templates')[$msg->template] ?? [null, 0];
        if (! $name) {
            $msg->update(['status' => 'failed', 'error' => 'Unknown template']);

            return;
        }
        $data = json_decode((string) $msg->body, true) ?: [];
        $params = array_slice(array_pad($data['params'] ?? [], $vars, '-'), 0, $vars);

        try {
            $res = Http::withToken((string) config('whatsapp.token'))->timeout(10)->acceptJson()
            ->post('https://graph.facebook.com/'.config('whatsapp.api_version').'/'.config('whatsapp.phone_number_id').'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => $msg->recipient_phone,
                'type' => 'template',
                'template' => [
                    'name' => $name,
                    'language' => ['code' => config('whatsapp.language')],
                    'components' => [
                        ['type' => 'body', 'parameters' => array_map(fn ($p) => ['type' => 'text', 'text' => $p === '' ? '-' : $p], $params)],
                        ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => (string) ($data['path'] ?? '')]]],
                    ],
                ],
            ]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->retryOrFail($msg, 'Could not reach WhatsApp: '.mb_substr($e->getMessage(), 0, 150));

            return;
        }

        if ($res->successful()) {
            $msg->update(['status' => 'sent', 'provider_message_id' => $res->json('messages.0.id'), 'error' => null]);

            return;
        }
        $error = mb_substr((string) ($res->json('error.message') ?? $res->status()), 0, 250);
        // Server-side trouble: try again later. Anything else (bad number, template): give up.
        if ($res->serverError() || $res->status() === 429) {
            $this->retryOrFail($msg, $error);

            return;
        }
        $msg->update(['status' => 'failed', 'error' => $error]);
        Log::warning('whatsapp_failed', ['message_id' => $msg->id, 'error' => $error]);
    }

    /** Try again later on a real queue; on the "sync" driver (no retries) record it as failed. */
    private function retryOrFail(WhatsappMessage $msg, string $error): void
    {
        if (! $this->job || $this->job->getConnectionName() === 'sync' || $this->attempts() >= $this->tries) {
            $msg->update(['status' => 'failed', 'error' => $error]);

            return;
        }
        $msg->update(['error' => $error]);
        $this->release($this->backoff[$this->attempts() - 1] ?? 300);
    }

    public function failed(\Throwable $e): void
    {
        WhatsappMessage::whereKey($this->messageId)->where('status', 'queued')->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 250)]);
    }
}
