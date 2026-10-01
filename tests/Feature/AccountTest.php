<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_menu_and_profile_page(): void
    {
        [, $buyer] = $this->buyer();
        $this->actingAs($buyer)->get(route('dashboard'))->assertOk()
            ->assertSee('RFQs &amp; auctions', false)->assertSee('Get started')->assertSee('Create your first RFQ')->assertSee('Needs your attention')->assertSee('Company profile')->assertSee('Plan &amp; billing', false)->assertSee('My profile')->assertSee('New RFQ');
        $this->actingAs($buyer)->get(route('account.edit'))->assertOk()->assertSee('Personal details')->assertSee($buyer->email);
        $this->get('/account')->assertOk();

        [, $supplier] = $this->supplier();
        $this->actingAs($supplier)->get(route('dashboard'))->assertOk()
            ->assertSee('Purchase orders')->assertSee('Documents &amp; KYC', false)->assertSee('Get verified')->assertSee('No new invitations')->assertDontSee('New RFQ');
    }

    public function test_update_details(): void
    {
        [, $user] = $this->buyer();
        $this->actingAs($user)->put(route('account.update'), ['name' => 'Ravi Kumar', 'phone' => '12345'])->assertSessionHasErrors('phone');
        $this->actingAs($user)->put(route('account.update'), ['name' => 'Ravi Kumar', 'phone' => '+91 98765 43210'])->assertSessionHas('status', 'Your details are saved.');
        $user->refresh();
        $this->assertSame('Ravi Kumar', $user->name);
        $this->assertSame('9876543210', $user->phone);
        $this->assertTrue(AuditLog::where('action', 'profile_updated')->exists());
    }

    public function test_change_password(): void
    {
        [, $user] = $this->buyer();
        $this->actingAs($user)->put(route('account.password'), ['current_password' => 'wrong-one', 'password' => 'new-secret-123', 'password_confirmation' => 'new-secret-123'])
            ->assertSessionHasErrorsIn('password', 'current_password');
        $this->actingAs($user)->put(route('account.password'), ['current_password' => 'password', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrorsIn('password', 'password');
        $this->actingAs($user)->put(route('account.password'), ['current_password' => 'password', 'password' => 'new-secret-123', 'password_confirmation' => 'new-secret-123'])
            ->assertSessionHasNoErrors()->assertSessionHas('status');
        $this->assertTrue(Hash::check('new-secret-123', $user->fresh()->password));
        $this->assertTrue(AuditLog::where('action', 'password_changed')->exists());
    }
}
