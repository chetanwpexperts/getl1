<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('org.type:buyer') or 'org.type:supplier'
 */
class EnsureOrganizationType
{
    public function __construct(private CurrentOrganization $current) {}

    public function handle(Request $request, Closure $next, string $type): Response
    {
        abort_unless($this->current->get()?->type?->value === $type, 403);

        return $next($request);
    }
}
