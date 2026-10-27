<?php

namespace App\Domain\Organizations\Tenancy;

use App\Domain\Organizations\Models\Organization;
use LogicException;

class TenantContext
{
    private ?string $organizationId = null;

    public function select(Organization|string $organization): void
    {
        $this->organizationId = $organization instanceof Organization
            ? $organization->getKey()
            : $organization;
    }

    public function hasOrganization(): bool
    {
        return $this->organizationId !== null;
    }

    public function organizationId(): string
    {
        return $this->organizationId
            ?? throw new LogicException('No organization has been selected for this request.');
    }

    public function clear(): void
    {
        $this->organizationId = null;
    }
}
