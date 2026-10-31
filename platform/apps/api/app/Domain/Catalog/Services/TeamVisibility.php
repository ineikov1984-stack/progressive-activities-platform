<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\StaffMember;

/**
 * Whom of the team a user sees (docs/product/team-rights.md, т. 9): with the scope "all" everyone; with a narrower one
 * only the people who lead or assist a group of the scope, and the user themselves — and of their groups only those.
 */
final readonly class TeamVisibility
{
    public function __construct(private AccessScope $scope, private StaffTeaching $teaching) {}

    /** @return list<string>|null the people; null — everyone */
    public function people(User $user): ?array
    {
        $groups = $this->groups($user);
        if ($groups === null) {
            return null;
        }
        $self = $this->scope->access($user)->staffMemberId;

        return array_values(array_unique([...$this->teaching->peopleIn($groups), ...($self === null ? [] : [$self])]));
    }

    /** @return list<string>|null the groups of the scope; null — every group */
    public function groups(User $user): ?array
    {
        return $this->scope->groups($user, 'team.view');
    }

    public function sees(User $user, StaffMember $member): bool
    {
        $people = $this->people($user);

        return $people === null || in_array((string) $member->id, $people, true);
    }
}
