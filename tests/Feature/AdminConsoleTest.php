<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Security\TwoFactor;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class AdminConsoleTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    private function codeFor(string $secret, int $offset = 0): string
    {
        return app(TwoFactor::class)->code($secret, intdiv(time(), TwoFactor::PERIOD) + $offset);
    }

    public function test_two_step_setup_is_required_and_works_with_an_app_code(): void
    {
        $u = User::factory()->create();
        $u->forceFill(['is_platform_admin' => true])->save();

        $this->actingAs($u)->get('/admin')->assertRedirect(route('admin.2fa.setup'));
        $this->actingAs($u)->get('/admin/companies')->assertRedirect(route('admin.2fa.setup'));
        $this->actingAs($u)->get(route('admin.2fa.setup'))->assertOk()->assertSee('Set up two-step login')->assertSee('otpauth://totp/', false);
        $secret = session('admin_2fa_pending');
        $this->assertSame(32, strlen($secret));

        $this->actingAs($u)->post(route('admin.2fa.confirm'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertNull($u->fresh()->two_factor_confirmed_at);

        $page = $this->actingAs($u)->post(route('admin.2fa.confirm'), ['code' => $this->codeFor($secret)])->assertOk()->assertSee('recovery codes');
        $u->refresh();
        $this->assertTrue($u->hasTwoFactor());
        $this->assertSame($secret, $u->two_factor_secret);
        $this->assertNotSame($secret, \DB::table('users')->where('id', $u->id)->value('two_factor_secret'), 'Stored encrypted');
        $this->assertCount(8, json_decode($u->two_factor_recovery_codes, true));
        $this->assertTrue(AuditLog::where('action', 'admin_2fa_enabled')->exists());
        $this->assertMatchesRegularExpression('/[a-z0-9]{5}-[a-z0-9]{5}/', $page->getContent());

        $this->actingAs($u)->get('/admin')->assertOk()->assertSee('Overview');
    }

    public function test_each_session_needs_a_code_and_codes_work_once(): void
    {
        $u = $this->admin();
        $this->actingAs($u)->get('/admin/payments')->assertRedirect(route('admin.2fa.challenge'));
        $this->actingAs($u)->get(route('admin.2fa.challenge'))->assertOk()->assertSee('authenticator app');

        $this->actingAs($u)->post(route('admin.2fa.verify'), ['code' => '123456'])->assertSessionHasErrors('code');
        $code = $this->codeFor($u->two_factor_secret);
        $this->actingAs($u)->post(route('admin.2fa.verify'), ['code' => $code])->assertRedirect('/admin/payments');
        $this->actingAs($u)->get('/admin/payments')->assertOk();

        // The same code can't be used again (e.g. by someone watching over a shoulder).
        session()->forget('admin_2fa');
        $this->actingAs($u)->post(route('admin.2fa.verify'), ['code' => $code])->assertSessionHasErrors('code');

        // Idle for more than an hour: ask again.
        $this->actingAs($u)->post(route('admin.2fa.verify'), ['code' => $this->codeFor($u->two_factor_secret, 1)])->assertRedirect();
        $this->travel(61)->minutes();
        $this->actingAs($u)->get('/admin')->assertRedirect(route('admin.2fa.challenge'));
    }

    public function test_recovery_codes_work_once_and_wrong_codes_lock_out(): void
    {
        $u = $this->admin();
        $u->forceFill(['two_factor_recovery_codes' => json_encode([Hash::make('abcde-12345')])])->save();

        $this->actingAs($u)->post(route('admin.2fa.verify'), ['recovery_code' => 'ABCDE-12345'])->assertRedirect(route('admin.dashboard'));
        $this->assertSame([], json_decode($u->fresh()->two_factor_recovery_codes, true));
        $this->assertTrue(AuditLog::where('action', 'admin_2fa_recovery_code_used')->exists());

        session()->forget('admin_2fa');
        $this->actingAs($u)->post(route('admin.2fa.verify'), ['recovery_code' => 'abcde-12345'])->assertSessionHasErrors('recovery_code');

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($u)->post(route('admin.2fa.verify'), ['code' => '000000']);
        }
        $this->actingAs($u)->post(route('admin.2fa.verify'), ['code' => $this->codeFor($u->two_factor_secret)])
            ->assertSessionHasErrors(['code' => 'Too many wrong codes. Try again in 15 minutes.']);
    }

    public function test_customers_never_reach_the_console(): void
    {
        [, $buyer] = $this->buyer();
        foreach (['/admin', '/admin/two-step', '/admin/two-step/setup', '/admin/companies', '/admin/leads'] as $url) {
            $this->actingAs($buyer)->get($url)->assertForbidden();
        }
        $this->get('/admin')->assertRedirect(route('login'));
    }

    public function test_every_console_page_loads(): void
    {
        [$org] = $this->buyer('Acme Buyers');
        [$sup] = $this->supplier('Steel Works');
        Lead::create(['name' => 'Ravi', 'company' => 'Ravi Packaging', 'email' => 'ravi@example.com', 'phone' => '9876543210', 'interest' => 'buyer']);
        $admin = $this->admin();

        foreach (['/admin' => 'MRR', '/admin/companies' => 'Acme Buyers', "/admin/companies/{$org->id}" => 'Extend trial',
                  "/admin/companies/{$sup->id}" => 'Steel Works', '/admin/kyc' => 'KYC review', '/admin/payments' => 'Payments',
                  '/admin/ai' => 'AI usage', '/admin/leads' => 'Ravi Packaging', '/admin/audit' => 'Audit log', '/admin/security' => 'Security log'] as $url => $text) {
            $this->asAdmin($admin)->get($url)->assertOk()->assertSee($text)->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->asAdmin($admin)->get('/admin/companies?q=steel')->assertOk()->assertSee('Steel Works')->assertDontSee('Acme Buyers');
    }

    public function test_support_actions_are_reasoned_and_audited(): void
    {
        [$org, $owner] = $this->buyer('Acme Buyers');
        $admin = $this->admin();
        $trialEnd = Subscription::where('organization_id', $org->id)->value('trial_ends_at');

        $this->asAdmin($admin)->post(route('admin.companies.trial', $org->id), ['days' => 7])->assertSessionHasErrors('reason');
        $this->asAdmin($admin)->post(route('admin.companies.trial', $org->id), ['days' => 7, 'reason' => 'Pilot with their plant head'])->assertRedirect();
        $this->assertEquals(\Illuminate\Support\Carbon::parse($trialEnd)->addDays(7)->timestamp,
            Subscription::where('organization_id', $org->id)->first()->trial_ends_at->timestamp);

        $this->asAdmin($admin)->post(route('admin.companies.credits', $org->id), ['kind' => 'ai_credits', 'quantity' => 25, 'reason' => 'Onboarding bonus']);
        $this->asAdmin($admin)->post(route('admin.companies.credits', $org->id), ['kind' => 'auction_credits', 'quantity' => 2, 'reason' => 'Onboarding bonus']);
        $org->refresh();
        $this->assertSame(25, $org->ai_credits);
        $this->assertSame(2, $org->auction_credits);

        $this->asAdmin($admin)->post(route('admin.companies.suspend', $org->id), ['reason' => 'Fake GST details'])->assertRedirect();
        $this->actingAs($owner)->get('/dashboard')->assertForbidden();
        $this->asAdmin($admin)->post(route('admin.companies.restore', $org->id), ['reason' => 'Documents checked'])->assertRedirect();
        $this->actingAs($owner)->get('/dashboard')->assertOk();

        foreach (['admin_trial_extended', 'admin_credits_granted', 'admin_company_suspended', 'admin_company_restored'] as $a) {
            $log = AuditLog::where('action', $a)->latest('id')->firstOrFail();
            $this->assertSame($admin->id, $log->user_id);
            $this->assertSame($org->id, $log->organization_id);
            $this->assertNotEmpty($log->after['reason']);
        }

        $lead = Lead::create(['name' => 'Ravi', 'company' => 'Ravi Packaging', 'email' => 'ravi@example.com', 'interest' => 'buyer']);
        $this->asAdmin($admin)->post(route('admin.leads.update', $lead->id), ['status' => 'contacted', 'notes' => 'Called, demo on Monday'])->assertRedirect();
        $this->assertSame('contacted', $lead->fresh()->status);
    }
}
