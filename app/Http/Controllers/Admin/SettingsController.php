<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\PlatformSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Platform-wide auction rules and defaults. Changes apply to auctions scheduled afterwards. */
class SettingsController extends Controller
{
    public function edit(PlatformSettings $settings): View
    {
        return view('admin.settings', ['values' => $settings->all(), 'fields' => PlatformSettings::FIELDS]);
    }

    public function update(Request $request, PlatformSettings $settings, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(PlatformSettings::rules() + ['reason' => ['required', 'string', 'min:5', 'max:200']]);
        if ((int) $data['auction__default_duration_min'] > (int) $data['auction__max_duration_min']) {
            throw ValidationException::withMessages(['auction__default_duration_min' => 'The default length can\'t be longer than the longest allowed.']);
        }
        if ((int) $data['auction__default_max_extensions'] > (int) $data['auction__max_extensions_limit']) {
            throw ValidationException::withMessages(['auction__default_max_extensions' => 'The default can\'t be above the most extensions allowed.']);
        }

        [$before, $after] = $settings->save($data, $request->user());
        if (! $after) {
            return back()->with('status', 'Nothing changed.');
        }
        $audit->log('admin_settings_changed', null, before: $before, after: $after + ['reason' => $data['reason']], organizationId: null);

        return back()->with('status', count($after).' '.\Illuminate\Support\Str::plural('setting', count($after)).' saved. New auctions use them from now on.');
    }
}
