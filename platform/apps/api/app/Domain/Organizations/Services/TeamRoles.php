<?php

namespace App\Domain\Organizations\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
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
