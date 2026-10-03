<?php

namespace App\Http\Controllers;

use App\Enums\OrgRole;
use App\Mail\TeamInviteMail;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Billing\PlanService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Company team: who can sign in for this company and with which role.
 * Managed by a buyer Admin, or by the owner of a supplier company. Every change is audited.
 */
class TeamController extends Controller
{
    public function __construct(private CurrentOrganization $current, private AuditLogger $audit) {}

    public function index(Request $request, PlanService $plans): View
    {
        $org = $this->current->get();
        $members = $org->users()->orderByPivot('is_owner', 'desc')->orderBy('name')->get();

        return view('team.index', [
            'org' => $org,
            'members' => $members,
            'roles' => OrgRole::forType($org->type),
            'canManage' => $this->canManage($request->user(), $org),
            'seats' => $this->seatLimit($org, $plans),
        ]);
    }

    public function store(Request $request, PlanService $plans): RedirectResponse
    {
        $org = $this->current->get();
        $this->authorizeManage($request->user(), $org);

        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
            'phone' => preg_replace('/[\s\-().]/', '', (string) $request->input('phone')) ?: null,
        ]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'regex:/^(\+?91|0)?[6-9]\d{9}$/'],
            'role' => ['required', Rule::in(array_map(fn ($r) => $r->value, OrgRole::forType($org->type)))],
        ], ['phone.regex' => 'Enter a 10-digit Indian mobile number.']);

        $limit = $this->seatLimit($org, $plans);
        if ($limit !== null && $org->users()->count() >= $limit) {
            throw ValidationException::withMessages(['email' => "Your plan includes {$limit} team members. Upgrade under Plan & billing to add more."]);
        }

        $user = User::where('email', $data['email'])->first();
        if ($user && $user->belongsToOrganization($org)) {
            throw ValidationException::withMessages(['email' => 'This person is already in your team.']);
        }
        if ($user?->is_platform_admin) {
            throw ValidationException::withMessages(['email' => 'This email can\'t be added to a company team.']);
        }

        $isNew = ! $user;
        if ($isNew) {
            // No usable password until they set one from the invitation link.
            $user = User::create([
                'name' => $data['name'], 'email' => $data['email'], 'phone' => isset($data['phone']) ? substr(preg_replace('/\D/', '', $data['phone']), -10) : null,
                'password' => Str::random(64), 'invited_at' => now(), 'invited_by' => $request->user()->id,
            ]);
        }

        $org->users()->attach($user->id, ['role' => $data['role'], 'is_owner' => false]);
        if (! $user->current_organization_id) {
            $user->forceFill(['current_organization_id' => $org->id])->save();
        }

        $this->audit->log('team_member_added', $user, after: ['email' => $user->email, 'role' => $data['role'], 'new_account' => $isNew], user: $request->user(), organizationId: $org->id);
        Mail::to($user->email)->queue(new TeamInviteMail($user, $org, $request->user(), $isNew ? self::inviteLink($user) : null));

        return back()->with('status', $isNew
            ? "Invitation sent to {$user->email}. The link to set a password works for 7 days."
            : "{$user->name} already has a GetL1 login and can now switch to {$org->name}. We've emailed them.");
    }

    public function updateRole(Request $request, User $member): RedirectResponse
    {
        $org = $this->current->get();
        $this->authorizeManage($request->user(), $org);
        $this->guardMember($request, $org, $member);
        $data = $request->validate(['role' => ['required', Rule::in(array_map(fn ($r) => $r->value, OrgRole::forType($org->type)))]]);

        $old = $member->roleIn($org);
        if ($old?->value === $data['role']) {
            return back();
        }
        $org->users()->updateExistingPivot($member->id, ['role' => $data['role']]);
        $this->audit->log('team_role_changed', $member, before: ['role' => $old?->value], after: ['role' => $data['role']], user: $request->user(), organizationId: $org->id);

        return back()->with('status', "{$member->name} is now ".OrgRole::from($data['role'])->label().'.');
    }

    public function destroy(Request $request, User $member): RedirectResponse
    {
        $org = $this->current->get();
        $this->authorizeManage($request->user(), $org);
        $this->guardMember($request, $org, $member);

        $org->users()->detach($member->id);
        // Their alerts about this company go too.
        \Illuminate\Support\Facades\DB::table('notifications')->where('notifiable_type', 'user')->where('notifiable_id', $member->id)
            ->where('organization_id', $org->id)->delete();
        if ($member->current_organization_id === $org->id) {
            $member->forceFill(['current_organization_id' => null])->save();
        }
        $this->audit->log('team_member_removed', $member, before: ['email' => $member->email], user: $request->user(), organizationId: $org->id);

        return back()->with('status', "{$member->name} no longer has access to {$org->name}.");
    }

    public function resend(Request $request, User $member): RedirectResponse
    {
        $org = $this->current->get();
        $this->authorizeManage($request->user(), $org);
        $this->guardMember($request, $org, $member);
        abort_unless($member->invited_at && ! $member->last_login_at, 422, 'This person has already joined.');

        // A new invitation time makes every earlier link stop working.
        $member->forceFill(['invited_at' => now()])->save();
        Mail::to($member->email)->queue(new TeamInviteMail($member, $org, $request->user(), self::inviteLink($member)));
        $this->audit->log('team_invite_resent', $member, user: $request->user(), organizationId: $org->id);

        return back()->with('status', "A new invitation is on its way to {$member->email}.");
    }

    /** Signed link to set a password; valid 7 days and only for the latest invitation. */
    public static function inviteLink(User $user): string
    {
        return \Illuminate\Support\Facades\URL::temporarySignedRoute('team.join', now()->addDays(7), ['user' => $user->id, 'v' => $user->invited_at->getTimestamp()]);
    }

    private function canManage(User $user, Organization $org): bool
    {
        $member = $user->organizations()->whereKey($org->id)->first()?->pivot;
        if (! $member) {
            return false;
        }

        return $org->isBuyer() ? $member->role === OrgRole::BuyerAdmin->value : (bool) $member->is_owner;
    }

    private function authorizeManage(User $user, Organization $org): void
    {
        abort_unless($this->canManage($user, $org), 403, $org->isBuyer() ? 'Only an Admin can change the team.' : 'Only the company owner can change the team.');
    }

    /** Never yourself, never the owner, and only people in this company. */
    private function guardMember(Request $request, Organization $org, User $member): void
    {
        $pivot = $org->users()->whereKey($member->id)->first()?->pivot;
        abort_unless($pivot, 404);
        abort_if($member->id === $request->user()->id, 422, 'You can\'t change your own access here.');
        abort_if((bool) $pivot->is_owner, 422, 'The company owner\'s access can\'t be changed.');
    }

    private function seatLimit(Organization $org, PlanService $plans): ?int
    {
        return $org->isBuyer() ? $plans->current($org)?->max_users : null;
    }
}
