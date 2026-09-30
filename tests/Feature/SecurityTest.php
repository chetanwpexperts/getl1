<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    private function spySecurityLog(): Mockery\MockInterface
    {
        $logger = Mockery::spy();
        // Any channel returns the spy; assertions below check the security events.
        Log::shouldReceive('channel')->andReturn($logger);

        return $logger;
    }

    public function test_failed_login_is_logged_without_the_password(): void
    {
        $logger = $this->spySecurityLog();
        $user = User::factory()->create(['password' => 'right-password']);

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password-123']);

        $logger->shouldHaveReceived('warning')->with('login_failed', Mockery::on(function (array $ctx) use ($user) {
            return $ctx['email'] === $user->email
                && ! str_contains(json_encode($ctx), 'wrong-password-123');
        }));
    }

    public function test_repeated_failures_lock_the_account(): void
    {
        $this->spySecurityLog();
        $user = User::factory()->create(['password' => 'right-password']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'nope']);
        }

        // Even the right password is refused while locked out.
        $this->post('/login', ['email' => $user->email, 'password' => 'right-password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_successful_login_is_audited(): void
    {
        [, $user] = $this->buyer('Buyer', ['password' => 'right-password']);

        $this->post('/login', ['email' => $user->email, 'password' => 'right-password'])->assertRedirect(route('dashboard'));

        $this->assertTrue(AuditLog::where('action', 'login')->where('user_id', $user->id)->exists());
    }

    public function test_denied_access_is_logged(): void
    {
        $logger = $this->spySecurityLog();
        [, $supplierUser] = $this->supplier();

        $this->actingAs($supplierUser)->get('/buyer/suppliers')->assertForbidden();

        $logger->shouldHaveReceived('warning')->with('access_denied', Mockery::type('array'));
    }

    public function test_security_headers_are_sent(): void
    {
        [, $user] = $this->buyer();

        $this->actingAs($user)->get('/dashboard')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_platform_admin_flag_cannot_be_mass_assigned(): void
    {
        $this->post('/register', [
            'account_type' => 'buyer', 'name' => 'Sneaky', 'email' => 'sneaky@example.com', 'phone' => '9876543210',
            'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1',
            'company_name' => 'Sneaky Co', 'city' => 'Mohali', 'is_platform_admin' => 1,
        ]);

        $this->assertFalse(User::where('email', 'sneaky@example.com')->firstOrFail()->is_platform_admin);
    }
}
