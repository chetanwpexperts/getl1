<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SecurityLog;
use App\Services\SecurityLogReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Individual people (a company can have several). Staff can sign someone out everywhere,
 * lock or unlock them, and reset a colleague's two-step login. Each action needs a reason.
 */
class UserController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q'));
        $users = User::query()
            ->when($q !== '', function ($b) use ($q) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
                $b->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like));
            })
            ->when($request->query('filter') === 'locked', fn ($b) => $b->whereNotNull('locked_at'))
            ->when($request->query('filter') === 'staff', fn ($b) => $b->where('is_platform_admin', true))
            ->with('organizations')
            ->latest()->paginate(40)->withQueryString();

        return view('admin.users.index', ['users' => $users, 'q' => $q, 'filter' => $request->query('filter')]);
    }

    public function show(User $user, SecurityLogReader $reader): View
    {
        $user->load('organizations');
        $email = Str::lower($user->email);

        return view('admin.users.show', [
            'u' => $user,
            'logins' => AuditLog::where('user_id', $user->id)->whereIn('action', ['login', 'password_reset', 'admin_2fa_enabled', 'admin_2fa_recovery_code_used'])
                ->latest('id')->limit(20)->get(),
            'failed' => $reader->search(fn ($e) => in_array($e['event'], ['login_failed', 'login_locked_out', 'login_locked_account'], true)
                && Str::lower((string) ($e['context']['email'] ?? '')) === $email, 7, 20),
            'activity' => AuditLog::with('user:id,name,email')->where('user_id', $user->id)->latest('id')->limit(25)->get(),
            'sessions' => $this->sessionCount($user),
        ]);
    }

    public function signOut(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:200']]);
        $this->notSelf($request, $user);
        $n = $this->endSessions($user);
        $this->record('admin_user_signed_out', $user, $data['reason'], ['sessions' => $n]);

        return back()->with('status', "{$user->name} has been signed out of every device.");
    }

    public function lock(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:200']]);
        $this->notSelf($request, $user);
        abort_if($user->locked_at !== null, 409);
        $user->forceFill(['locked_at' => now(), 'locked_reason' => $data['reason']])->save();
        $this->endSessions($user);
        $this->record('admin_user_locked', $user, $data['reason']);
        SecurityLog::warning('admin_user_locked', ['target_user_id' => $user->id]);

        return back()->with('status', "{$user->name} is locked and signed out everywhere.");
    }

    public function unlock(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:200']]);
        abort_if($user->locked_at === null, 409);
        $user->forceFill(['locked_at' => null, 'locked_reason' => null])->save();
        $this->record('admin_user_unlocked', $user, $data['reason']);

        return back()->with('status', "{$user->name} can log in again.");
    }

    /** For a colleague who lost their phone: they set up two-step login again at their next visit. */
    public function resetTwoFactor(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:200']]);
        $this->notSelf($request, $user);
        abort_unless($user->is_platform_admin && $user->two_factor_confirmed_at, 409);
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        $this->endSessions($user);
        $this->record('admin_2fa_reset', $user, $data['reason']);
        SecurityLog::warning('admin_2fa_reset', ['target_user_id' => $user->id]);

        return back()->with('status', "Two-step login reset for {$user->name}. They set it up again at their next visit.");
    }

    private function notSelf(Request $request, User $user): void
    {
        abort_if($request->user()->is($user), 422, 'You can\'t do this to your own account.');
    }

    /** Ends every login: database sessions are deleted and "remember me" cookies stop working. */
    private function endSessions(User $user): int
    {
        $user->forceFill(['remember_token' => Str::random(60)])->save();
        if (config('session.driver') !== 'database') {
            return 0;
        }

        return DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
    }

    private function sessionCount(User $user): ?int
    {
        return config('session.driver') === 'database'
            ? DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->count() : null;
    }

    private function record(string $action, User $user, string $reason, array $extra = []): void
    {
        $this->audit->log($action, $user, after: ['reason' => $reason] + $extra, organizationId: $user->current_organization_id);
    }
}
