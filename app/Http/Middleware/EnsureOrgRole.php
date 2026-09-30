<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('org.role:buyer_admin,buyer_user')
 */
class EnsureOrgRole
{
    public function __construct(private CurrentOrganization $current) {}

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $org = $this->current->get();

        abort_unless($org && $request->user()?->hasRoleIn($org, ...$roles), 403);

        return $next($request);
    }
}
