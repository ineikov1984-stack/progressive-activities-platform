<?php

namespace App\Domain\Catalog\Policies;

use App\Domain\Catalog\Services\AccessScope;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;

/**
 * The checks for the whole organization (`Gate::…($organization)`, docs/product/team-rights.md, т. 2.2): the role and the
 * added rights through `AccessScope`; a right of a group holds here only with the scope "all". The old keys
 * "enrollment.manage" and "enrollment.contacts" are synonyms of "enrollment.enroll" and "contacts.view".
 */
final readonly class CatalogPolicy
{
    public function __construct(private TenantContext $context, private AccessScope $scope) {}

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

    /** Who leads or assists a group, teaches a session or leads an offering: assignments give rights (т. 2). */
    public function assign(User $user, Organization $organization): bool
    {
        return $this->allows($user, $organization, 'team.assign');
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
        return $this->allows($user, $organization, 'enrollment.enroll');
    }

    /** Parents' names, phones, emails and notes (docs/product/enrollment-contacts-and-emails.md, т. 3). */
    public function viewEnrollmentContacts(User $user, Organization $organization): bool
    {
        return $this->allows($user, $organization, 'contacts.view');
    }

    private function allows(User $user, Organization $organization, string $ability): bool
    {
        if (! $this->context->hasOrganization()
            || $this->context->organizationId() !== $organization->getKey()) {
            return false;
        }

        return $this->scope->allowsEverywhere($user, $ability);
    }
}
