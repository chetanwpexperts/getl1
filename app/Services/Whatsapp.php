<?php

namespace App\Services;

use App\Jobs\SendWhatsapp;
use App\Models\User;
use App\Models\WhatsappMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp alerts for the few moments that matter most (RFQ invitation, auction starting, PO,
 * approval needed, payment). Every message is logged in whatsapp_messages and sent by a queued
 * job after the action commits. Nothing here can break the action; when WhatsApp isn't set up,
 * it does nothing.
 */
class Whatsapp
{
    public static function enabled(): bool
    {
        // The app secret too: without it STOP replies and receipts can't be trusted, so nothing is sent.
        return filled(config('whatsapp.phone_number_id')) && filled(config('whatsapp.token')) && filled(config('whatsapp.app_secret'));
    }

    /** 10-digit Indian mobile → 91XXXXXXXXXX, or null. */
    public static function phone(?string $raw): ?string
    {
        $d = preg_replace('/\D/', '', (string) $raw);
        if (strlen($d) === 12 && str_starts_with($d, '91')) {
            $d = substr($d, 2);
        } elseif (strlen($d) === 11 && str_starts_with($d, '0')) {
            $d = substr($d, 1);
        }

        return preg_match('/^[6-9]\d{9}$/', $d) ? '91'.$d : null;
    }

    /** A person in a company: only if they have a mobile number and haven't turned WhatsApp off. */
    public static function toUser(?User $user, string $template, array $params, string $path, ?int $orgId = null, ?Model $related = null): void
    {
        if (! $user || $user->locked_at || ! data_get($user->notification_prefs, 'whatsapp', true)) {
            return;
        }
        self::toPhone($user->phone, $template, $params, $path, $orgId, $related);
    }

    /**
     * @param  list<string>  $params  body variables, in template order
     * @param  string  $path  the page the button opens, e.g. "supplier/orders/12" (no leading slash)
     */
    public static function toPhone(?string $phone, string $template, array $params, string $path, ?int $orgId = null, ?Model $related = null, ?int $supplierOrgId = null): void
    {
        if (! self::enabled() || ! isset(config('whatsapp.templates')[$template])) {
            return;
        }
        $to = self::phone($phone);
        if (! $to) {
            return;
        }
        try {
            DB::afterCommit(fn () => rescue(function () use ($to, $template, $params, $path, $orgId, $related, $supplierOrgId) {
                // Same template about the same thing to the same number: once.
                if ($related && WhatsappMessage::where('recipient_phone', $to)->where('template', $template)
                    ->where('related_type', $related->getMorphClass())->where('related_id', $related->getKey())->exists()) {
                    return;
                }
                // Someone who replied STOP, or a GetL1 user with this number who turned WhatsApp off, gets nothing.
                if (WhatsappMessage::where('recipient_phone', $to)->where('direction', 'in')->where('status', 'opted_out')->exists()
                    || User::where('phone', substr($to, 2))->get(['id', 'notification_prefs'])->contains(fn ($u) => data_get($u->notification_prefs, 'whatsapp', true) === false)) {
                    return;
                }
                // Each company has a daily ceiling, so a large import can't flood numbers (or the bill).
                if ($orgId && WhatsappMessage::where('organization_id', $orgId)->where('direction', 'out')->where('created_at', '>=', now()->startOfDay())->count()
                    >= (int) config('whatsapp.daily_limit_per_company', 300)) {
                    Log::warning('whatsapp_daily_limit', ['organization_id' => $orgId, 'template' => $template]);

                    return;
                }
                $params = array_map(fn ($p) => mb_substr(preg_replace('/\s+/', ' ', trim((string) $p)), 0, 120), $params);
                $msg = WhatsappMessage::create([
                    'organization_id' => $orgId, 'recipient_phone' => $to, 'supplier_org_id' => $supplierOrgId, 'template' => $template,
                    'direction' => 'out', 'body' => json_encode(['params' => $params, 'path' => ltrim($path, '/')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'related_type' => $related?->getMorphClass(), 'related_id' => $related?->getKey(), 'status' => 'queued',
                ]);
                SendWhatsapp::dispatch($msg->id);
            }, fn ($e) => Log::warning('whatsapp_queue_failed', ['template' => $template, 'error' => $e->getMessage()]), false));
        } catch (\Throwable $e) {
            Log::warning('whatsapp_queue_failed', ['template' => $template, 'error' => $e->getMessage()]);
        }
    }
}
