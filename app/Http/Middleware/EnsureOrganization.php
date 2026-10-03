<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the organization the logged-in user is acting for and
 * makes it available app-wide (CurrentOrganization) and to views ($currentOrg).
 */
class EnsureOrganization
{
    public function __construct(private CurrentOrganization $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        $org = $user->currentOrganization;

        // Current org missing or membership removed: fall back to the first org the user belongs to.
        if (! $org || ! $user->belongsToOrganization($org)) {
            $org = $user->organizations()->first();

            if (! $org) {
                return redirect()->route('onboarding');
            }

            $user->forceFill(['current_organization_id' => $org->id])->save();
        }

        abort_if($org->status === 'suspended', 403, 'This organization is suspended. Contact support.');

        $this->current->set($org);
        $role = $user->roleIn($org);
        view()->share('currentOrg', $org);
        view()->share('currentRole', $role);

        // Requesters (store/plant staff) only ever reach their purchase requests: no prices,
        // suppliers, RFQs or orders. Any other page sends them back to their requests.
        if ($role === \App\Enums\OrgRole::Requester && ! $request->routeIs('buyer.requests.*')) {
            abort_unless($request->isMethod('GET'), 403);

            return redirect()->route('buyer.requests.index');
        }

        return $next($request);
    }
}
