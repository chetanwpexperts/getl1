<?php

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Grant or revoke GetL1 staff (platform admin) access. Server-only by design:
 * there is no web UI that can make someone an admin.
 */
Artisan::command('getl1:admin {email} {--revoke}', function (string $email) {
    $user = User::where('email', strtolower($email))->first();

    if (! $user) {
        $this->error("No user with email {$email}.");

        return 1;
    }

    $grant = ! $this->option('revoke');
    $user->forceFill(['is_platform_admin' => $grant])->save();

    app(AuditLogger::class)->log($grant ? 'platform_admin_granted' : 'platform_admin_revoked', $user,
        after: ['via' => 'artisan'], user: $user, organizationId: $user->current_organization_id);

    $this->info(($grant ? 'Granted' : 'Revoked')." platform admin for {$user->email}.");

    return 0;
})->purpose('Grant (or --revoke) GetL1 platform admin access');

/*
 * Live auctions: open and close on time, and push the change to connected browsers.
 * Bids check the clock themselves, so this only tidies status and notifies screens.
 */
Artisan::command('auctions:tick', function (App\Services\Auction\AuctionService $auctions) {
    $r = $auctions->tick();
    if ($r['opened'] || $r['closed']) {
        $this->info("opened {$r['opened']}, closed {$r['closed']}");
    }
})->purpose('Open and close live auctions by the server clock');

Illuminate\Support\Facades\Schedule::command('auctions:tick')->everyFiveSeconds()->withoutOverlapping(1);

/*
 * Automatic next steps: supplier reminders, "quotes are in" for buyers, auction start
 * reminders and results. Each message is sent once (marker columns).
 */
Artisan::command('getl1:automations', function (App\Services\Automations $automations) {
    $r = array_filter($automations->run());
    if ($r) {
        $this->info(collect($r)->map(fn ($n, $k) => "{$k}: {$n}")->implode(', '));
    }
})->purpose('Send the automatic reminders and results that move each RFQ forward');

Illuminate\Support\Facades\Schedule::command('getl1:automations')->everyMinute()->withoutOverlapping(5);

/*
 * Send one test email right now (not queued) and report what the mail server said.
 */
Artisan::command('getl1:mail-test {to}', function (string $to) {
    if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $this->error('That is not a valid email address.');

        return 1;
    }
    $this->line('Mailer: '.config('mail.default').' · from '.config('mail.from.address').' · host '.config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port'));
    try {
        $sent = Illuminate\Support\Facades\Mail::raw(
            "This is a test email from GetL1 (".config('app.url').").\n\nIf you can read this, email delivery works.",
            fn ($m) => $m->to($to)->subject('GetL1 test email')
        );
    } catch (Throwable $e) {
        $this->error('Failed: '.$e->getMessage());

        return 1;
    }
    if ($sent === null) {
        $this->warn('Not sent: the address is blocked (test domain or not in MAIL_ALLOWLIST).');

        return 1;
    }
    $this->info("Sent to {$to}. Check the inbox (and spam folder).");

    return 0;
})->purpose('Send a test email to check the mail settings');

/*
 * Privacy: files uploaded for AI reading are only needed briefly. Delete them after 30 days.
 */
Illuminate\Support\Facades\Schedule::call(function () {
    App\Models\AiJob::withoutGlobalScopes()->whereNotNull('input_file_path')->where('created_at', '<', now()->subDays(30))
        ->chunkById(100, function ($jobs) {
            foreach ($jobs as $job) {
                Illuminate\Support\Facades\Storage::disk('local')->delete($job->input_file_path);
                $job->forceFill(['input_file_path' => null])->save();
            }
        });
})->daily()->name('ai-inputs-cleanup')->withoutOverlapping();
