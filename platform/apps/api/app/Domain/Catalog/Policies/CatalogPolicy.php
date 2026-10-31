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

    public function viewEnrollments(User $user, Organization $organization): bool
    {
        return $this->allows($user, $organization, 'enrollment.view');
    }

    /**
     * Only the groups the user teaches, and in them only the children's names, classes, dates, pauses and skipped
     * sessions (docs/product/team.md, т. 8); which groups — `TeachingScope`.
     */
    public function viewOwnEnrollments(User $user, Organization $organization): bool
    {
        return $this->allows($user, $organization, 'enrollment.view_own');
    }

    public function manageEnrollments(User $user, Organization $organization): bool
    {
        return $this->allows($user, $organization, 'enrollment.manage');
    }

    /** Parents' names, phones, emails and notes (docs/product/enrollment-contacts-and-emails.md, т. 3). */
    public function viewEnrollmentContacts(User $user, Organization $organization): bool
    {
        return $this->allows($user, $organization, 'enrollment.contacts');
    }

    private function allows(User $user, Organization $organization, string $ability): bool
    {
        if (! $this->context->hasOrganization()
            || $this->context->organizationId() !== $organization->getKey()) {
            return false;
        }

        $role = OrganizationMembership::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $user->getAuthIdentifier())
            ->where('status', 'active')
            ->value('role');

        if (! is_string($role)) {
            return false;
        }

        return OrganizationRole::tryFrom($role)?->allows($ability) ?? false;
    }
}
