<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Cloudflare Turnstile: a free, mostly invisible check that the form was filled in by a person.
 * Off when the keys aren't set (local, tests). If Cloudflare itself can't be reached, the form is
 * allowed through (and logged), so an outage on their side never locks out real customers;
 * the app's own rate limits still apply.
 */
class Turnstile
{
    public const URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public static function enabled(): bool
    {
        return filled(config('services.turnstile.site_key')) && filled(config('services.turnstile.secret_key'));
    }

    /** Throws a validation error (field "turnstile") when the check fails. */
    public function check(Request $request, string $action): void
    {
        if (! self::enabled()) {
            return;
        }
        $token = (string) $request->input('cf-turnstile-response');
        if ($token === '' || strlen($token) > 2048) {
            $this->fail($action, 'missing');
        }

        try {
            $res = Http::asForm()->timeout(6)->post(self::URL, [
                'secret' => config('services.turnstile.secret_key'),
                'response' => $token,
                'remoteip' => $request->ip(),
            ]);
        } catch (Throwable $e) {
            SecurityLog::warning('turnstile_unreachable', ['action' => $action, 'error' => mb_substr($e->getMessage(), 0, 120)]);

            return;
        }
        if (! $res->successful()) {
            SecurityLog::warning('turnstile_unreachable', ['action' => $action, 'status' => $res->status()]);

            return;
        }
        if ($res->json('success') !== true) {
            $this->fail($action, implode(',', (array) $res->json('error-codes')));
        }
    }

    private function fail(string $action, string $why): never
    {
        SecurityLog::warning('turnstile_failed', ['action' => $action, 'why' => $why]);

        throw ValidationException::withMessages(['turnstile' => 'Please complete the security check and try again.']);
    }
}
