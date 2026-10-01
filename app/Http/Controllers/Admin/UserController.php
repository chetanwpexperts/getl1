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
 * and lock or unlock them. Each action needs a reason. Staff accounts are managed on the server only.
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
        $this->notStaff($user);
        $n = $this->endSessions($user);
        $this->record('admin_user_signed_out', $user, $data['reason'], ['sessions' => $n]);

        return back()->with('status', config('session.driver') === 'database'
            ? "{$user->name} has been signed out of every device."
            : "Saved \"remember me\" logins were ended. Open sessions end when they expire (sessions aren't stored in the database here).");
    }

    public function lock(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:200']]);
        $this->notSelf($request, $user);
        $this->notStaff($user);
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

    private function notSelf(Request $request, User $user): void
    {
        abort_if($request->user()->is($user), 422, 'You can\'t do this to your own account.');
    }

    /**
     * Staff accounts are changed only from the server (artisan), never from the console, so one
     * stolen admin session can't lock out or take over the other staff accounts.
     */
    private function notStaff(User $user): void
    {
        abort_if($user->is_platform_admin, 422, 'GetL1 staff accounts are managed on the server: php artisan getl1:admin --revoke or getl1:reset-two-step.');
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
