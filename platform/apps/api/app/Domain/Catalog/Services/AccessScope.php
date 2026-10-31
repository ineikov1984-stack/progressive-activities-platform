<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\ValueObjects\ScheduleScope;
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
     * The sessions of the schedule the person sees (docs/product/schedule.md, т. 2). Seeing the schedule of one's scope
     * is a right of its own without a key — "catalog.view" is no condition, it is a right for the whole organization:
     * - the scope "all", or "schedule.view_all": every session;
     * - otherwise every session of the person's groups (`TeachingScope`: an assignment that holds now or later, the lead
     *   of the offering) and, with the scope "groups", of the selected groups and of the groups of the selected
     *   activities. The groups that are the person's today (`activeGroupsFor`) are among the person's groups;
     * - a group where the person only stands in: only the sessions they are on, never the whole group.
     */
    public function scheduleGroups(User $user): ScheduleScope
    {
        $access = $this->rights->for($user);
        if ($access->role === null) {
            return ScheduleScope::nothing();
        }
        if ($access->scope === StaffScope::All || $access->has('schedule.view_all')) {
            return ScheduleScope::everything();
        }

        $groups = $access->scope === StaffScope::Groups ? [...$access->groupIds, ...$this->groupsOf($access->activityIds)] : [];
        $standIn = [];
        $profiles = $this->teaching->profilesFor($user);
        foreach ($this->teaching->bases($profiles) as $groupId => $basis) {
            if ($basis === 'substitute') {
                $standIn[] = $groupId;
            } else {
                $groups[] = $groupId;
            }
        }
        $groups = array_values(array_unique($groups));
        sort($groups);
        $standIn = array_values(array_diff($standIn, $groups));
        sort($standIn);

        return new ScheduleScope(false, $groups, $standIn, $this->teaching->sessionsOf($profiles, $standIn));
    }

    /**
     * The groups whose children the panel of a session shows (docs/product/schedule.md, т. 2, 6.2): with
     * "enrollment.view" in the group, or in one's own group — an assignment or the lead of the offering; where the person
     * only stands in, from the day of the session to the end of the next day (`activeGroupsFor`). Null — every group.
     *
     * @return list<string>|null
     */
    public function scheduleChildren(User $user): ?array
    {
        if ($this->rights->for($user)->role === null) {
            return [];
        }
        $viewing = $this->groups($user, 'enrollment.view');
        if ($viewing === null) {
            return null;
        }
        $own = array_keys(array_filter(
            $this->teaching->bases($this->teaching->profilesFor($user)),
            fn (string $basis): bool => $basis !== 'substitute',
        ));
        $groups = array_values(array_unique([...$viewing, ...$own, ...$this->teaching->activeGroupsFor($user)]));
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
