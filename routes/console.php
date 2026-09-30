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
