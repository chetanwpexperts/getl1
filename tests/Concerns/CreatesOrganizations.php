<?php

namespace Tests\Concerns;

use App\Enums\OrganizationType;
use App\Enums\OrgRole;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationService;

trait CreatesOrganizations
{
    /** @return array{0: Organization, 1: User} */
    protected function buyer(string $name = 'Buyer Co', array $user = []): array
    {
        $u = User::factory()->create($user);
        $org = app(OrganizationService::class)->createWithOwner($u, ['name' => $name, 'city' => 'Mohali'], OrganizationType::Buyer);

        return [$org, $u];
    }

    /** @return array{0: Organization, 1: User} */
    protected function supplier(string $name = 'Supplier Co', array $user = []): array
    {
        $u = User::factory()->create($user);
        $org = app(OrganizationService::class)->createWithOwner($u, ['name' => $name, 'city' => 'Ludhiana'], OrganizationType::Supplier);

        return [$org, $u];
    }

    protected function memberOf(Organization $org, OrgRole $role): User
    {
        $u = User::factory()->create();
        app(OrganizationService::class)->addMember($org, $u, $role);

        return $u;
    }

    /** A GetL1 staff user with two-step login already set up. */
    protected function admin(): User
    {
        $u = User::factory()->create();
        $u->forceFill([
            'is_platform_admin' => true,
            'two_factor_secret' => app(\App\Services\Security\TwoFactor::class)->newSecret(),
            'two_factor_recovery_codes' => json_encode([]),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $u;
    }

    /** Acts as a staff user who has passed the two-step login in this session. */
    protected function asAdmin(User $u): static
    {
        return $this->actingAs($u)->withSession([\App\Http\Middleware\EnsureAdminTwoFactor::SESSION => ['user' => $u->id, 'at' => now()->getTimestamp(), 'seen' => now()->getTimestamp()]]);
    }
}
