<?php

namespace App\Domain\Organizations\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\Invitation;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Models\StaffMember;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Who gives which role (docs/product/team.md, т. 4): never the platform operator's role; an invitation never makes an
 * owner; an administrator gives and changes only coordinator, teacher, accountant and viewer.
 */
final readonly class TeamRoles
{
    /** The roles an administrator gives and changes. */
    public const ADMINISTRATOR_GIVES = [
        OrganizationRole::Coordinator, OrganizationRole::Instructor, OrganizationRole::Finance, OrganizationRole::Viewer,
    ];

    public function actorRole(User $actor, Organization $organization): ?OrganizationRole
    {
        $role = OrganizationMembership::query()->withoutGlobalScopes()
            ->where('organization_id', $organization->id)->where('user_id', $actor->id)->where('status', 'active')
            ->value('role');

        return is_string($role) ? OrganizationRole::tryFrom($role) : null;
    }

    /**
     * The role of a person of the team for their rights (docs/product/team-rights.md, т. 4.1): of the active membership;
     * without one, of the latest pending invitation; otherwise none — then no rights and no scope are kept.
     */
    public function personRole(Organization $organization, StaffMember $member): ?OrganizationRole
    {
        if ($member->user_id !== null) {
            $role = OrganizationMembership::query()->withoutGlobalScopes()->where('organization_id', $organization->id)
                ->where('user_id', $member->user_id)->where('status', 'active')->value('role');
            if (is_string($role)) {
                return OrganizationRole::tryFrom($role);
            }
        }
        $invitation = Invitation::query()->where('staff_member_id', $member->id)->whereNull('accepted_at')
            ->where('expires_at', '>', now())->latest('created_at')->latest('id')->first();

        return $invitation?->role;
    }

    /**
     * The user is an active owner and no other active owner is left: the last owner is neither changed, stopped nor
     * archived (docs/product/team.md, т. 4). Inside the caller's transaction, after its locks.
     */
    public function isLastActiveOwner(Organization $organization, ?string $userId): bool
    {
        if ($userId === null) {
            return false;
        }
        $owners = OrganizationMembership::query()->withoutGlobalScopes()->where('organization_id', $organization->id)
            ->where('role', OrganizationRole::OrganizationOwner->value)->where('status', 'active');

        return (clone $owners)->where('user_id', $userId)->exists()
            && ! (clone $owners)->where('user_id', '<>', $userId)->exists();
    }

    public function assertMayInvite(User $actor, Organization $organization, OrganizationRole $role): void
    {
        if ($role === OrganizationRole::PlatformAdmin || $role === OrganizationRole::OrganizationOwner) {
            throw new AuthorizationException('Тази роля не може да се задава чрез покана.');
        }
        if ($this->actorRole($actor, $organization) === OrganizationRole::OrganizationAdmin
            && ! in_array($role, self::ADMINISTRATOR_GIVES, true)) {
            throw new AuthorizationException('Нямате право да зададете тази роля.');
        }
    }

    public function assertMayChange(User $actor, Organization $organization, OrganizationRole $from, OrganizationRole $to): void
    {
        if ($from === OrganizationRole::PlatformAdmin || $to === OrganizationRole::PlatformAdmin) {
            throw new AuthorizationException('Тази роля не може да се задава.');
        }
        $actorRole = $this->actorRole($actor, $organization);
        if ($actorRole === OrganizationRole::OrganizationOwner) {
            return;
        }
        if ($actorRole !== OrganizationRole::OrganizationAdmin
            || ! in_array($from, self::ADMINISTRATOR_GIVES, true) || ! in_array($to, self::ADMINISTRATOR_GIVES, true)) {
            throw new AuthorizationException('Нямате право да смените тази роля.');
        }
    }
}
