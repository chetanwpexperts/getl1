<?php

namespace App\Http\Middleware;

use App\Services\WebsiteSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Makes sure each request sees the latest Admin → Website values (they are cached for 5 minutes). */
class ApplyWebsiteSettings
{
    public function __construct(private WebsiteSettings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->settings->apply();

        return $next($request);
    }
}
