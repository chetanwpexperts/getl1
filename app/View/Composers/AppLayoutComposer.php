<?php

namespace App\View\Composers;

use App\Support\Tenancy\CurrentOrganization;
use Illuminate\View\View;

/**
 * Gives every page using the app layout the same header: current company, role and menu,
 * even on pages that run outside the organization middleware (e.g. admin pages).
 */
class AppLayoutComposer
{
    public function __construct(private CurrentOrganization $current) {}

    public function compose(View $view): void
    {
        $user = auth()->user();

        if (! $user) {
            return;
        }

        $org = $this->current->get();

        if (! $org && $user->current_organization_id) {
            $candidate = $user->currentOrganization;
            // Only show a company the user still belongs to.
            if ($candidate && $user->belongsToOrganization($candidate)) {
                $org = $candidate;
            }
        }

        if ($org) {
            $view->with('currentOrg', $org);
            $view->with('currentRole', $user->roleIn($org));
        }
    }
}
