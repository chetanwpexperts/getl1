<?php

namespace App\Support\Tenancy;

use App\Models\Organization;

/**
 * Holds the organization the current request acts for.
 * Bound as a singleton; set by the EnsureOrganization middleware.
 */
class CurrentOrganization
{
    private ?Organization $organization = null;

    public function set(?Organization $organization): void
    {
        $this->organization = $organization;
    }

    public function get(): ?Organization
    {
        return $this->organization;
    }

    public function id(): ?int
    {
        return $this->organization?->id;
    }

    public function has(): bool
    {
        return $this->organization !== null;
    }
}
