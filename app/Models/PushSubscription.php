<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A browser/device that allowed GetL1 notifications for one user. */
class PushSubscription extends Model
{
    public const MAX_PER_USER = 10;

    /**
     * Browser push services we deliver to. Anything else is refused, so a crafted "endpoint" can
     * never make the server call an internal or third-party address.
     */
    public const ALLOWED_HOSTS = [
        'fcm.googleapis.com', 'android.googleapis.com',          // Chrome, Edge (Chromium), Samsung
        'updates.push.services.mozilla.com',                      // Firefox
        'web.push.apple.com',                                     // Safari (macOS, iOS 16.4+)
        '*.notify.windows.com',                                   // legacy Edge
        '*.push.apple.com',
    ];

    public static function allowedEndpoint(string $endpoint): bool
    {
        $p = parse_url($endpoint);
        if (($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['port'])) {
            return false;
        }
        $host = strtolower($p['host']);
        foreach (self::ALLOWED_HOSTS as $allowed) {
            if ($host === $allowed || (str_starts_with($allowed, '*.') && str_ends_with($host, substr($allowed, 1)))) {
                return true;
            }
        }

        return false;
    }

    /** "Chrome on Windows" etc., for the device list and audit log. */
    public function deviceName(): string
    {
        $ua = (string) $this->user_agent;
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Firefox') => 'Firefox',
            str_contains($ua, 'Chrome') => 'Chrome',
            str_contains($ua, 'Safari') => 'Safari',
            default => 'Browser',
        };
        $os = match (true) {
            str_contains($ua, 'Android') => 'Android',
            (bool) preg_match('/iPhone|iPad/', $ua) => 'iPhone/iPad',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') => 'Mac',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'device',
        };

        return "{$browser} on {$os}";
    }

    protected $fillable = ['user_id', 'endpoint_hash', 'endpoint', 'public_key', 'auth_token', 'content_encoding', 'user_agent', 'last_used_at'];

    protected $hidden = ['endpoint', 'public_key', 'auth_token'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
