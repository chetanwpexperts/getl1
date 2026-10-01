<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * In "website" mode (SITE_MODE=website) only the public website answers;
 * signup and every product page return 404. Only GetL1 staff can log in, to reach the admin console.
 *
 * Runs as global middleware, before routing and before any login check, so a
 * product URL never even redirects to the login page.
 */
class WebsiteOnly
{
    public const PATHS = ['/', 'pricing', 'for-suppliers', 'contact', 'contact/thanks', 'terms', 'privacy', 'refunds', 'shipping', 'sitemap.xml', 'robots.txt', 'up',
        'login', 'logout', 'forgot-password', 'reset-password', 'manifest.webmanifest', 'sw.js', 'offline', 'start'];

    /** GetL1 staff still reach the admin console (login is limited to staff in this mode). */
    public const PREFIXES = ['admin/', 'reset-password/'];

    public function handle(Request $request, Closure $next): Response
    {
        $path = $request->path();
        if (config('site.mode') === 'website' && ! in_array($path, self::PATHS, true) && $path !== 'admin'
            && ! \Illuminate\Support\Str::startsWith($path, self::PREFIXES)) {
            abort(404);
        }

        return $next($request);
    }
}
