<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

/**
 * Writes to storage/logs/security-YYYY-MM-DD.log (90-day retention).
 *
 * For events worth a security review: failed logins, lockouts, denied access,
 * CSRF failures, rate-limit hits, rejected uploads. Never pass passwords,
 * tokens or file contents in $context.
 */
class SecurityLog
{
    public static function warning(string $event, array $context = []): void
    {
        Log::channel('security')->warning($event, self::context($context));
    }

    public static function info(string $event, array $context = []): void
    {
        Log::channel('security')->info($event, self::context($context));
    }

    private static function context(array $context): array
    {
        return array_merge([
            'user_id' => Auth::id(),
            'ip' => Request::ip(),
            'method' => Request::method(),
            'path' => '/'.ltrim(Request::path(), '/'),
            'ua' => substr((string) Request::userAgent(), 0, 180),
        ], $context);
    }
}
