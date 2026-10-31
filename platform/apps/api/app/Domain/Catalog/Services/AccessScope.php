<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Enums\AbilityKind;
use App\Domain\Organizations\Enums\StaffScope;
use App\Domain\Organizations\Services\StaffRights;
use App\Domain\Organizations\Services\TeamAbilities;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Domain\Organizations\ValueObjects\StaffAccess;
use Illuminate\Support\Facades\DB;

/**
 * May the user do something in a group (docs/product/team-rights.md, т. 4.4, 4.5) — the one question that Enrollment
 * and, later, Attendance and the schedule ask. The rights come from `StaffRights` (Organizations); which groups are the
 * person's from `TeachingScope`; a selected activity covers its groups, also the later ones.
 * - a right for teaching in one of the person's groups: yes, whatever the role; seeing the children of one's groups
 *   ("enrollment.view_own") holds nowhere else — the role's key only says the person may;
 * - a right the person does not have: no;
 * - a right for the organization: yes;
 * - the scope "all": yes; "own": the groups that are the person's today; "groups": those and the selected ones.
 */
final readonly class AccessScope
{
    public function __construct(
        private StaffRights $rights,
        private TeachingScope $teaching,
        private TenantContext $context,
    ) {}

    public function access(User $user): StaffAccess
    {
        return $this->rights->for($user);
    }

    /** For the whole organization: a right of a group only with the scope "all" (т. 2.2). */
    public function allowsEverywhere(User $user, string $ability): bool
    {
        return $this->rights->for($user)->allowsAcrossOrganization($ability);
    }

    public function allows(User $user, string $ability, string $groupId): bool
    {
        $access = $this->rights->for($user);
        if ($access->role === null) {
            return false;
        }
        if (TeamAbilities::isTeaching($ability) && in_array($groupId, $this->teaching->groupsFor($user), true)) {
            return true;
        }
        if (TeamAbilities::isOwnGroupsOnly($ability) || ! $access->has($ability)) {
            return false;
        }
        if (TeamAbilities::kind($ability) !== AbilityKind::Group) {
            return true;
        }

        return match ($access->scope) {
            StaffScope::All => true,
            StaffScope::Own => in_array($groupId, $this->teaching->activeGroupsFor($user), true),
            StaffScope::Groups => in_array($groupId, $access->groupIds, true)
                || in_array($groupId, $this->teaching->activeGroupsFor($user), true)
                || in_array($groupId, $this->groupsOf($access->activityIds), true),
        };
    }

    /**
     * The groups where the right holds, for the filters of the lists: null — every group of the organization.
     *
     * @return list<string>|null
     */
    public function groups(User $user, string $ability): ?array
    {
        $access = $this->rights->for($user);
        if ($access->role === null) {
            return [];
        }
        $teaching = TeamAbilities::isTeaching($ability) ? $this->teaching->groupsFor($user) : [];
        if (TeamAbilities::isOwnGroupsOnly($ability) || ! $access->has($ability)) {
            return $teaching;
        }
        if (TeamAbilities::kind($ability) !== AbilityKind::Group || $access->scope === StaffScope::All) {
            return null;
        }
        $selected = $access->scope === StaffScope::Groups
            ? [...$access->groupIds, ...$this->groupsOf($access->activityIds)]
            : [];
        $groups = array_values(array_unique([...$teaching, ...$this->teaching->activeGroupsFor($user), ...$selected]));
        sort($groups);

        return $groups;
    }

    /**
     * The groups of the selected activities now, also those created after the choice.
     *
     * @param  list<string>  $activityIds
     * @return list<string>
     */
    private function groupsOf(array $activityIds): array
    {
        if ($activityIds === []) {
            return [];
        }
        $organizationId = $this->context->organizationId();

        return array_values(DB::table('activity_groups')
            ->join('offerings', 'offerings.id', '=', 'activity_groups.offering_id')
            ->where('activity_groups.organization_id', $organizationId)
            ->where('offerings.organization_id', $organizationId)
            ->whereIn('offerings.activity_id', $activityIds)
            ->orderBy('activity_groups.id')
            ->pluck('activity_groups.id')->map(fn (mixed $id): string => (string) $id)->all());
    }
}
