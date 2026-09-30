<?php

namespace App\Http\Controllers;

use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Services\OrganizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Onboarding for a logged-in user with no organization, and switching between orgs
 * (e.g. a supplier user who serves several buyers under different firms).
 */
class OrganizationController extends Controller
{
    public function onboarding(Request $request): View|RedirectResponse
    {
        if ($request->user()->organizations()->exists()) {
            return redirect()->route('dashboard');
        }

        return view('organizations.onboarding');
    }

    public function store(Request $request, OrganizationService $orgs): RedirectResponse
    {
        $data = $request->validate([
            'account_type' => ['required', Rule::enum(OrganizationType::class)],
            'company_name' => ['required', 'string', 'max:150'],
            'city' => ['required', 'string', 'max:100'],
        ]);

        $orgs->createWithOwner($request->user(), [
            'name' => $data['company_name'],
            'city' => $data['city'],
        ], OrganizationType::from($data['account_type']));

        return redirect()->route('dashboard');
    }

    public function switch(Request $request, Organization $organization): RedirectResponse
    {
        $request->user()->switchOrganization($organization);

        return redirect()->route('dashboard');
    }
}
