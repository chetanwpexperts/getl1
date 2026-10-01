<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\FileGuard;
use App\Services\WebsiteSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\View\View;

/** Admin → Website: branding, home page text, contact and legal details, social links, analytics. */
class WebsiteController extends Controller
{
    public function edit(WebsiteSettings $settings): View
    {
        return view('admin.website', ['saved' => $settings->saved(), 'sections' => WebsiteSettings::SECTIONS]);
    }

    public function update(Request $request, WebsiteSettings $settings, FileGuard $guard, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(WebsiteSettings::rules(), [
            'ga4_id.regex' => 'Use the Measurement ID that starts with G-, e.g. G-ABC123XYZ.',
            'google_verification.regex' => 'Paste only the content="…" value from the Search Console tag.',
            '*.regex' => 'Enter a valid phone number.',
        ]);
        $files = collect(WebsiteSettings::fields())->filter(fn ($f) => $f[0] === 'image')->keys()
            ->mapWithKeys(fn ($k) => [$k => $request->file($k)])->filter()->all();

        [$before, $after] = $settings->save(Arr::except($data, array_keys($files)), $files, $request->user(), $guard);
        if (! $after) {
            return back()->with('status', 'Nothing changed.');
        }
        $audit->log('admin_website_changed', null, before: $before, after: $after, organizationId: null);
        $settings->apply();

        return back()->with('status', count($after).' '.\Illuminate\Support\Str::plural('change', count($after)).' saved. The website shows them now.');
    }
}
