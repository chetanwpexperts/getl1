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

    protected function admin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_platform_admin' => true])->save();

        return $u;
    }
}
