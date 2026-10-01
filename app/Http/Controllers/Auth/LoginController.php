<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\SecurityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    /** Attempts per email+IP before a short lockout. */
    private const PER_IP_ATTEMPTS = 5;

    /** Attempts per email from any IP per hour (stops spread-out guessing). */
    private const PER_ACCOUNT_ATTEMPTS = 20;

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:190'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        $email = Str::lower($credentials['email']);
        $ipKey = 'login:'.Str::transliterate($email).'|'.$request->ip();
        $accountKey = 'login-account:'.sha1($email);

        foreach ([[$ipKey, self::PER_IP_ATTEMPTS], [$accountKey, self::PER_ACCOUNT_ATTEMPTS]] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                SecurityLog::warning('login_locked_out', ['email' => $email, 'scope' => str_starts_with($key, 'login:') ? 'ip' : 'account']);

                throw ValidationException::withMessages([
                    'email' => 'Too many attempts. Try again in '.ceil(RateLimiter::availableIn($key) / 60).' minute(s).',
                ]);
            }
        }

        if (! Auth::attempt(['email' => $email, 'password' => $credentials['password']], $request->boolean('remember'))) {
            RateLimiter::hit($ipKey, 60);
            RateLimiter::hit($accountKey, 3600);
            SecurityLog::warning('login_failed', ['email' => $email]);

            // Same message whether or not the email exists.
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        $user = $request->user();
        if (config('site.mode') === 'website' && ! $user->is_platform_admin) {
            // Before launch only GetL1 staff log in on the main domain. Same message as a wrong password.
            Auth::guard('web')->logout();
            SecurityLog::warning('login_refused_website_mode', ['email' => $email]);

            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }
        if ($user->locked_at !== null) {
            Auth::guard('web')->logout();
            SecurityLog::warning('login_locked_account', ['email' => $email]);

            throw ValidationException::withMessages(['email' => 'This account has been locked. Please contact '.config('site.email').'.']);
        }

        RateLimiter::clear($ipKey);
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();
        $audit->log('login', $user, user: $user, organizationId: $user->current_organization_id);

        return redirect()->intended($this->landing($user));
    }

    /**
     * Where to land after login when no page was requested: a supplier with an auction
     * running or starting soon goes straight into the auction room.
     */
    private function landing(\App\Models\User $user): string
    {
        if ($user->is_platform_admin && (config('site.mode') === 'website' || ! $user->organizations()->exists())) {
            return route('admin.dashboard');
        }
        $org = $user->currentOrganization;
        if ($org && $org->isSupplier() && $user->belongsToOrganization($org)) {
            $auction = \App\Services\Auction\ActiveAuctions::for($org)->first();
            if ($auction) {
                return \App\Services\Auction\ActiveAuctions::url($org, $auction);
            }
        }

        return route('dashboard');
    }

    public function destroy(Request $request, AuditLogger $audit): RedirectResponse
    {
        if ($user = $request->user()) {
            $audit->log('logout', $user, user: $user, organizationId: $user->current_organization_id);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
