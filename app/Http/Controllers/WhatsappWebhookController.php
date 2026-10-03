<?php

namespace App\Http\Controllers;

use App\Models\WhatsappMessage;
use App\Services\SecurityLog;
use App\Services\Whatsapp;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Meta WhatsApp webhook: the one-time verification (GET), then delivery receipts and replies
 * (POST, signed with the app secret). Replies of STOP stop all further messages to that number.
 */
class WhatsappWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $token = (string) config('whatsapp.verify_token');
        if ($token !== '' && $request->query('hub_mode') === 'subscribe' && hash_equals($token, (string) $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
        }

        return response('', 403);
    }

    public function receive(Request $request): Response
    {
        $secret = (string) config('whatsapp.app_secret');
        $sig = (string) $request->header('X-Hub-Signature-256');
        if ($secret === '' || ! hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $sig)) {
            SecurityLog::warning('whatsapp_webhook_bad_signature', ['ip' => $request->ip()]);

            return response('', 403);
        }

        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = $change['value'] ?? [];
                foreach ((array) ($value['statuses'] ?? []) as $st) {
                    $status = in_array($st['status'] ?? '', ['sent', 'delivered', 'read', 'failed'], true) ? $st['status'] : null;
                    if ($status && ! empty($st['id'])) {
                        $msg = WhatsappMessage::where('provider_message_id', $st['id'])->first();
                        // Never move backwards (a late "sent" after "read").
                        $order = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];
                        if ($msg && ($order[$status] ?? 0) > ($order[$msg->status] ?? 0)) {
                            $msg->update(['status' => $status, 'error' => $status === 'failed' ? mb_substr((string) ($st['errors'][0]['title'] ?? 'Failed'), 0, 250) : $msg->error]);
                        }
                    }
                }
                foreach ((array) ($value['messages'] ?? []) as $in) {
                    $from = Whatsapp::phone((string) ($in['from'] ?? ''));
                    if (! $from) {
                        continue;
                    }
                    $text = mb_substr(trim((string) ($in['text']['body'] ?? $in['button']['text'] ?? '')), 0, 1000);
                    $stop = (bool) preg_match('/^\s*(stop|unsubscribe|band karo)\s*$/i', $text);
                    WhatsappMessage::create([
                        'recipient_phone' => $from, 'direction' => 'in', 'body' => $text, 'provider_message_id' => mb_substr((string) ($in['id'] ?? ''), 0, 190),
                        'status' => $stop ? 'opted_out' : 'received',
                    ]);
                }
            }
        }

        return response('', 200);
    }
}
