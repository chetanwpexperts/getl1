<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SecurityLog;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

/**
 * Forgot password: a single-use link by email, valid for 60 minutes.
 * The reply never says whether an email is registered.
 */
class PasswordResetController extends Controller
{
    public function request(): View
    {
        return view('auth.forgot-password');
    }

    public function email(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:190']]);
        $status = Password::sendResetLink(['email' => strtolower($data['email'])]);
        SecurityLog::info('password_reset_requested', ['email' => strtolower($data['email']), 'result' => $status]);

        return back()->with('status', 'If an account exists for that email, a reset link is on its way. It works for 60 minutes.');
    }

    public function edit(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => (string) $request->query('email')]);
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset(
            ['email' => strtolower($data['email']), 'password' => $data['password'], 'password_confirmation' => $request->input('password_confirmation'), 'token' => $data['token']],
            function (User $user, string $password) use ($audit) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                event(new PasswordReset($user));
                $audit->log('password_reset', $user, user: $user, organizationId: $user->current_organization_id);
                SecurityLog::info('password_reset_done', ['user_id' => $user->id]);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            SecurityLog::warning('password_reset_failed', ['email' => strtolower($data['email']), 'result' => $status]);

            return back()->withInput($request->only('email'))->withErrors(['email' => 'This reset link is invalid or has expired. Please ask for a new one.']);
        }

        return redirect()->route('login')->with('status', 'Your password has been changed. Please log in.');
    }
}
