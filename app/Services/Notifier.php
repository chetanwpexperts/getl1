<?php

namespace App\Services;

use App\Enums\OrgRole;
use App\Events\UserNotified;
use App\Jobs\SendWebPush;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Real-time notifications: one call records the bell entry, pushes a live pop-up to every open
 * GetL1 tab (Reverb, private channel user.{id}) and sends a device push where the user allowed it.
 *
 * Notifications never break the business action that triggered them: everything is best-effort,
 * and the live/push parts run only after the database transaction commits.
 */
class Notifier
{
    public const CATEGORIES = [
        'sourcing' => ['RFQs and quotes', 'New RFQ invitations, quotes received, questions and answers'],
        'auctions' => ['Live auctions', 'Auction scheduled, starting, results, and when you lose L1'],
        'orders' => ['Awards and purchase orders', 'Approvals, POs issued and accepted, deliveries, counter-offers'],
        'payments' => ['Invoices and payments', 'Invoices to review, approvals, payments and MSME due dates'],
    ];

    /** Users of a company, optionally only some roles. */
    public static function orgUsers(int $orgId, array $roles = []): Collection
    {
        $org = Organization::find($orgId);
        if (! $org) {
            return collect();
        }
        $q = $org->users();
        if ($roles) {
            $q->wherePivotIn('role', array_map(fn ($r) => $r instanceof OrgRole ? $r->value : $r, $roles));
        }

        return $q->get();
    }

    /**
     * Record and deliver one alert. Runs after the surrounding transaction commits (straight away
     * if there is none), so an action that rolls back never notifies, and nothing here can break or
     * slow down the action's own database work.
     *
     * Only people who are still members of $orgId receive it (a removed colleague never does).
     *
     * @param  iterable<User|null>  $users
     */
    public function send(iterable $users, string $category, string $title, string $body, string $url, ?int $orgId = null): void
    {
        $list = [];
        foreach ($users as $user) {
            if ($user instanceof User && ! $user->locked_at) {
                $list[$user->id] = $user;
            }
        }
        if (! $list) {
            return;
        }
        DB::afterCommit(fn () => $this->deliver($list, $category, mb_substr($title, 0, 120), mb_substr($body, 0, 300), $url, $orgId));
    }

    /** @param  array<int, User>  $users */
    private function deliver(array $users, string $category, string $title, string $body, string $url, ?int $orgId): void
    {
        try {
            if ($orgId) {
                $members = DB::table('org_user')->where('organization_id', $orgId)->whereIn('user_id', array_keys($users))->pluck('user_id')->all();
                $users = array_intersect_key($users, array_flip($members));
            }
            if (! $users) {
                return;
            }

            $now = now();
            $rows = [];
            foreach ($users as $user) {
                $rows[$user->id] = [
                    'id' => (string) Str::uuid(),
                    'type' => 'app_alert',
                    'notifiable_type' => 'user',
                    'notifiable_id' => $user->id,
                    'organization_id' => $orgId,
                    'data' => json_encode(['category' => $category, 'title' => $title, 'body' => $body, 'url' => $url, 'org_id' => $orgId], JSON_UNESCAPED_UNICODE),
                    'read_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('notifications')->insert(array_values($rows));
        } catch (\Throwable $e) {
            Log::warning('notify_failed', ['category' => $category, 'org_id' => $orgId, 'error' => $e->getMessage()]);

            return;
        }

        // Live signal: one call for everyone (100 channels per call). It carries no content; each
        // open tab fetches its own new alerts, so nothing private travels over the socket.
        if (config('broadcasting.default') !== 'null') {
            foreach (array_chunk(array_keys($users), 100) as $ids) {
                rescue(fn () => broadcast(new UserNotified($ids)), null, false);
            }
        }

        if (config('webpush.public_key') && config('webpush.private_key')) {
            foreach ($users as $user) {
                if (self::wantsPush($user, $category)) {
                    $id = $rows[$user->id]['id'];
                    rescue(fn () => SendWebPush::dispatch($user->id, ['id' => $id, 'title' => $title, 'body' => $body, 'url' => route('notifications.open', $id, false)]), null, false);
                }
            }
        }
    }

    /** Everyone in a company (optionally some roles). Used for supplier-side alerts. */
    public static function toOrg(?int $orgId, string $category, string $title, string $body, string $url, array $roles = []): void
    {
        if (! $orgId) {
            return;
        }
        rescue(fn () => app(self::class)->send(self::orgUsers($orgId, $roles), $category, $title, $body, $url, $orgId), null, false);
    }

    /** Specific people in one company (e.g. the buyer team on an RFQ, the approvers). */
    public static function toUsers(iterable $users, ?int $orgId, string $category, string $title, string $body, string $url): void
    {
        rescue(fn () => app(self::class)->send($users, $category, $title, $body, $url, $orgId), null, false);
    }

    public static function wantsPush(User $user, string $category): bool
    {
        return (bool) data_get($user->notification_prefs, "{$category}.push", true);
    }
}
