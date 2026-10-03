<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Services\SecurityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/** The signed-in person's own details and password (My profile). */
class AccountController extends Controller
{
    public function edit(Request $request): View
    {
        $user = $request->user();

        return view('account.profile', [
            'user' => $user,
            'memberships' => $user->organizations()->orderBy('name')->get(),
            'devices' => \App\Models\PushSubscription::where('user_id', $user->id)->latest('updated_at')->get(),
        ]);
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        $request->merge(['phone' => preg_replace('/[\s\-().]/', '', (string) $request->input('phone'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'regex:/^(\+?91|0)?[6-9]\d{9}$/'],
        ], ['phone.regex' => 'Enter a 10-digit Indian mobile number.']);
        $data['phone'] = substr(preg_replace('/\D/', '', $data['phone']), -10);

        $before = $user->only(['name', 'phone']);
        $user->fill($data);
        if (! $user->isDirty()) {
            return back()->with('status', 'Nothing changed.');
        }
        $user->save();
        $audit->log('profile_updated', $user, before: $before, after: $user->only(['name', 'phone']), user: $user, organizationId: $user->current_organization_id);

        return back()->with('status', 'Your details are saved.');
    }

    public function password(Request $request, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        $request->validateWithBag('password', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8), 'different:current_password'],
        ], ['current_password.current_password' => 'That isn\'t your current password.']);

        // New password, and every other browser or phone signed in to this account is signed out.
        $user->forceFill(['password' => Hash::make((string) $request->input('password')), 'remember_token' => Str::random(60)])->save();
        $request->session()->regenerate();
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        }
        $audit->log('password_changed', $user, user: $user, organizationId: $user->current_organization_id);
        SecurityLog::info('password_changed', ['user_id' => $user->id]);

        return back()->with('status', 'Password changed. Other devices have been signed out.');
    }
}
