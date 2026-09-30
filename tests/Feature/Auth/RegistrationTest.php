<?php

namespace Tests\Feature\Auth;

use App\Enums\OrganizationType;
use App\Enums\OrgRole;
use App\Enums\SubscriptionStatus;
use App\Models\BuyerSupplier;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'account_type' => 'buyer',
            'name' => 'Rajesh Kumar',
            'email' => 'rajesh@example.com',
            'phone' => '9876543210',
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
            'company_name' => 'Kumar Packaging',
            'city' => 'Mohali',
        ], $overrides);
    }

    public function test_register_page_loads(): void
    {
        $this->get('/register')->assertOk();
    }

    public function test_buyer_registration_creates_org_owner_and_trial(): void
    {
        $this->post('/register', $this->payload())->assertRedirect(route('dashboard'));

        $user = User::where('email', 'rajesh@example.com')->firstOrFail();
        $org = $user->currentOrganization;

        $this->assertAuthenticatedAs($user);
        $this->assertSame(OrganizationType::Buyer, $org->type);
        $this->assertSame('Kumar Packaging', $org->name);
        $this->assertSame(OrgRole::BuyerAdmin, $user->roleIn($org));
        $this->assertSame(SubscriptionStatus::Trialing, $org->subscription->status);
        $this->assertTrue($org->subscription->trial_ends_at->isFuture());
        $this->assertTrue($org->hasUsableSubscription());
    }

    public function test_supplier_registration_has_no_subscription_and_links_buyer_lists(): void
    {
        $buyer = Organization::factory()->create();
        $entry = BuyerSupplier::create([
            'buyer_org_id' => $buyer->id,
            'company_name' => 'Sharma Cartons',
            'contact_email' => 'sharma@example.com',
        ]);

        $this->post('/register', $this->payload([
            'account_type' => 'supplier',
            'email' => 'sharma@example.com',
            'company_name' => 'Sharma Cartons',
        ]))->assertRedirect(route('dashboard'));

        $user = User::where('email', 'sharma@example.com')->firstOrFail();
        $supplier = $user->currentOrganization;

        $this->assertSame(OrganizationType::Supplier, $supplier->type);
        $this->assertSame(OrgRole::SupplierUser, $user->roleIn($supplier));
        $this->assertNull($supplier->subscription);
        $this->assertSame($supplier->id, $entry->fresh()->supplier_org_id);
    }

    public function test_invalid_phone_and_gstin_are_rejected(): void
    {
        $this->post('/register', $this->payload(['phone' => '12345', 'gstin' => 'BADGSTIN']))
            ->assertSessionHasErrors(['phone', 'gstin']);

        $this->assertGuest();
    }

    public function test_gstin_with_wrong_check_digit_is_rejected(): void
    {
        $this->post('/register', $this->payload(['gstin' => '03AAACR5055K1Z5']))
            ->assertSessionHasErrors('gstin');
    }

    public function test_valid_gstin_is_saved_uppercase(): void
    {
        $this->post('/register', $this->payload(['gstin' => '03aaacr5055k1zh']))
            ->assertSessionHasNoErrors();

        $this->assertSame('03AAACR5055K1ZH', User::first()->currentOrganization->gstin);
    }

    public function test_user_can_log_in_and_out(): void
    {
        $user = User::factory()->create(['password' => 'secret-pass-1']);

        $this->post('/login', ['email' => $user->email, 'password' => 'secret-pass-1'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create(['password' => 'secret-pass-1']);

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
