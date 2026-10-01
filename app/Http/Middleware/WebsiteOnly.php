<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * In "website" mode (SITE_MODE=website) only the public website answers;
 * login, signup and every product page return 404.
 */
class WebsiteOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('site.mode') === 'website' && ! $request->routeIs('site.*', 'home')) {
            abort(404);
        }

        return $next($request);
    }
}
