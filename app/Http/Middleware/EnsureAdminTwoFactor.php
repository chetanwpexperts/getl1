<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin console needs a second step on top of the password: an authenticator-app code.
 *
 * - Not set up yet → setup page (it cannot be skipped).
 * - Verified less than 12 hours ago and active in the last 60 minutes → allowed.
 * - Otherwise → enter a code again.
 */
class EnsureAdminTwoFactor
{
    public const SESSION = 'admin_2fa';
    public const MAX_AGE = 12 * 3600;
    public const IDLE = 60 * 60;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user->hasTwoFactor()) {
            return redirect()->route('admin.2fa.setup');
        }

        $s = $request->session()->get(self::SESSION);
        $now = time();
        if (! is_array($s) || ($s['user'] ?? null) !== $user->id
            || $now - ($s['at'] ?? 0) > self::MAX_AGE || $now - ($s['seen'] ?? 0) > self::IDLE) {
            $request->session()->forget(self::SESSION);
            if ($request->isMethod('GET')) {
                $request->session()->put('url.intended', $request->fullUrl());
            }

            return redirect()->route('admin.2fa.challenge');
        }

        $request->session()->put(self::SESSION.'.seen', $now);

        $response = $next($request);
        // Admin pages are never cached by the browser or a proxy.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    public static function markVerified(Request $request): void
    {
        $request->session()->regenerate();
        $request->session()->put(self::SESSION, ['user' => $request->user()->id, 'at' => time(), 'seen' => time()]);
    }
}
