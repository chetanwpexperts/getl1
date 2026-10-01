<?php

namespace App\Http\Controllers\Auth;

use App\Enums\OrganizationType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\Gstin;
use App\Services\AuditLogger;
use App\Services\OrganizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class RegisterController extends Controller
{
    public function create(Request $request): View
    {
        // ?as=supplier preselects supplier signup (used in invite links)
        return view('auth.register', [
            'as' => $request->query('as') === 'supplier' ? 'supplier' : 'buyer',
        ]);
    }

    public function store(Request $request, OrganizationService $orgs, AuditLogger $audit): RedirectResponse
    {
        app(\App\Services\Turnstile::class)->check($request, 'register');
        $data = $request->validate([
            'account_type' => ['required', Rule::enum(OrganizationType::class)],
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:190', 'unique:users,email'],
            'phone' => ['required', 'string', 'regex:/^[6-9]\d{9}$/'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'company_name' => ['required', 'string', 'max:150'],
            'city' => ['required', 'string', 'max:100'],
            'gstin' => ['nullable', 'string', 'size:15', new Gstin],
        ], [
            'phone.regex' => 'Enter a 10-digit Indian mobile number.',
        ]);

        $user = DB::transaction(function () use ($data, $orgs) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'password' => $data['password'],
            ]);

            $orgs->createWithOwner($user, [
                'name' => $data['company_name'],
                'city' => $data['city'],
                'gstin' => isset($data['gstin']) ? strtoupper($data['gstin']) : null,
                'email' => $data['email'],
                'phone' => $data['phone'],
            ], OrganizationType::from($data['account_type']));

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        $audit->log('registered', $user, after: ['account_type' => $data['account_type']], user: $user,
            organizationId: $user->current_organization_id);

        return redirect()->intended(route('dashboard'))->with('status', 'Welcome to GetL1!');
    }
}
