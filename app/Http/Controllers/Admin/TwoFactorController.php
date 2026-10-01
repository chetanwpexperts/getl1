<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureAdminTwoFactor;
use App\Services\AuditLogger;
use App\Services\Security\TwoFactor;
use App\Services\SecurityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Two-step login for the admin console: set up once with an authenticator app, then a code per session. */
class TwoFactorController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(private TwoFactor $totp, private AuditLogger $audit) {}

    public function setup(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if ($user->hasTwoFactor()) {
            return redirect()->route('admin.2fa.challenge');
        }
        // One pending secret per session, so a reload doesn't invalidate the scanned code.
        $secret = $request->session()->get('admin_2fa_pending') ?: $this->totp->newSecret();
        $request->session()->put('admin_2fa_pending', $secret);

        return view('admin.two-factor.setup', [
            'secret' => $secret,
            'uri' => $this->totp->otpauthUri($user, $secret),
        ]);
    }

    public function confirm(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        abort_if($user->hasTwoFactor(), 409);
        $secret = (string) $request->session()->get('admin_2fa_pending');
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $this->throttle($request);

        if ($secret === '' || ! $this->totp->verify($user, $secret, $data['code'])) {
            $this->failed($request, 'setup');
        }

        $codes = $this->totp->newRecoveryCodes();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => json_encode($codes['hashed']),
            'two_factor_confirmed_at' => now(),
        ])->save();
        $request->session()->forget('admin_2fa_pending');
        RateLimiter::clear($this->key($request));
        EnsureAdminTwoFactor::markVerified($request);
        $this->audit->log('admin_2fa_enabled', $user, user: $user, organizationId: null);
        SecurityLog::info('admin_2fa_enabled');

        return view('admin.two-factor.recovery', ['codes' => $codes['plain']]);
    }

    public function challenge(Request $request): View|RedirectResponse
    {
        if (! $request->user()->hasTwoFactor()) {
            return redirect()->route('admin.2fa.setup');
        }

        return view('admin.two-factor.challenge', ['recovery' => $request->boolean('recovery')]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasTwoFactor(), 409);
        $data = $request->validate(['code' => ['nullable', 'string', 'max:10'], 'recovery_code' => ['nullable', 'string', 'max:20']]);
        $this->throttle($request);

        $ok = filled($data['recovery_code'] ?? null)
            ? $this->totp->useRecoveryCode($user, $data['recovery_code'])
            : $this->totp->verify($user, (string) $user->two_factor_secret, (string) ($data['code'] ?? ''));
        if (! $ok) {
            $this->failed($request, filled($data['recovery_code'] ?? null) ? 'recovery' : 'code');
        }

        RateLimiter::clear($this->key($request));
        EnsureAdminTwoFactor::markVerified($request);
        if (filled($data['recovery_code'] ?? null)) {
            $this->audit->log('admin_2fa_recovery_code_used', $user, after: ['left' => $this->totp->remainingRecoveryCodes($user)], user: $user, organizationId: null);
            SecurityLog::warning('admin_2fa_recovery_code_used');
        }
        SecurityLog::info('admin_2fa_passed');

        return redirect()->intended(route('admin.dashboard'));
    }

    private function key(Request $request): string
    {
        return 'admin-2fa:'.$request->user()->id;
    }

    private function throttle(Request $request): void
    {
        if (RateLimiter::tooManyAttempts($this->key($request), self::MAX_ATTEMPTS)) {
            SecurityLog::warning('admin_2fa_locked');
            throw ValidationException::withMessages(['code' => 'Too many wrong codes. Try again in '.ceil(RateLimiter::availableIn($this->key($request)) / 60).' minutes.']);
        }
    }

    private function failed(Request $request, string $kind): never
    {
        RateLimiter::hit($this->key($request), 15 * 60);
        SecurityLog::warning('admin_2fa_failed', ['kind' => $kind]);
        throw ValidationException::withMessages([$kind === 'recovery' ? 'recovery_code' : 'code' => 'That code is not right. Check your authenticator app and try again.']);
    }
}
