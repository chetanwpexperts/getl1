<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\PushSubscription;
use App\Services\AuditLogger;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\View\View;

/**
 * The bell: list, open (mark read and go), mark all read, device push on/off and preferences.
 * A user only ever sees and touches their own notifications and devices.
 */
class NotificationController extends Controller
{
    public const PER_PAGE = 25;

    public function index(Request $request): View
    {
        $user = $request->user();

        return view('notifications.index', [
            'notifications' => $user->visibleNotifications()->latest()->paginate(self::PER_PAGE),
            'orgNames' => $user->organizations()->pluck('name', 'organizations.id'),
            'unread' => $user->visibleNotifications()->whereNull('read_at')->count(),
        ]);
    }

    /** Latest entries for the bell dropdown (also the polling fallback when the socket is down). */
    public function feed(Request $request): JsonResponse
    {
        $user = $request->user();
        $items = $user->visibleNotifications()->latest()->limit(10)->get()->map(fn (DatabaseNotification $n) => self::present($n));

        return response()->json(['unread' => $user->visibleNotifications()->whereNull('read_at')->count(), 'items' => $items])
            ->header('Cache-Control', 'no-store');
    }

    public function open(Request $request, string $id): RedirectResponse
    {
        $user = $request->user();
        $n = $user->visibleNotifications()->whereKey($id)->firstOrFail();
        $n->markAsRead();

        // The alert may belong to another company this person works for: switch to it first.
        $orgId = (int) ($n->data['org_id'] ?? 0);
        if ($orgId && $orgId !== (int) $user->current_organization_id) {
            $org = Organization::find($orgId);
            if (! $org || ! $user->belongsToOrganization($org)) {
                return redirect()->route('notifications.index')->with('status', 'You no longer have access to that company.');
            }
            $user->switchOrganization($org);
        }

        return redirect()->to(self::safePath($n->data['url'] ?? null));
    }

    public function readAll(Request $request): RedirectResponse|JsonResponse
    {
        $request->user()->notifications()->whereNull('read_at')->update(['read_at' => now()]);

        return $request->expectsJson() ? response()->json(['unread' => 0]) : back()->with('status', 'All notifications marked as read.');
    }

    /** Save this browser's push subscription (after the person clicked "Turn on" and allowed it). */
    public function subscribe(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:1000', 'url:https'],
            'keys.p256dh' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_\-=+\/]+$/'],
            'keys.auth' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_\-=+\/]+$/'],
            'content_encoding' => ['nullable', 'in:aes128gcm,aesgcm'],
        ]);
        abort_unless(PushSubscription::allowedEndpoint($data['endpoint']), 422, 'This browser\'s push service is not supported.');
        $user = $request->user();
        $hash = hash('sha256', $data['endpoint']);
        $known = PushSubscription::where('user_id', $user->id)->where('endpoint_hash', $hash)->exists();
        if (! $known && PushSubscription::where('user_id', $user->id)->count() >= PushSubscription::MAX_PER_USER) {
            PushSubscription::where('user_id', $user->id)->orderByRaw('COALESCE(last_used_at, created_at)')->first()?->delete();
        }

        $sub = PushSubscription::updateOrCreate(['endpoint_hash' => $hash], [
            'user_id' => $user->id,
            'endpoint' => $data['endpoint'],
            'public_key' => $data['keys']['p256dh'],
            'auth_token' => $data['keys']['auth'],
            'content_encoding' => $data['content_encoding'] ?? 'aes128gcm',
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);
        if ($sub->wasRecentlyCreated) {
            $audit->log('push_device_added', $user, after: ['device' => $sub->deviceName()], user: $user, organizationId: $user->current_organization_id);
        }

        return response()->json(['ok' => true]);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $request->validate(['endpoint' => ['required', 'string', 'max:1000']]);
        PushSubscription::where('user_id', $request->user()->id)
            ->where('endpoint_hash', hash('sha256', (string) $request->input('endpoint')))->delete();

        return response()->json(['ok' => true]);
    }

    public function preferences(Request $request, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        $prefs = [];
        foreach (array_keys(Notifier::CATEGORIES) as $cat) {
            $prefs[$cat] = ['push' => $request->boolean("push.{$cat}")];
        }
        $before = $user->notification_prefs;
        $user->forceFill(['notification_prefs' => $prefs])->save();
        $audit->log('notification_prefs_updated', $user, before: ['prefs' => $before], after: ['prefs' => $prefs], user: $user, organizationId: $user->current_organization_id);

        return redirect()->to(route('account.edit').'#notifications')->with('status', 'Notification settings saved.');
    }

    public static function present(DatabaseNotification $n): array
    {
        return [
            'id' => $n->id,
            'category' => $n->data['category'] ?? null,
            'title' => $n->data['title'] ?? '',
            'body' => $n->data['body'] ?? '',
            'url' => route('notifications.open', $n->id),
            'unread' => $n->read_at === null,
            'ago' => $n->created_at?->diffForHumans(short: true),
            'ts' => $n->created_at?->getTimestampMs(),
        ];
    }

    /** Only ever redirect inside GetL1: keep the path and query, drop any host. */
    public static function safePath(?string $url): string
    {
        $parts = parse_url((string) $url);
        $path = $parts['path'] ?? '';
        if ($path === '' || ! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return route('dashboard');
        }

        return $path.(isset($parts['query']) ? '?'.$parts['query'] : '').(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }
}
