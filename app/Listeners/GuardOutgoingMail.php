<?php

namespace App\Listeners;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;

/**
 * Last check before any email leaves the server.
 *
 * - Never send to reserved test domains (getl1.test, example.com, …): they always bounce, and
 *   bounces hurt the sending domain's reputation.
 * - Outside production, send only to addresses or domains in MAIL_ALLOWLIST (if set), so a
 *   staging test can never email a real customer.
 * Dropped recipients are logged; if nobody is left the email is not sent at all.
 */
class GuardOutgoingMail
{
    private const RESERVED = ['/\.(test|example|invalid|localhost)$/i', '/(^|\.)example\.(com|net|org)$/i'];

    public function handle(MessageSending $event): bool
    {
        $message = $event->message;
        $allow = array_filter(array_map(fn ($v) => strtolower(trim($v)), explode(',', (string) config('mail.allowlist'))));
        $restrict = ! app()->isProduction() && $allow !== [];
        $dropped = [];

        $keep = function (array $addresses) use ($allow, $restrict, &$dropped): array {
            return array_values(array_filter($addresses, function (Address $a) use ($allow, $restrict, &$dropped) {
                $email = strtolower($a->getAddress());
                $domain = substr(strrchr($email, '@') ?: '', 1);
                $ok = ! collect(self::RESERVED)->contains(fn ($re) => preg_match($re, $domain))
                    && (! $restrict || in_array($email, $allow, true) || in_array($domain, $allow, true));
                if (! $ok) {
                    $dropped[] = $email;
                }

                return $ok;
            }));
        };

        $to = $keep($message->getTo());
        $cc = $keep($message->getCc());
        $bcc = $keep($message->getBcc());

        if ($dropped) {
            Log::info('mail_recipients_dropped', ['dropped' => $dropped, 'subject' => $message->getSubject()]);
        }
        if (! $to && ! $cc && ! $bcc) {
            return false; // nothing left to send to
        }

        foreach (['to' => $to, 'cc' => $cc, 'bcc' => $bcc] as $header => $list) {
            $message->getHeaders()->remove($header);
            if ($list) {
                $message->{$header}(...$list);
            }
        }

        return true;
    }
}
