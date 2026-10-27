<?php

namespace App\Domain\Catalog\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Tenancy\TenantContext;

final readonly class CatalogPolicy
{
    public function __construct(private TenantContext $context) {}

    public function view(User $user, Organization $organization): bool
    {
        return $this->allows($user, $organization, 'catalog.view');
    }

    public function manage(User $user, Organization $organization): bool
    {
        return $this->allows($user, $organization, 'catalog.manage');
    }

    public function publish(User $user, Organization $organization): bool
    {
        return $this->allows($user, $organization, 'catalog.publish');
    }

    private function allows(User $user, Organization $organization, string $ability): bool
    {
        if (! $this->context->hasOrganization()
            || $this->context->organizationId() !== $organization->getKey()) {
            return false;
        }

        $membership = OrganizationMembership::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $user->getAuthIdentifier())
            ->where('status', 'active')
            ->first();

        return OrganizationRole::tryFrom($membership?->role ?? '')?->allows($ability) ?? false;
    }
}
