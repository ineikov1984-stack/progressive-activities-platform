<?php

namespace App\Domain\Organizations\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Services\StaffRights;
use App\Domain\Organizations\Tenancy\TenantContext;

/**
 * The rights of a person of the team for the whole organization go through `StaffRights` (docs/product/team-rights.md,
 * т. 2.2): "team.view" is a right of a group and holds here only with the scope "all". The organization's application
 * to the platform stays by role — it is not a right in the team.
 */
class OrganizationPolicy
{
    public function __construct(private readonly TenantContext $context, private readonly StaffRights $rights) {}

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

    /** Every member of the team reads the sets of payment terms; parents see them on the activity. */
    public function viewPaymentTerms(User $user, Organization $organization): bool
    {
        return $this->allows($user, $organization, 'view');
    }

    public function managePaymentTerms(User $user, Organization $organization): bool
    {
        return $this->allows($user, $organization, 'terms.manage');
    }

    /** Who is in the team, their roles and groups (docs/product/team.md, т. 4). */
    public function viewTeam(User $user, Organization $organization): bool
    {
        return $this->allows($user, $organization, 'team.view');
    }

    /** Adding and changing people, their phones and e-mails, archiving. */
    public function manageTeam(User $user, Organization $organization): bool
    {
        return $this->allows($user, $organization, 'team.manage');
    }

    public function viewApplication(User $user, Organization $organization): bool
    {
        return in_array($this->role($user, $organization), [
            OrganizationRole::OrganizationOwner,
            OrganizationRole::OrganizationAdmin,
        ], true);
    }

    public function updateApplication(User $user, Organization $organization): bool
    {
        return $this->viewApplication($user, $organization);
    }

    public function submitApplication(User $user, Organization $organization): bool
    {
        return $this->role($user, $organization) === OrganizationRole::OrganizationOwner;
    }

    private function allows(User $user, Organization $organization, string $ability): bool
    {
        if (! $this->context->hasOrganization()
            || $this->context->organizationId() !== $organization->getKey()) {
            return false;
        }

        return $this->rights->for($user)->allowsAcrossOrganization($ability);
    }

    private function role(User $user, Organization $organization): ?OrganizationRole
    {
        if (! $this->context->hasOrganization()
            || $this->context->organizationId() !== $organization->getKey()) {
            return null;
        }

        $membership = OrganizationMembership::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $user->getAuthIdentifier())
            ->where('status', 'active')
            ->first();

        if ($membership === null) {
            return null;
        }

        return OrganizationRole::tryFrom($membership->role);
    }
}
