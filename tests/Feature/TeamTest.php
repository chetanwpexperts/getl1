<?php

namespace Tests\Feature;

use App\Enums\OrgRole;
use App\Http\Controllers\TeamController;
use App\Mail\TeamInviteMail;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Billing\PlanService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class TeamTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        Mail::fake();
    }

    public function test_admin_invites_a_new_colleague_who_sets_a_password(): void
    {
        [$org, $owner] = $this->buyer();
        $this->actingAs($owner)->get(route('team.index'))->assertOk()->assertSee('Add a team member')->assertSee('Owner')->assertSee('seats used');

        $this->actingAs($owner)->post(route('team.store'), ['name' => 'Priya Mehta', 'email' => ' Priya@Buyer.in ', 'phone' => '98765 43210', 'role' => 'approver'])
            ->assertSessionHas('status');
        $priya = User::where('email', 'priya@buyer.in')->firstOrFail();
        $this->assertSame(OrgRole::Approver, $priya->roleIn($org));
        $this->assertNotNull($priya->invited_at);
        $this->assertSame($owner->id, $priya->invited_by);
        $this->assertSame('9876543210', $priya->phone);
        $this->assertTrue(AuditLog::where('action', 'team_member_added')->exists());

        $link = null;
        Mail::assertQueued(TeamInviteMail::class, function ($m) use (&$link) {
            $link = $m->link;

            return $m->hasTo('priya@buyer.in') && $m->link !== null;
        });

        // Same email again: refused.
        $this->actingAs($owner)->post(route('team.store'), ['name' => 'Priya', 'email' => 'priya@buyer.in', 'role' => 'buyer_user'])->assertSessionHasErrors('email');

        // The invited person sets a password and is signed in.
        $this->app['auth']->forgetGuards();
        $this->get($link)->assertOk()->assertSee('Welcome, Priya')->assertSee('Buyer Co');
        $this->post($link, ['password' => 'priya-pass-123', 'password_confirmation' => 'priya-pass-123'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($priya->fresh());
        $this->assertTrue(Hash::check('priya-pass-123', $priya->fresh()->password));
        $this->assertNotNull($priya->fresh()->last_login_at);

        // The link works once.
        $this->app['auth']->forgetGuards();
        $this->get($link)->assertForbidden();
    }

    public function test_links_are_signed_and_resend_replaces_them(): void
    {
        [, $owner] = $this->buyer();
        $this->actingAs($owner)->post(route('team.store'), ['name' => 'Ravi', 'email' => 'ravi@buyer.in', 'role' => 'buyer_user']);
        $ravi = User::where('email', 'ravi@buyer.in')->firstOrFail();
        $old = TeamController::inviteLink($ravi);

        $this->get(str_replace('v=', 'v=1', $old))->assertForbidden();               // tampered
        $this->get(route('team.join', ['user' => $ravi->id, 'v' => 1]))->assertForbidden(); // unsigned

        $this->travel(5)->seconds();
        $this->actingAs($owner)->post(route('team.resend', $ravi->id))->assertSessionHas('status');
        $this->app['auth']->forgetGuards();
        $this->get($old)->assertForbidden();                                          // old link dead
        $this->get(TeamController::inviteLink($ravi->fresh()))->assertOk();

        $this->travel(8)->days();
        $this->get(TeamController::inviteLink($ravi->fresh()))->assertOk();          // fresh link is still fine
        $this->get($old)->assertForbidden();
    }

    public function test_existing_login_is_added_without_a_password_link(): void
    {
        [$org, $owner] = $this->buyer();
        [, $other] = $this->buyer('Other Co', ['email' => 'amit@other.in']);
        $this->actingAs($owner)->post(route('team.store'), ['name' => 'Amit', 'email' => 'amit@other.in', 'role' => 'buyer_user'])->assertSessionHas('status');
        $this->assertTrue($other->belongsToOrganization($org));
        Mail::assertQueued(TeamInviteMail::class, fn ($m) => $m->hasTo('amit@other.in') && $m->link === null);
    }

    public function test_roles_removal_and_guards(): void
    {
        [$org, $owner] = $this->buyer();
        $buyerUser = $this->memberOf($org, OrgRole::BuyerUser);

        // Only an Admin manages the team.
        $this->actingAs($buyerUser)->get(route('team.index'))->assertOk()->assertSee('Only an Admin');
        $this->actingAs($buyerUser)->post(route('team.store'), ['name' => 'X', 'email' => 'x@y.in', 'role' => 'buyer_user'])->assertForbidden();

        $this->actingAs($owner)->put(route('team.role', $buyerUser->id), ['role' => 'buyer_admin'])->assertSessionHas('status');
        $this->assertSame(OrgRole::BuyerAdmin, $buyerUser->roleIn($org));
        $this->actingAs($owner)->put(route('team.role', $buyerUser->id), ['role' => 'supplier_user'])->assertSessionHasErrors('role');

        // The new Admin can't change or remove the owner, nor themselves.
        $this->actingAs($buyerUser)->put(route('team.role', $owner->id), ['role' => 'buyer_user'])->assertStatus(422);
        $this->actingAs($buyerUser)->delete(route('team.destroy', $owner->id))->assertStatus(422);
        $this->actingAs($owner)->delete(route('team.destroy', $owner->id))->assertStatus(422);

        // People from another company can't be touched.
        [, $stranger] = $this->buyer('Elsewhere');
        $this->actingAs($owner)->delete(route('team.destroy', $stranger->id))->assertNotFound();

        $this->actingAs($owner)->delete(route('team.destroy', $buyerUser->id))->assertSessionHas('status');
        $this->assertFalse($buyerUser->fresh()->belongsToOrganization($org));
        $this->assertTrue(AuditLog::where('action', 'team_member_removed')->exists());
        $this->actingAs($buyerUser->fresh())->get('/dashboard')->assertRedirect(route('onboarding'));
    }

    public function test_plan_seat_limit(): void
    {
        [$org, $owner] = $this->buyer();
        app(PlanService::class)->current($org)->forceFill(['max_users' => 1])->save();
        $this->actingAs($owner)->get(route('team.index'))->assertSee('only free requesters can be added');
        $this->actingAs($owner)->post(route('team.store'), ['name' => 'Ravi', 'email' => 'ravi@buyer.in', 'role' => 'buyer_user'])->assertSessionHasErrors('email');
        $this->assertNull(User::where('email', 'ravi@buyer.in')->first());

        // Requesters are free: they can still be added, and don't use a seat.
        $this->actingAs($owner)->post(route('team.store'), ['name' => 'Store', 'email' => 'store@buyer.in', 'role' => 'requester'])->assertSessionHasNoErrors();
        $store = User::where('email', 'store@buyer.in')->firstOrFail();
        $this->assertSame(1, TeamController::paidSeatsUsed($org));
        $this->actingAs($owner)->get(route('team.index'))->assertSee('1 of 1');

        // ...but can't be moved into a paid role while the seats are full.
        $this->actingAs($owner)->put(route('team.role', $store->id), ['role' => 'buyer_user'])->assertSessionHasErrors('role');
        $this->assertSame(OrgRole::Requester, $store->fresh()->roleIn($org));
    }

    public function test_supplier_owner_adds_colleagues(): void
    {
        [$org, $owner] = $this->supplier();
        $this->actingAs($owner)->post(route('team.store'), ['name' => 'Sunil', 'email' => 'sunil@supplier.in', 'role' => 'supplier_user'])->assertSessionHas('status');
        $sunil = User::where('email', 'sunil@supplier.in')->firstOrFail();
        $this->assertSame(OrgRole::SupplierUser, $sunil->roleIn($org));
        $this->actingAs($sunil)->post(route('team.store'), ['name' => 'X', 'email' => 'x@y.in', 'role' => 'supplier_user'])->assertForbidden();
    }
}
