<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * In "website" mode (SITE_MODE=website) only the public website answers;
 * login, signup and every product page return 404.
 *
 * Runs as global middleware, before routing and before any login check, so a
 * product URL never even redirects to the login page.
 */
class WebsiteOnly
{
    public const PATHS = ['/', 'pricing', 'for-suppliers', 'contact', 'contact/thanks', 'terms', 'privacy', 'refunds', 'shipping', 'sitemap.xml', 'robots.txt', 'up'];

    public function handle(Request $request, Closure $next): Response
    {
        if (config('site.mode') === 'website' && ! in_array($request->path(), self::PATHS, true)) {
            abort(404);
        }

        return $next($request);
    }
}
