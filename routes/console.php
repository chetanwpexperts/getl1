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

/*
 * Moves sign-ups from the old coming-soon page (waitlist.csv) into Leads. Safe to re-run:
 * an email that is already a lead is skipped.
 */
Artisan::command('getl1:import-waitlist {path}', function (string $path) {
    if (! is_readable($path)) {
        $this->error("Can't read {$path}");

        return 1;
    }
    $fh = fopen($path, 'r');
    $header = fgetcsv($fh, 0, ',', '"', '\\') ?: [];
    $added = $skipped = 0;
    while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        $r = array_combine(array_slice($header, 0, count($row)), array_slice($row, 0, count($header))) ?: [];
        $email = strtolower(trim((string) ($r['email'] ?? '')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || App\Models\Lead::where('email', $email)->exists()) {
            $skipped++;

            continue;
        }
        $clean = fn ($v, $n) => mb_substr(ltrim(trim((string) $v), "='+-@"), 0, $n) ?: null;
        $lead = App\Models\Lead::create([
            'name' => 'Waitlist sign-up',
            'company' => $clean($r['company'] ?? '', 160) ?? Illuminate\Support\Str::after($email, '@'),
            'email' => $email,
            'city' => $clean($r['city'] ?? '', 80),
            'interest' => ($r['role'] ?? '') === 'supplier' ? 'supplier' : 'buyer',
            'source' => $clean($r['source'] ?? '', 60) ?? 'waitlist',
            'message' => 'Joined the waitlist on the coming-soon page.',
            'ip' => $clean($r['ip'] ?? '', 45),
        ]);
        if ($ts = strtotime((string) ($r['created_at'] ?? ''))) {
            $lead->forceFill(['created_at' => date('Y-m-d H:i:s', $ts)])->saveQuietly();
        }
        $added++;
    }
    fclose($fh);
    $this->info("Waitlist: {$added} added, {$skipped} skipped.");

    return 0;
})->purpose('Import the old coming-soon waitlist CSV into Leads');

/** Recent website leads, for website mode where the admin console isn't served. */
Artisan::command('getl1:leads {--days=30}', function () {
    $rows = App\Models\Lead::where('created_at', '>=', now()->subDays((int) $this->option('days')))->latest()->get()
        ->map(fn ($l) => [$l->created_at->ist()->format('d M H:i'), $l->interest, $l->company, $l->name, $l->email, $l->phone, $l->city, $l->status]);
    $this->table(['When (IST)', 'Type', 'Company', 'Name', 'Email', 'Phone', 'City', 'Status'], $rows);

    return 0;
})->purpose('List recent website leads');

/*
 * Health: the scheduler leaves a heartbeat every minute (the Health page checks it), and every
 * 5 minutes getl1:health emails an alert when a check fails, once an hour per problem, plus an
 * "all clear" when it recovers. Sent directly, not queued, in case the queue is what's broken.
 */
Illuminate\Support\Facades\Schedule::call(fn () => Illuminate\Support\Facades\Cache::put(App\Services\SystemHealth::HEARTBEAT_KEY, now()->getTimestamp(), 3600))
    ->everyMinute()->name('health-heartbeat');

Artisan::command('getl1:health', function (App\Services\SystemHealth $health, App\Services\PlatformSettings $settings) {
    $checks = $health->checks();
    $failing = collect($checks)->where('status', 'fail')->keyBy('key');
    $was = Illuminate\Support\Facades\Cache::get('health:failing', []);
    $to = $settings->get('alerts.email') ?: config('site.leads_to');

    $new = $failing->filter(fn ($c) => Illuminate\Support\Facades\Cache::add('health:alerted:'.$c['key'], true, 3600));
    $recovered = collect($was)->diff($failing->keys());
    Illuminate\Support\Facades\Cache::put('health:failing', $failing->keys()->all(), 86400);

    if ($to && ($new->isNotEmpty() || $recovered->isNotEmpty())) {
        try {
            Illuminate\Support\Facades\Mail::to($to)->send(new App\Mail\HealthAlertMail($checks, $new->pluck('label')->all(),
                collect($checks)->whereIn('key', $recovered)->pluck('label')->all()));
        } catch (Throwable $e) {
            Illuminate\Support\Facades\Log::error('health_alert_mail_failed', ['error' => $e->getMessage()]);
        }
    }
    $recovered->each(fn ($k) => Illuminate\Support\Facades\Cache::forget('health:alerted:'.$k));

    foreach ($checks as $c) {
        $this->line(str_pad(strtoupper($c['status']), 5).' '.$c['label'].': '.$c['detail']);
    }

    return $failing->isEmpty() ? 0 : 1;
})->purpose('Check system health and email an alert if something fails');
Illuminate\Support\Facades\Schedule::command('getl1:health')->everyFiveMinutes()->withoutOverlapping(4);

/** A staff member lost their phone and recovery codes: they set up two-step login again at their next visit. */
Artisan::command('getl1:reset-two-step {email}', function (string $email) {
    $user = User::where('email', strtolower($email))->first();
    if (! $user) {
        $this->error("No user with email {$email}.");

        return 1;
    }
    $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null,
        'remember_token' => Illuminate\Support\Str::random(60)])->save();
    if (config('session.driver') === 'database') {
        Illuminate\Support\Facades\DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
    }
    app(AuditLogger::class)->log('admin_2fa_reset', $user, after: ['via' => 'artisan'], user: $user, organizationId: $user->current_organization_id);
    App\Services\SecurityLog::warning('admin_2fa_reset', ['target_user_id' => $user->id, 'via' => 'artisan']);
    $this->info("Two-step login reset for {$user->email}. They set it up again at their next visit to /admin.");

    return 0;
})->purpose('Reset a staff member\'s two-step login (lost phone)');
