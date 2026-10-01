<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Services\BillingSettings;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class BillingSettingsTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    private const GSTIN = '27AAPFU0939F1ZV'; // valid check digit

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_gstin_check_digit(): void
    {
        $this->assertTrue(BillingSettings::validGstin(self::GSTIN));
        $this->assertTrue(BillingSettings::validGstin('29AAGCB7383J1Z4'));
        $this->assertFalse(BillingSettings::validGstin('27AAPFU0939F1ZX'));
        $this->assertFalse(BillingSettings::validGstin('27AAPFU0939F1Z'));
    }

    public function test_only_staff_reach_it(): void
    {
        [, $buyer] = $this->buyer();
        $this->actingAs($buyer)->get(route('admin.billing'))->assertForbidden();
        $this->asAdmin($this->admin())->get(route('admin.billing'))->assertOk()->assertSee('Billing &amp; GST', false)->assertSee('Not charging GST');
    }

    public function test_turning_gst_on_and_off_safely(): void
    {
        $admin = $this->admin();
        $this->get('/pricing')->assertDontSee('+ GST');

        // Needs a valid GSTIN, a confirmation and a reason.
        $this->asAdmin($admin)->post(route('admin.billing.update'), ['gst_enabled' => '1', 'gst_rate' => 18, 'reason' => 'Registered', 'confirm_gst' => '1'])
            ->assertSessionHasErrors('gstin');
        $this->asAdmin($admin)->post(route('admin.billing.update'), ['gst_enabled' => '1', 'gstin' => '27AAPFU0939F1ZX', 'gst_rate' => 18, 'reason' => 'Registered', 'confirm_gst' => '1'])
            ->assertSessionHasErrors('gstin');
        $this->asAdmin($admin)->post(route('admin.billing.update'), ['gst_enabled' => '1', 'gstin' => self::GSTIN, 'gst_rate' => 18, 'reason' => 'Registered'])
            ->assertSessionHasErrors('confirm_gst');
        $this->asAdmin($admin)->post(route('admin.billing.update'), ['gst_enabled' => '1', 'gstin' => self::GSTIN, 'gst_rate' => 99, 'reason' => 'Registered', 'confirm_gst' => '1'])
            ->assertSessionHasErrors('gst_rate');
        $this->assertFalse((bool) config('billing.gst_enabled'));

        $this->asAdmin($admin)->post(route('admin.billing.update'), ['gst_enabled' => '1', 'gstin' => strtolower(self::GSTIN), 'gst_rate' => 18, 'sac' => '998314',
            'seller_name' => 'Sharma Ventures', 'reason' => 'GST registration approved', 'confirm_gst' => '1'])
            ->assertRedirect()->assertSessionHas('status');
        $log = AuditLog::where('action', 'admin_billing_changed')->firstOrFail();
        $this->assertTrue($log->after['gst_enabled']);
        $this->assertSame(self::GSTIN, $log->after['gstin']);
        $this->assertSame('GST registration approved', $log->after['reason']);

        $this->app['auth']->forgetGuards();
        $this->get('/pricing')->assertOk()->assertSee('+ GST');
        $this->assertTrue(config('billing.gst_enabled'));
        $this->assertSame(self::GSTIN, config('billing.seller.gstin'));
        $this->assertSame('27', config('billing.seller.state_code'));
        $this->assertSame('Sharma Ventures', config('billing.seller.name'));
        $this->assertSame('998314', config('billing.seller.sac'));

        // Saving again without changes: nothing logged.
        $this->asAdmin($admin)->post(route('admin.billing.update'), ['gst_enabled' => '1', 'gstin' => self::GSTIN, 'gst_rate' => 18, 'sac' => '998314',
            'seller_name' => 'Sharma Ventures', 'reason' => 'No change'])->assertSessionHas('status', 'Nothing changed.');

        // Off again needs the confirmation too.
        $this->asAdmin($admin)->post(route('admin.billing.update'), ['gstin' => self::GSTIN, 'gst_rate' => 18, 'reason' => 'Testing off'])->assertSessionHasErrors('confirm_gst');
        $this->asAdmin($admin)->post(route('admin.billing.update'), ['gstin' => self::GSTIN, 'gst_rate' => 18, 'reason' => 'Testing off', 'confirm_gst' => '1'])->assertRedirect();
        $this->app['auth']->forgetGuards();
        $this->get('/pricing')->assertDontSee('+ GST');
    }

    public function test_seller_details_fall_back_to_the_website(): void
    {
        config(['site.legal_name' => 'Sharma Ventures', 'site.email' => 'notifications@getl1.com', 'billing.seller.name' => null, 'billing.seller.email' => null]);
        app(BillingSettings::class)->apply();
        $this->assertSame('Sharma Ventures', config('billing.seller.name'));
        $this->assertSame('notifications@getl1.com', config('billing.seller.email'));
    }

    public function test_typed_details_are_saved_even_when_they_match_the_default(): void
    {
        config(['site.email' => 'notifications@getl1.com', 'site.legal_name' => 'Chetan Sharma']);
        $admin = $this->admin();
        $this->asAdmin($admin)->post(route('admin.billing.update'), ['gst_rate' => 18, 'seller_name' => 'Chetan Sharma', 'seller_email' => 'notifications@getl1.com', 'reason' => 'Initial setup'])
            ->assertSessionHas('status', 'Billing details saved. New receipts and invoices use them.');
        $saved = app(BillingSettings::class)->saved();
        $this->assertSame('Chetan Sharma', $saved['seller_name']);
        $this->assertSame('notifications@getl1.com', $saved['seller_email']);
        $this->assertArrayNotHasKey('gst_enabled', $saved); // switch left as it was
    }
}
