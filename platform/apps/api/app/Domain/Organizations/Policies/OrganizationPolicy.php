<?php

namespace App\Domain\Organizations\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Tenancy\TenantContext;

class OrganizationPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function create(User $user, Organization $organization): bool
    {
        return $this->allows($user, $organization, 'create');
    }

    public function update(User $user, Organization $organization): bool
    {
        return $this->allows($user, $organization, 'update');
    }

    public function invite(User $user, Organization $organization): bool
    {
        return $this->allows($user, $organization, 'invite');
    }

    public function finance(User $user, Organization $organization): bool
    {
        return $this->allows($user, $organization, 'finance');
    }

    public function view(User $user, Organization $organization): bool
    {
        return $this->allows($user, $organization, 'view');
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

        if ($membership === null) {
            return false;
        }

        $role = OrganizationRole::tryFrom($membership->role);

        return $role?->allows($ability) ?? false;
    }
}
