<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Creates the server's Web Push (VAPID) key pair and writes it to .env, once.
 * Run on each server after deploying notifications. Safe to run again: existing keys are kept,
 * because replacing them would silently cut off every device that already allowed notifications.
 */
class WebPushKeys extends Command
{
    protected $signature = 'getl1:webpush-keys {--show : Print the public key only}';

    protected $description = 'Create the browser push (VAPID) keys in .env if they are missing';

    public function handle(): int
    {
        $envPath = app()->environmentFilePath();
        $env = is_file($envPath) ? (string) file_get_contents($envPath) : '';
        $has = fn (string $k) => (bool) preg_match('/^'.$k.'=["\']?[A-Za-z0-9_\-]{20,}/m', $env);

        if ($has('WEBPUSH_PUBLIC_KEY') !== $has('WEBPUSH_PRIVATE_KEY')) {
            $this->error('Only one of the two push keys is in .env. Not changing anything: fix .env by hand (both keys or neither).');

            return self::FAILURE;
        }
        if ($has('WEBPUSH_PUBLIC_KEY') && $has('WEBPUSH_PRIVATE_KEY')) {
            $this->info('Push keys already exist in .env. Nothing changed.');
            if ($this->option('show')) {
                preg_match('/^WEBPUSH_PUBLIC_KEY=(\S+)/m', $env, $m);
                $this->line('Public key: '.trim($m[1] ?? '', '"'));
            }

            return self::SUCCESS;
        }
        if (! is_writable($envPath)) {
            $this->error('.env is not writable by this user. Run as the site user.');

            return self::FAILURE;
        }

        $keys = VAPID::createVapidKeys();
        // Drop empty placeholders first, then append. The private key is never printed.
        $env = preg_replace('/^WEBPUSH_(PUBLIC|PRIVATE)_KEY=.*\R?/m', '', $env);
        $lines = "\n# Browser push (created by getl1:webpush-keys). Never change or share the private key.\n"
            ."WEBPUSH_PUBLIC_KEY={$keys['publicKey']}\n"
            ."WEBPUSH_PRIVATE_KEY={$keys['privateKey']}\n";
        if (! preg_match('/^WEBPUSH_SUBJECT=/m', $env)) {
            $lines .= 'WEBPUSH_SUBJECT=mailto:'.config('site.email', 'support@getl1.com')."\n";
        }
        file_put_contents($envPath, rtrim($env)."\n".$lines, LOCK_EX);

        if (app()->configurationIsCached()) {
            $this->call('config:cache');
        }
        \App\Services\SecurityLog::info('webpush_keys_created', ['via' => 'artisan']);
        $this->info('Push keys created and saved to .env.');

        return self::SUCCESS;
    }
}
