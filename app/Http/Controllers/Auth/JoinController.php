<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Invited team member sets a password from the emailed link (signed, 7 days, latest invitation only)
 * and is signed in. Works once: after the first sign-in the link is dead.
 */
class JoinController extends Controller
{
    public function show(Request $request, User $user): View|RedirectResponse
    {
        $this->check($request, $user);

        return view('auth.join', [
            'user' => $user,
            'org' => $user->organizations()->orderByPivot('created_at', 'desc')->first(),
            'action' => $request->fullUrl(),
        ]);
    }

    public function store(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        $this->check($request, $user);
        $request->validate(['password' => ['required', 'confirmed', Password::min(8)]]);

        $user->forceFill([
            'password' => Hash::make((string) $request->input('password')),
            'email_verified_at' => $user->email_verified_at ?? now(), // they opened the link from their inbox
            'last_login_at' => now(),
        ])->save();

        Auth::guard('web')->logout();
        Auth::login($user);
        $request->session()->regenerate();
        $audit->log('team_invite_accepted', $user, user: $user, organizationId: $user->current_organization_id);

        return redirect()->route('dashboard')->with('status', 'Welcome to GetL1. Your password is set.');
    }

    private function check(Request $request, User $user): void
    {
        $valid = $request->hasValidSignature()
            && $user->invited_at
            && ! $user->last_login_at
            && (int) $request->query('v') === $user->invited_at->getTimestamp();

        abort_unless($valid, 403, 'This invitation link has expired or was already used. Ask your colleague to send a new one, or use "Forgot password" on the login page.');
    }
}
