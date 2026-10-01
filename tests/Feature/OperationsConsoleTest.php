<?php

namespace Tests\Feature;

use App\Enums\AuctionStatus;
use App\Enums\RfqStatus;
use App\Mail\AuctionNoticeMail;
use App\Mail\HealthAlertMail;
use App\Models\Auction;
use App\Models\AuditLog;
use App\Models\BidRejection;
use App\Models\BuyerSupplier;
use App\Models\Organization;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\User;
use App\Services\Auction\AuctionService;
use App\Services\PlatformSettings;
use App\Services\RfqService;
use App\Services\SupplierTrust;
use App\Services\SystemHealth;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class OperationsConsoleTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    private Organization $buyerOrg;
    private User $buyerUser;
    /** @var array<string, array{0: Organization, 1: User}> */
    private array $s = [];
    private Rfq $rfq;
    private Carbon $t0;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->t0 = Carbon::parse('2026-10-05 04:30:00', 'UTC');
        $this->travelTo($this->t0);
        [$this->buyerOrg, $this->buyerUser] = $this->buyer('Acme Buyers');
        foreach (['A' => 'Alpha Packaging', 'B' => 'Beta Corrugators'] as $k => $name) {
            [$org, $user] = $this->supplier($name);
            BuyerSupplier::create(['buyer_org_id' => $this->buyerOrg->id, 'company_name' => $name, 'status' => 'active',
                'contact_email' => $user->email, 'supplier_org_id' => $org->id]);
            $this->s[$k] = [$org, $user];
        }
        $svc = app(RfqService::class);
        $rfq = $svc->saveDraft($this->buyerOrg, $this->buyerUser, [
            'title' => 'Corrugated boxes',
            'quote_deadline' => $this->t0->copy()->addHours(3)->setTimezone('Asia/Kolkata')->format('Y-m-d\TH:i'),
            'items' => [['name' => 'Box 5-ply', 'qty' => 10000, 'unit' => 'pcs']],
        ]);
        $svc->invite($rfq, $this->buyerUser, BuyerSupplier::where('buyer_org_id', $this->buyerOrg->id)->pluck('id')->all());
        $svc->publish($rfq->fresh(), $this->buyerUser);
        $this->rfq = $rfq->fresh();
        $this->admin = $this->admin();
    }

    /** A live 30-minute auction: A opens at 1,00,000, B at 1,05,000. */
    private function live(): Auction
    {
        foreach ([['A', 100000, 10], ['B', 105000, 20]] as [$k, $total, $min]) {
            Quote::create(['rfq_id' => $this->rfq->id, 'supplier_org_id' => $this->s[$k][0]->id, 'submitted_by' => $this->s[$k][1]->id,
                'total' => $total, 'valid_till' => $this->t0->copy()->addDays(30)->toDateString(), 'submitted_at' => $this->t0->copy()->addMinutes($min)]);
        }
        $this->travelTo($this->t0->copy()->addHours(4));
        $a = app(AuctionService::class)->schedule($this->rfq->fresh(), $this->buyerUser, [
            'starts_at' => now()->addMinutes(10)->setTimezone('Asia/Kolkata')->format('Y-m-d\TH:i'), 'duration_min' => 30,
            'min_decrement_type' => 'percent', 'min_decrement_value' => '0.5', 'max_decrement_pct' => '10',
            'extend_window_sec' => 0, 'extend_by_sec' => 60, 'max_extensions' => 0, 'visibility' => 'rank_only',
        ]);
        app(CurrentOrganization::class)->set(null);
        $this->travelTo($a->starts_at->copy()->addSecond());

        return $a;
    }

    private function bid(string $k, Auction $a, string $amount)
    {
        return $this->actingAs($this->s[$k][1])->postJson(route('supplier.auctions.bid', $a->id), ['amount' => $amount, 'idempotency_key' => (string) Str::uuid()]);
    }

    private function fresh(Auction $a): Auction
    {
        return Auction::withoutGlobalScopes()->findOrFail($a->id);
    }

    public function test_pause_stops_the_clock_and_bids_then_resume_gives_the_time_back(): void
    {
        $a = $this->live();
        $this->travel(5)->minutes(); // 25 minutes left

        $this->asAdmin($this->admin)->post(route('admin.auctions.pause', $a->id), ['reason' => 'x'])->assertSessionHasErrors('reason');
        $this->asAdmin($this->admin)->post(route('admin.auctions.pause', $a->id), ['reason' => 'Suppliers report the page is not loading'])->assertRedirect();
        $this->assertTrue($this->fresh($a)->isPaused());
        Mail::assertQueued(AuctionNoticeMail::class, fn ($m) => $m->kind === 'paused' && $m->supplierOrgId === null);
        Mail::assertQueued(AuctionNoticeMail::class, fn ($m) => $m->kind === 'paused' && $m->supplierOrgId === $this->s['A'][0]->id);

        $this->bid('A', $a, '99000')->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertSame('paused', BidRejection::latest('id')->value('reason'));
        $this->actingAs($this->s['A'][1])->getJson(route('supplier.auctions.state', $a->id))->assertOk()
            ->assertJsonPath('paused', true)->assertJsonPath('paused_remaining_ms', 25 * 60 * 1000 - 1000);

        // Paused well past the original end: the auction must not close.
        $this->travel(40)->minutes();
        $this->artisan('auctions:tick');
        $this->assertSame(AuctionStatus::Live, $this->fresh($a)->status);

        $this->asAdmin($this->admin)->post(route('admin.auctions.resume', $a->id))->assertRedirect();
        $after = $this->fresh($a);
        $this->assertNull($after->paused_at);
        $this->assertEqualsWithDelta(25 * 60 - 1, now()->diffInSeconds($after->ends_at, true), 2, 'Same time left as when paused');
        $this->assertSame(40 * 60, $after->paused_seconds);
        $this->bid('A', $a, '99000')->assertOk();
        $this->assertTrue(AuditLog::where('action', 'auction_resumed_by_getl1')->where('user_id', $this->admin->id)->exists());
    }

    public function test_add_time_and_cancel_with_reason(): void
    {
        $a = $this->live();
        $end = $this->fresh($a)->ends_at;

        $this->asAdmin($this->admin)->post(route('admin.auctions.time', $a->id), ['minutes' => 7, 'reason' => 'Phone-in bid dispute'])->assertSessionHasErrors('auction');
        $this->asAdmin($this->admin)->post(route('admin.auctions.time', $a->id), ['minutes' => 5, 'reason' => 'Supplier lost connection'])->assertRedirect();
        $this->assertTrue($end->copy()->addMinutes(5)->equalTo($this->fresh($a)->ends_at));

        $this->asAdmin($this->admin)->post(route('admin.auctions.cancel', $a->id), ['reason' => 'Server problem during the auction'])->assertSessionHasErrors('confirm');
        $this->asAdmin($this->admin)->post(route('admin.auctions.cancel', $a->id), ['reason' => 'Server problem during the auction', 'confirm' => 'CANCEL'])->assertRedirect();
        $c = $this->fresh($a);
        $this->assertSame(AuctionStatus::Cancelled, $c->status);
        $this->assertStringContainsString('Cancelled by GetL1: Server problem', $c->cancel_reason);
        $this->assertSame(RfqStatus::Published, Rfq::withoutGlobalScopes()->find($this->rfq->id)->status, 'Buyer can schedule again');
        $this->assertSame(0, app(\App\Services\Billing\PlanService::class)->auctionsUsed($this->buyerOrg), 'Does not count against the plan');
        Mail::assertQueued(AuctionNoticeMail::class, fn ($m) => $m->kind === 'cancelled');
        $this->bid('A', $a, '99000')->assertUnprocessable();

        // Nothing more can be done to a finished auction.
        $this->asAdmin($this->admin)->post(route('admin.auctions.pause', $a->id), ['reason' => 'Trying again'])->assertSessionHasErrors('auction');
    }

    public function test_monitor_shows_running_auctions_and_every_bid_including_refused(): void
    {
        $a = $this->live();
        $this->bid('A', $a, '99000')->assertOk();
        $this->bid('B', $a, '105000')->assertUnprocessable(); // must beat own price by 0.5%
        $this->assertSame('too_high', BidRejection::latest('id')->value('reason'));

        $this->asAdmin($this->admin)->get(route('admin.auctions'))->assertOk()->assertSee('Corrugated boxes')->assertSee('Acme Buyers')->assertSee('Running now');
        $this->asAdmin($this->admin)->getJson(route('admin.auctions.live'))->assertOk()->assertJsonStructure(['v', 'refresh_at', 'server_time']);
        $this->asAdmin($this->admin)->get(route('admin.auctions.show', $a->id))->assertOk()
            ->assertSee('Alpha Packaging')->assertSee('Beta Corrugators')->assertSee('REFUSED')->assertSee('Emergency controls')->assertSee('Pause now');
        $this->asAdmin($this->admin)->getJson(route('admin.auctions.show.live', $a->id))->assertOk()->assertJsonStructure(['v', 'refresh_at']);

        // Suppliers never see refusals or other names; customers can't open the monitor.
        $this->actingAs($this->s['B'][1])->getJson(route('supplier.auctions.state', $a->id))->assertOk()->assertDontSee('Alpha Packaging');
        $this->actingAs($this->buyerUser)->get(route('admin.auctions'))->assertForbidden();
        session()->forget(\App\Http\Middleware\EnsureAdminTwoFactor::SESSION);
        $this->actingAs($this->admin)->get(route('admin.auctions'))->assertRedirect(route('admin.2fa.challenge'));
    }

    public function test_staff_can_lock_unlock_and_sign_out_people(): void
    {
        $target = $this->s['A'][1];
        $this->asAdmin($this->admin)->get(route('admin.users.index', ['q' => $target->email]))->assertOk()->assertSee($target->email);
        $this->asAdmin($this->admin)->get(route('admin.users.show', $target->id))->assertOk()->assertSee('Lock this person');

        $this->asAdmin($this->admin)->post(route('admin.users.lock', $target->id), ['reason' => 'Shared login with a competitor'])->assertRedirect();
        $this->assertNotNull($target->fresh()->locked_at);
        $this->actingAs($target->fresh())->get('/dashboard')->assertRedirect(route('login'));
        $this->app['auth']->forgetGuards();
        $this->post('/login', ['email' => $target->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->asAdmin($this->admin)->post(route('admin.users.unlock', $target->id), ['reason' => 'Checked with the owner'])->assertRedirect();
        $this->app['auth']->forgetGuards();
        $this->post('/login', ['email' => $target->email, 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticatedAs($target->fresh());

        $this->asAdmin($this->admin)->post(route('admin.users.signout', $target->id), ['reason' => 'Lost phone'])->assertRedirect();
        $this->asAdmin($this->admin)->post(route('admin.users.lock', $this->admin->id), ['reason' => 'Testing myself'])->assertStatus(422);

        // Staff accounts can't be touched from the console (a stolen session can't lock out colleagues).
        $colleague = $this->admin();
        $this->asAdmin($this->admin)->post(route('admin.users.lock', $colleague->id), ['reason' => 'Trying to lock staff'])->assertStatus(422);
        $this->asAdmin($this->admin)->post(route('admin.users.signout', $colleague->id), ['reason' => 'Trying to sign out'])->assertStatus(422);
        $this->assertNull($colleague->fresh()->locked_at);
        $this->artisan('getl1:reset-two-step', ['email' => $colleague->email])->assertExitCode(0);
        $this->assertFalse($colleague->fresh()->hasTwoFactor());
        $this->assertTrue(AuditLog::where('action', 'admin_2fa_reset')->exists());

        foreach (['admin_user_locked', 'admin_user_unlocked', 'admin_user_signed_out'] as $action) {
            $this->assertTrue(AuditLog::where('action', $action)->where('user_id', $this->admin->id)->exists(), $action);
        }
    }

    public function test_supplier_trust_score_and_company_page(): void
    {
        $this->live();
        $t = app(SupplierTrust::class)->for($this->s['A'][0]);
        $this->assertSame('New', $t['label']);
        $this->assertSame(1, $t['invited']);
        $this->assertSame(1, $t['quoted']);
        $this->assertSame(1, $t['auctions']);
        $this->asAdmin($this->admin)->get(route('admin.companies.show', $this->s['A'][0]->id))->assertOk()->assertSee('Trust score');
        $this->asAdmin($this->admin)->get(route('admin.companies.index', ['type' => 'supplier']))->assertOk()->assertSee('Trust: New');
    }

    public function test_platform_rules_set_buyer_defaults_and_limits(): void
    {
        $values = collect(PlatformSettings::FIELDS)->mapWithKeys(fn ($f, $k) => [str_replace('.', '__', $k) => $f[0]])->all();
        $this->asAdmin($this->admin)->get(route('admin.settings'))->assertOk()->assertSee('Platform rules');
        $this->asAdmin($this->admin)->post(route('admin.settings.update'), ['auction__default_duration_min' => 45] + $values)->assertSessionHasErrors('reason');
        $this->asAdmin($this->admin)->post(route('admin.settings.update'), ['auction__default_duration_min' => 300, 'reason' => 'Longer default'] + $values)
            ->assertSessionHasErrors('auction__default_duration_min');
        $this->asAdmin($this->admin)->post(route('admin.settings.update'), ['auction__default_duration_min' => 45, 'auction__max_extensions_limit' => 5, 'auction__default_max_extensions' => 5,
            'reason' => 'Buyers asked for longer auctions'] + $values)->assertRedirect();
        $this->assertSame(45, app(PlatformSettings::class)->get('auction.default_duration_min'));
        $this->assertTrue(AuditLog::where('action', 'admin_settings_changed')->exists());

        foreach ([['A', 100000, 10], ['B', 105000, 20]] as [$k, $total, $min]) {
            Quote::create(['rfq_id' => $this->rfq->id, 'supplier_org_id' => $this->s[$k][0]->id, 'submitted_by' => $this->s[$k][1]->id,
                'total' => $total, 'valid_till' => $this->t0->copy()->addDays(30)->toDateString(), 'submitted_at' => $this->t0->copy()->addMinutes($min)]);
        }
        $this->travelTo($this->t0->copy()->addHours(4));
        $this->actingAs($this->buyerUser)->get(route('buyer.auctions.create', $this->rfq->id))->assertOk()
            ->assertSee('<option value="45" selected>45 minutes</option>', false);
        $this->actingAs($this->buyerUser)->post(route('buyer.auctions.store', $this->rfq->id), [
            'starts_at' => now()->addMinutes(10)->setTimezone('Asia/Kolkata')->format('Y-m-d\TH:i'), 'duration_min' => 30,
            'min_decrement_type' => 'percent', 'min_decrement_value' => '0.5', 'max_decrement_pct' => '10',
            'extend_window_sec' => 120, 'extend_by_sec' => 120, 'max_extensions' => 8, 'visibility' => 'rank_only',
        ])->assertSessionHasErrors('max_extensions');
    }

    public function test_health_page_and_alert_once_then_all_clear(): void
    {
        Cache::forget(SystemHealth::HEARTBEAT_KEY);
        $this->asAdmin($this->admin)->get(route('admin.health'))->assertOk()->assertSee('System health')->assertSee('No heartbeat yet');
        $this->asAdmin($this->admin)->get(route('admin.dashboard'))->assertOk()->assertSee('Scheduler');

        $this->artisan('getl1:health')->assertExitCode(1);
        Mail::assertSent(HealthAlertMail::class, fn ($m) => in_array('Scheduler (auction clock, reminders)', $m->failing, true));
        $this->artisan('getl1:health')->assertExitCode(1);
        Mail::assertSent(HealthAlertMail::class, 1); // once an hour per problem

        Cache::put(SystemHealth::HEARTBEAT_KEY, now()->getTimestamp(), 3600);
        $this->artisan('getl1:health'); // other checks (e.g. disk) depend on the machine, so only the mail is asserted
        Mail::assertSent(HealthAlertMail::class, fn ($m) => $m->failing === [] && $m->recovered !== []);
        $this->asAdmin($this->admin)->get(route('admin.dashboard'))->assertOk()->assertDontSee('Scheduler (auction clock, reminders)');
    }

    public function test_dashboard_periods_and_test_companies_hidden(): void
    {
        $this->live();
        [$test] = $this->supplier('Stress Test Supplier 1');
        foreach (['7d', '30d', '90d', 'fy'] as $p) {
            $this->asAdmin($this->admin)->get(route('admin.dashboard', ['period' => $p]))->assertOk()
                ->assertSee('Platform activity')->assertSee('Top suppliers')->assertSee('data-chart', false);
        }
        $this->asAdmin($this->admin)->get(route('admin.dashboard'))->assertSee('1 test or demo company is hidden');
        $hidden = new \App\Services\Admin\Dashboard('30d');
        $all = new \App\Services\Admin\Dashboard('30d', true);
        $this->assertSame($all->kpis()['now']['suppliers'] - 1, $hidden->kpis()['now']['suppliers']);
        $this->assertSame(1, $hidden->kpis()['now']['rfqs']);
        $this->assertCount(30, $hidden->charts()['labels']);
    }
}
