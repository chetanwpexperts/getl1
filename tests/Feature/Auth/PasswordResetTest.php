<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_link_works_once_and_never_reveals_accounts(): void
    {
        Notification::fake();
        $u = User::factory()->create(['email' => 'owner@acme.in']);

        $this->get('/login')->assertOk()->assertSee('Forgot password?');
        $this->get('/forgot-password')->assertOk();

        // Unknown and known emails get the same answer.
        $this->post('/forgot-password', ['email' => 'nobody@acme.in'])->assertSessionHas('status');
        $this->post('/forgot-password', ['email' => 'Owner@Acme.in'])->assertSessionHas('status');

        $token = null;
        Notification::assertSentTo($u, ResetPassword::class, function ($n) use (&$token, $u) {
            $token = $n->token;
            $mail = $n->toMail($u);

            return $mail->subject === 'Reset your GetL1 password' && str_contains($mail->actionUrl, '/reset-password/');
        });

        $this->get("/reset-password/{$token}?email=owner@acme.in")->assertOk()->assertSee('Choose a new password');
        $this->post('/reset-password', ['token' => $token, 'email' => 'owner@acme.in', 'password' => 'NewPass#2026', 'password_confirmation' => 'NewPass#2026'])
            ->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('NewPass#2026', $u->fresh()->password));
        $this->assertTrue(AuditLog::where('action', 'password_reset')->where('user_id', $u->id)->exists());

        // The same link can't be used again.
        $this->post('/reset-password', ['token' => $token, 'email' => 'owner@acme.in', 'password' => 'Another#2026', 'password_confirmation' => 'Another#2026'])
            ->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('NewPass#2026', $u->fresh()->password));
    }
}
