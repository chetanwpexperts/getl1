<?php

namespace Tests\Feature;

use App\Events\UserNotified;
use App\Jobs\SendWebPush;
use App\Models\Award;
use App\Models\BuyerSupplier;
use App\Models\Organization;
use App\Models\PushSubscription;
use App\Models\Rfq;
use App\Models\RfqInvite;
use App\Models\User;
use App\Services\Notifier;
use App\Services\RfqService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

/** The bell, live alerts, device push and the business events that raise them. */
class NotificationsTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    private Organization $buyer;
    private User $buyerUser;
    /** @var array<string, array{0: Organization, 1: User}> */
    private array $s = [];

    private const FCM = 'https://fcm.googleapis.com/fcm/send/abc123:def456';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-10-05 04:30:00', 'UTC'));
        [$this->buyer, $this->buyerUser] = $this->buyer('Acme Buyers');
        foreach (['A' => 'Alpha Packaging', 'B' => 'Beta Corrugators'] as $k => $name) {
            [$org, $user] = $this->supplier($name);
            BuyerSupplier::create(['buyer_org_id' => $this->buyer->id, 'company_name' => $name, 'status' => 'active',
                'contact_email' => $user->email, 'supplier_org_id' => $org->id]);
            $this->s[$k] = [$org, $user];
        }
    }

    private function titles(User $u): array
    {
        return $u->fresh()->notifications()->get()->pluck('data.title')->all();
    }

    private function rfq(): Rfq
    {
        $svc = app(RfqService::class);
        $rfq = $svc->saveDraft($this->buyer, $this->buyerUser, [
            'title' => 'Packing',
            'quote_deadline' => now()->addHours(3)->setTimezone('Asia/Kolkata')->format('Y-m-d\TH:i'),
            'items' => [['name' => 'Box 5-ply', 'qty' => 1000, 'unit' => 'pcs']],
            'terms' => ['payment' => 'credit_30', 'freight' => 'included'],
        ]);
        $svc->invite($rfq, $this->buyerUser, BuyerSupplier::where('buyer_org_id', $this->buyer->id)->pluck('id')->all());
        $svc->publish($rfq->fresh(), $this->buyerUser);
        app(CurrentOrganization::class)->set(null);

        return Rfq::withoutGlobalScopes()->find($rfq->id);
    }

    private function quote(Rfq $rfq, string $k, float $price): void
    {
        $invite = RfqInvite::where('rfq_id', $rfq->id)->where('supplier_org_id', $this->s[$k][0]->id)->firstOrFail();
        $this->actingAs($this->s[$k][1])->post(route('supplier.rfqs.accept', $invite->id), ['agree' => 1]);
        $item = $rfq->items()->first();
        $this->actingAs($this->s[$k][1])->post(route('supplier.rfqs.quote', $invite->id), [
            'items' => [$item->id => ['unit_price' => $price, 'gst_rate' => '18', 'freight' => 0]],
            'valid_till' => now()->addDays(10)->toDateString(),
        ])->assertSessionHasNoErrors();
        app(CurrentOrganization::class)->set(null);
    }

    public function test_sourcing_flow_raises_alerts_on_both_sides(): void
    {
        $rfq = $this->rfq();
        $this->assertContains('New RFQ from Acme Buyers', $this->titles($this->s['A'][1]));
        $this->assertContains('New RFQ from Acme Buyers', $this->titles($this->s['B'][1]));

        $this->quote($rfq, 'A', 90);
        $this->quote($rfq, 'B', 95);
        $quoteAlerts = collect($this->buyerUser->fresh()->notifications()->get())->filter(fn ($n) => str_starts_with($n->data['title'], 'New quote received'));
        $this->assertCount(2, $quoteAlerts);
        // Sealed until the deadline: no prices and no supplier names in the alert.
        foreach ($quoteAlerts as $n) {
            $this->assertStringNotContainsString('Alpha', $n->data['body']);
            $this->assertStringNotContainsString('90', $n->data['body']);
        }
        $this->assertEqualsCanonicalizing(['1 of 2', '2 of 2'], $quoteAlerts->map(fn ($n) => substr($n->data['body'], 0, 6))->values()->all());

        // A revised quote doesn't raise another alert.
        $this->quote($rfq, 'A', 89);
        $this->assertSame(2, collect($this->titles($this->buyerUser))->filter(fn ($t) => str_starts_with($t, 'New quote received'))->count());

        $this->travel(4)->hours();
        $this->actingAs($this->buyerUser)->post(route('buyer.awards.store', $rfq->id), ['supplier_org_id' => $this->s['A'][0]->id])->assertSessionHasNoErrors();
        app(CurrentOrganization::class)->set(null);
        $award = Award::withoutGlobalScopes()->where('rfq_id', $rfq->id)->firstOrFail();

        $this->assertContains("New purchase order: {$award->po_number}", $this->titles($this->s['A'][1]));
        $this->assertContains('Not selected this time', $this->titles($this->s['B'][1]));
        $this->assertNotContains('Not selected this time', $this->titles($this->s['A'][1]));

        $this->actingAs($this->s['A'][1])->post(route('supplier.orders.accept', $award->id))->assertRedirect();
        $this->assertContains("PO accepted: {$award->po_number}", $this->titles($this->buyerUser));

        // Every alert carries the company it belongs to.
        $n = $this->s['A'][1]->fresh()->notifications()->first();
        $this->assertSame($this->s['A'][0]->id, $n->data['org_id']);
    }

    public function test_alert_is_recorded_broadcast_and_pushed_after_commit(): void
    {
        Event::fake([UserNotified::class]);
        Bus::fake([SendWebPush::class]);
        config(['webpush.public_key' => 'pub', 'webpush.private_key' => 'priv', 'broadcasting.default' => 'reverb']);

        app(Notifier::class)->send([$this->buyerUser, $this->buyerUser, null], 'orders', 'Award waiting', 'Body', route('buyer.orders.index'), $this->buyer->id);

        $this->assertSame(1, $this->buyerUser->notifications()->count()); // duplicates and nulls ignored
        $id = $this->buyerUser->notifications()->value('id');
        // The socket signal carries no content at all.
        Event::assertDispatched(UserNotified::class, fn ($e) => $e->userIds === [$this->buyerUser->id]
            && $e->broadcastOn()[0]->name === 'private-user.'.$this->buyerUser->id && $e->broadcastWith() === ['new' => true]);
        Bus::assertDispatched(SendWebPush::class, fn ($j) => $j->userId === $this->buyerUser->id
            && $j->payload['url'] === "/notifications/{$id}/open" && ! isset($j->payload['org_id']));

        // Several people: one broadcast for all of them.
        $colleague = $this->memberOf($this->buyer, \App\Enums\OrgRole::BuyerUser);
        app(Notifier::class)->send([$this->buyerUser, $colleague], 'sourcing', 'T', 'B', '/dashboard', $this->buyer->id);
        Event::assertDispatched(UserNotified::class, fn ($e) => $e->userIds === [$this->buyerUser->id, $colleague->id]);
        $this->buyerUser->notifications()->where('data', 'like', '%"title":"T"%')->delete();

        // Turned off for this category: bell only, no device push.
        $this->buyerUser->forceFill(['notification_prefs' => ['orders' => ['push' => false]]])->save();
        app(Notifier::class)->send([$this->buyerUser->fresh()], 'orders', 'Another', 'Body', '/dashboard', $this->buyer->id);
        Bus::assertDispatchedTimes(SendWebPush::class, 3); // 1 + the two above, none for this one
        $this->assertSame(2, $this->buyerUser->notifications()->count());

        // Locked accounts get nothing.
        $this->buyerUser->forceFill(['locked_at' => now()])->save();
        app(Notifier::class)->send([$this->buyerUser->fresh()], 'payments', 'X', 'Y', '/dashboard');
        $this->assertSame(2, $this->buyerUser->notifications()->count());
    }

    public function test_no_push_without_keys(): void
    {
        Bus::fake([SendWebPush::class]);
        config(['webpush.public_key' => null]);
        Notifier::toUsers([$this->buyerUser], $this->buyer->id, 'orders', 'T', 'B', '/dashboard');
        Bus::assertNotDispatched(SendWebPush::class);
        $this->assertSame(1, $this->buyerUser->notifications()->count());
    }

    public function test_feed_open_and_read_all(): void
    {
        Notifier::toUsers([$this->buyerUser], $this->buyer->id, 'payments', 'New invoice to review', 'Body', route('buyer.payments.index').'?tab=review');
        Notifier::toUsers([$this->buyerUser], $this->buyer->id, 'orders', 'Second', 'Body', 'https://evil.example.com/phish');

        $feed = $this->actingAs($this->buyerUser)->getJson(route('notifications.feed'))->assertOk()->json();
        $this->assertSame(2, $feed['unread']);
        $this->assertCount(2, $feed['items']);
        $this->assertStringContainsString('/notifications/', $feed['items'][0]['url']);

        $first = $this->buyerUser->notifications()->get()->firstWhere('data.title', 'New invoice to review');
        $this->get(route('notifications.open', $first->id))->assertRedirect('/buyer/payments?tab=review');
        $this->assertNotNull($first->fresh()->read_at);

        // A foreign host in a stored URL is never followed.
        $evil = $this->buyerUser->notifications()->get()->firstWhere('data.title', 'Second');
        $this->get(route('notifications.open', $evil->id))->assertRedirect('/phish');

        Notifier::toUsers([$this->buyerUser], $this->buyer->id, 'orders', 'Third', 'Body', '/dashboard');
        $this->postJson(route('notifications.read-all'))->assertOk()->assertJson(['unread' => 0]);
        $this->assertSame(0, $this->buyerUser->unreadNotifications()->count());

        $this->get(route('notifications.index'))->assertOk()->assertSee('New invoice to review')->assertSee('Third');
    }

    public function test_people_only_see_their_own_notifications(): void
    {
        Notifier::toUsers([$this->buyerUser], $this->buyer->id, 'orders', 'Private to buyer', 'Body', '/dashboard');
        $id = $this->buyerUser->notifications()->value('id');

        $this->actingAs($this->s['A'][1])->get(route('notifications.open', $id))->assertNotFound();
        $this->actingAs($this->s['A'][1])->getJson(route('notifications.feed'))->assertJson(['unread' => 0, 'items' => []]);
        $this->actingAs($this->s['A'][1])->post(route('notifications.read-all'));
        $this->assertNull($this->buyerUser->notifications()->first()->read_at);
        $this->get(route('notifications.feed'))->assertOk();
        auth()->logout();
        $this->get(route('notifications.feed'))->assertRedirect(route('login'));
    }

    public function test_opening_switches_to_the_alerts_company_only_if_still_a_member(): void
    {
        // One person in two companies.
        $user = $this->s['A'][1];
        [$other] = $this->supplier('Second Firm');
        $other->users()->attach($user->id, ['role' => 'supplier_admin', 'is_owner' => false]);
        $user->forceFill(['current_organization_id' => $this->s['A'][0]->id])->save();

        Notifier::toOrg($other->id, 'orders', 'For second firm', 'Body', '/supplier/orders');
        $n = $user->notifications()->firstOrFail();
        $this->actingAs($user)->get(route('notifications.open', $n->id))->assertRedirect('/supplier/orders');
        $this->assertSame($other->id, $user->fresh()->current_organization_id);

        // Removed from that company later: no switch, no redirect into it.
        $user->forceFill(['current_organization_id' => $this->s['A'][0]->id])->save();
        $other->users()->detach($user->id);
        $this->actingAs($user->fresh())->get(route('notifications.open', $n->id))->assertNotFound();
        $this->assertSame($this->s['A'][0]->id, $user->fresh()->current_organization_id);
        $this->getJson(route('notifications.feed'))->assertJson(['unread' => 0, 'items' => []]);
    }

    public function test_people_who_left_a_company_get_nothing_more_from_it(): void
    {
        $ex = $this->memberOf($this->buyer, \App\Enums\OrgRole::BuyerUser);
        Notifier::toUsers([$ex], $this->buyer->id, 'payments', 'Before leaving', 'Body', '/dashboard');
        $this->assertSame(1, $ex->notifications()->count());

        // Removed from the team: old alerts about the company go, and new ones never arrive.
        $this->actingAs($this->buyerUser)->delete(route('team.destroy', $ex->id))->assertRedirect();
        $this->assertSame(0, $ex->notifications()->count());
        Notifier::toUsers([$ex], $this->buyer->id, 'payments', 'After leaving', 'Body', '/dashboard');
        Notifier::toOrg($this->buyer->id, 'payments', 'To the company', 'Body', '/dashboard');
        $this->assertSame(0, $ex->fresh()->notifications()->count());
        $this->assertSame(1, $this->buyerUser->notifications()->where('data', 'like', '%To the company%')->count());
    }

    public function test_nothing_is_sent_when_the_action_rolls_back(): void
    {
        try {
            \Illuminate\Support\Facades\DB::transaction(function () {
                Notifier::toUsers([$this->buyerUser], $this->buyer->id, 'orders', 'Ghost', 'Body', '/dashboard');
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, $this->buyerUser->notifications()->count());

        \Illuminate\Support\Facades\DB::transaction(fn () => Notifier::toUsers([$this->buyerUser], $this->buyer->id, 'orders', 'Real', 'Body', '/dashboard'));
        $this->assertSame(1, $this->buyerUser->notifications()->count());
    }

    public function test_device_subscription_only_for_real_push_services(): void
    {
        $this->actingAs($this->buyerUser);
        $keys = ['p256dh' => 'BPg2x5xKZ3Yq-abc_DEF', 'auth' => 'tBHItJI5svbpez7KI4CCXg'];

        foreach (['https://127.0.0.1/hook', 'https://getl1.com.evil.io/x', 'http://fcm.googleapis.com/fcm/send/x', 'https://fcm.googleapis.com:8443/x', 'https://evil.com/?fcm.googleapis.com'] as $bad) {
            $this->postJson(route('notifications.devices.store'), ['endpoint' => $bad, 'keys' => $keys])->assertStatus(422);
        }
        $this->assertSame(0, PushSubscription::count());

        $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0 Safari/537.36')
            ->postJson(route('notifications.devices.store'), ['endpoint' => self::FCM, 'keys' => $keys, 'content_encoding' => 'aes128gcm'])->assertOk();
        $this->postJson(route('notifications.devices.store'), ['endpoint' => self::FCM, 'keys' => $keys])->assertOk(); // same device again
        $this->assertSame(1, PushSubscription::count());
        $sub = PushSubscription::first();
        $this->assertSame($this->buyerUser->id, $sub->user_id);
        $this->assertSame('Chrome on Windows', $sub->deviceName());
        $this->assertTrue(PushSubscription::allowedEndpoint('https://web.push.apple.com/QGuQyavXutnMH'));
        $this->assertTrue(PushSubscription::allowedEndpoint('https://wns2-par02p.notify.windows.com/w/?token=x'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'push_device_added']);

        // Someone else can't remove it.
        $this->actingAs($this->s['A'][1])->deleteJson(route('notifications.devices.destroy'), ['endpoint' => self::FCM])->assertOk();
        $this->assertSame(1, PushSubscription::count());
        $this->actingAs($this->buyerUser)->deleteJson(route('notifications.devices.destroy'), ['endpoint' => self::FCM])->assertOk();
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_preferences_saved_from_my_profile(): void
    {
        $this->actingAs($this->buyerUser)->get(route('account.edit'))->assertOk()->assertSee('Alerts on this device')->assertSee('Live auctions');
        $this->put(route('notifications.preferences'), ['push' => ['sourcing' => '1', 'payments' => '1']])
            ->assertRedirect(route('account.edit').'#notifications');
        $u = $this->buyerUser->fresh();
        $this->assertTrue(Notifier::wantsPush($u, 'sourcing'));
        $this->assertFalse(Notifier::wantsPush($u, 'auctions'));
        $this->assertFalse(Notifier::wantsPush($u, 'orders'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'notification_prefs_updated']);
    }

    public function test_private_channel_is_only_for_its_owner(): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'k', 'broadcasting.connections.reverb.secret' => 's', 'broadcasting.connections.reverb.app_id' => '1']);
        $this->app->forgetInstance(\Illuminate\Broadcasting\BroadcastManager::class);
        \Illuminate\Support\Facades\Broadcast::clearResolvedInstances();
        require base_path('routes/channels.php');

        $me = $this->buyerUser;
        $this->actingAs($me)->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-user.'.$me->id])->assertOk();
        $this->actingAs($me)->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-user.'.$this->s['A'][1]->id])->assertForbidden();
    }

    public function test_bell_appears_in_the_header(): void
    {
        Notifier::toUsers([$this->buyerUser], $this->buyer->id, 'orders', 'Hello', 'Body', '/dashboard');
        $this->actingAs($this->buyerUser)->get(route('dashboard'))->assertOk()
            ->assertSee('data-bell', false)->assertSee('aria-label="Notifications"', false)
            ->assertSee('data-user="'.$this->buyerUser->id.'"', false);
    }

    public function test_webpush_keys_command_writes_env_once(): void
    {
        $dir = sys_get_temp_dir().'/getl1-env-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);
        file_put_contents("$dir/.env", "APP_NAME=GetL1\nWEBPUSH_PUBLIC_KEY=\n");
        $this->app->useEnvironmentPath($dir);

        $this->artisan('getl1:webpush-keys')->assertSuccessful();
        $env = file_get_contents("$dir/.env");
        $this->assertMatchesRegularExpression('/^WEBPUSH_PUBLIC_KEY=[A-Za-z0-9_-]{80,}$/m', $env);
        $this->assertMatchesRegularExpression('/^WEBPUSH_PRIVATE_KEY=[A-Za-z0-9_-]{40,}$/m', $env);
        $this->assertSame(1, substr_count($env, 'WEBPUSH_PUBLIC_KEY='));
        $this->assertStringContainsString('APP_NAME=GetL1', $env);

        $this->artisan('getl1:webpush-keys')->expectsOutputToContain('already exist')->assertSuccessful();
        $this->assertSame($env, file_get_contents("$dir/.env"));
        File::deleteDirectory($dir);
    }
}
