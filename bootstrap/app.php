<?php

use App\Http\Middleware\EnsureOrganization;
use App\Http\Middleware\EnsureOrganizationType;
use App\Http\Middleware\EnsureOrgRole;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\SecurityHeaders;
use App\Services\SecurityLog;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Private channel auth for live auctions: signed-in users only, rate-limited.
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['web', 'auth', 'throttle:120,1']])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [SecurityHeaders::class]);
        $middleware->append(\App\Http\Middleware\WebsiteOnly::class);

        $middleware->alias([
            'org' => EnsureOrganization::class,
            'org.type' => EnsureOrganizationType::class,
            'org.role' => EnsureOrgRole::class,
            'platform.admin' => EnsurePlatformAdmin::class,
            'admin.2fa' => \App\Http\Middleware\EnsureAdminTwoFactor::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Record denied access, CSRF failures and rate-limit hits in the security log.
        // Returning null keeps Laravel's normal error response.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            $status = $e->getStatusCode();

            if (in_array($status, [403, 419, 429], true)) {
                SecurityLog::warning(match ($status) {
                    403 => 'access_denied',
                    419 => 'csrf_token_mismatch',
                    429 => 'rate_limited',
                }, ['status' => $status]);
            }

            return null;
        });
    })->create();
