<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Installable app (PWA): manifest, service worker, offline page and the start page the
 * home-screen icon opens.
 *
 * Security: the service worker never stores pages or data, only the built CSS/JS, the icons
 * and the offline page. Company data is always fetched live and never kept on the device.
 */
class PwaController extends Controller
{
    /** Bump to make every installed copy drop its cache and fetch fresh assets. */
    public const VERSION = 'v2';

    public function manifest(): JsonResponse
    {
        $name = (string) config('site.name', 'GetL1');

        return response()->json([
            'id' => '/start',
            'name' => $name.' · Reverse auctions',
            'short_name' => $name,
            'description' => 'Run live reverse auctions with your suppliers and buy at L1.',
            'start_url' => '/start',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => '#f8fafc',
            'theme_color' => '#047857',
            'lang' => 'en-IN',
            'categories' => ['business', 'productivity'],
            'icons' => [
                ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600'],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function worker(): Response
    {
        $js = str_replace('__VERSION__', self::VERSION, (string) file_get_contents(resource_path('js/sw.js')));

        // Never cached by the browser or Cloudflare, so fixes reach installed apps straight away.
        return response($js, 200, ['Content-Type' => 'application/javascript; charset=utf-8', 'Cache-Control' => 'no-cache, no-store, must-revalidate']);
    }

    public function offline(): Response
    {
        return response()->view('pwa.offline')->header('Cache-Control', 'no-cache');
    }

    /** Where the home-screen icon lands: the right page for whoever is signed in, else the login. */
    public function start(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('login');
        }

        return redirect()->to(app(Auth\LoginController::class)->landing($user));
    }
}
