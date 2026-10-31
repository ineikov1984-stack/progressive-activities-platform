<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Enums\ActivityStatus;
use App\Domain\Catalog\Models\ScheduleChange;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Models\SessionChange;
use App\Domain\Catalog\ValueObjects\ScheduleScope;
use App\Domain\Organizations\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The schedule from the catalog's side (docs/product/schedule.md, т. 5, 6): the sessions a person sees in a period,
 * who teaches each and in what role, and the dictionaries for the filters and the names. Never the children or the
 * places — those are Enrollment's (`EnrollmentCapacity`, `SessionRoster`). Read only, always inside the selected
 * organization, from a constant number of queries whatever the number of sessions. The days are Sofia's.
 *
 * @phpstan-import-type SessionTeacher from SessionRoles
 *
 * @phpstan-type ScheduleGroup array{id: string, name: string, age_label: string|null, activity_id: string, activity_title: string, activity_current: bool, location_id: string|null, capacity_max: int|null, lead_profile_id: string|null, lead_name: string|null}
 * @phpstan-type ScheduleDictionary array{activities: list<array{id: string, title: string, color: int}>, groups: list<array{id: string, name: string, activity_id: string, age_label: string|null}>, locations: list<array{id: string, name: string}>, instructors: list<array{id: string, name: string}>}
 * @phpstan-type Assignment array{profile_id: string, name: string, role: string, starts_on: string|null, ends_on: string|null}
 * @phpstan-type Teacher array{profile_id: string, name: string}
 * @phpstan-type LastChange array{action: string, at: string, by: string|null, reason: string|null}
 */
final readonly class ScheduleReader
{
    public const TIMEZONE = 'Europe/Sofia';

    /** The colours of the activities (`--cat-*` on the web): by the activities' order, repeated after the fifth (т. 3.6). */
    private const COLORS = 5;

    public function __construct(private TenantContext $context) {}

    /**
     * The sessions the person sees from one moment to another, and what the schedule shows with them (т. 6.1):
     * - `sessions` — those that pass the filters (any value of a filter, every filter), by start, activity and group;
     * - `teachers` — who teaches each session, with the role (`SessionRoles`);
     * - `groups` — the groups of the dictionary and of every session, by id;
     * - `dictionary` — from the person's groups, not from the period or the filters, so that the filters stay the same
     *   week after week; where the person only stands in, only the groups, places and people of their own sessions.
     *
     * @param  array{activity: list<string>, group: list<string>, instructor: list<string>, location: list<string>}  $filters
     * @return array{sessions: list<Session>, teachers: array<string, list<SessionTeacher>>, groups: array<string, ScheduleGroup>, dictionary: ScheduleDictionary}
     */
    public function board(ScheduleScope $scope, CarbonImmutable $start, CarbonImmutable $end, array $filters): array
    {
        if ($scope->isEmpty()) {
            return ['sessions' => [], 'teachers' => [], 'groups' => [], 'dictionary' => ['activities' => [], 'groups' => [], 'locations' => [], 'instructors' => []]];
        }

        $period = $this->visible($scope)->where('starts_at', '>=', $start->utc())->where('starts_at', '<', $end->utc())
            ->orderBy('starts_at')->orderBy('id')->get();
        // Where the person only stands in, their sessions of any day tell the dictionary whom and where.
        $seen = $scope->sessionIds === [] ? $period : $period->merge($this->visible($scope)->whereKey($scope->sessionIds)->get());

        $groups = $this->groupRows($scope->groups(), self::groupsOf($period));
        $covered = array_values(array_filter(array_keys($groups), fn (string $groupId): bool => $scope->coversGroup($groupId)));
        $onSession = $this->onSession(array_values(array_map(fn (Session $session): string => (string) $session->id, $seen->all())));
        $assignments = $this->assignments(array_values(array_unique([...self::groupsOf($period), ...$covered])));
        $teachers = $this->teachers($period, $onSession, $assignments, $groups);

        $sessions = array_values(array_filter($period->all(), fn (Session $session): bool => self::matches($session, $filters, $groups, $teachers)));
        usort($sessions, fn (Session $a, Session $b): int => self::order($a, $groups) <=> self::order($b, $groups));

        return [
            'sessions' => $sessions,
            'teachers' => $teachers,
            'groups' => $groups,
            'dictionary' => $this->dictionary($groups, $covered, $seen, $onSession, $assignments),
        ];
    }

    /**
     * One session the person sees, for its panel (т. 6.2): the session, who teaches it, its group, its place and its
     * latest change from the schedule (docs/product/schedule-changes.md, т. 7.5) — none for a session cancelled in the
     * draft's editor. Null — a session of another organization, outside the person's sessions, or of a draft.
     *
     * @return array{session: Session, teachers: list<SessionTeacher>, group: ScheduleGroup, location: array{id: string, name: string}|null, last_change: LastChange|null}|null
     */
    public function panel(ScheduleScope $scope, string $sessionId): ?array
    {
        $session = $scope->isEmpty() ? null : $this->visible($scope)->whereKey($sessionId)->first();
        if ($session === null) {
            return null;
        }
        $groupId = (string) $session->group_id;
        $group = $this->groupRows([$groupId], [$groupId])[$groupId] ?? null;
        if ($group === null) {
            return null;
        }
        $teachers = $this->teachers([$session], $this->onSession([(string) $session->id]), $this->assignments([$groupId]), [$groupId => $group]);
        $location = $session->location_id === null ? [] : $this->locations([(string) $session->location_id]);

        return [
            'session' => $session,
            'teachers' => $teachers[(string) $session->id] ?? [],
            'group' => $group,
            'location' => $location[0] ?? null,
            'last_change' => $this->lastChange((string) $session->id),
        ];
    }

    /**
     * The latest change of the session (the identifiers of the changes are time-ordered) and who made it — the name of
     * their person of the team, else of the account.
     *
     * @return LastChange|null
     */
    private function lastChange(string $sessionId): ?array
    {
        $last = SessionChange::query()->where('session_id', $sessionId)->orderByDesc('schedule_change_id')->first(['schedule_change_id']);
        $change = $last === null ? null : ScheduleChange::query()->find($last->schedule_change_id);
        if ($change === null) {
            return null;
        }
        $by = DB::table('staff_members')->where('organization_id', $this->context->organizationId())
            ->where('user_id', $change->actor_user_id)->whereNull('archived_at')->value('full_name')
            ?? DB::table('users')->where('id', $change->actor_user_id)->value('name');

        return [
            'action' => $change->action,
            'at' => $change->created_at->utc()->toIso8601String(),
            'by' => is_string($by) && $by !== '' ? $by : null,
            'reason' => $change->reason,
        ];
    }

    /**
     * The sessions of the person's groups and their own sessions where they only stand in. Never a draft's: its
     * sessions are made again at every change of its schedule, they are not yet sessions to plan around.
     *
     * @return Builder<Session>
     */
    private function visible(ScheduleScope $scope): Builder
    {
        $query = Session::query()->whereHas('group', fn ($group) => $group->where('status', '!=', 'draft'));
        if (! $scope->all) {
            $query->where(fn ($either) => $either->whereIn('group_id', $scope->groupIds)->orWhereIn('id', $scope->sessionIds));
        }

        return $query;
    }

    /**
     * The groups for the dictionary and the sessions: not archived and of an activity not archived, and any group with
     * a session in the period. Never a draft.
     *
     * @param  list<string>|null  $groupIds  null — every group
     * @param  list<string>  $withSessions
     * @return array<string, ScheduleGroup>
     */
    private function groupRows(?array $groupIds, array $withSessions): array
    {
        $organizationId = $this->context->organizationId();
        $rows = DB::table('activity_groups')
            ->join('offerings', 'offerings.id', '=', 'activity_groups.offering_id')
            ->join('activities', 'activities.id', '=', 'offerings.activity_id')
            ->leftJoin('instructor_profiles as leads', fn ($join) => $join->on('leads.id', '=', 'offerings.lead_instructor_profile_id')
                ->where('leads.organization_id', '=', $organizationId))
            ->where('activity_groups.organization_id', $organizationId)
            ->where('offerings.organization_id', $organizationId)
            ->where('activities.organization_id', $organizationId)
            ->where('activity_groups.status', '!=', 'draft')
            ->when($groupIds !== null, fn ($query) => $query->whereIn('activity_groups.id', $groupIds ?? []))
            ->where(fn ($query) => $query
                ->where(fn ($current) => $current->where('activity_groups.status', '!=', 'archived')
                    ->where('activities.status', '!=', ActivityStatus::Archived->value))
                ->orWhereIn('activity_groups.id', $withSessions))
            ->get([
                'activity_groups.id', 'activity_groups.name', 'activity_groups.age_label', 'activity_groups.location_id',
                'activity_groups.capacity_max', 'activities.id as activity_id', 'activities.title',
                'activities.age_label as activity_age_label', 'activities.status as activity_status',
                'offerings.lead_instructor_profile_id', 'leads.public_name as lead_name',
            ]);

        $result = [];
        foreach ($rows as $row) {
            $id = (string) $row->id;
            $result[$id] = [
                'id' => $id,
                'name' => (string) $row->name,
                'age_label' => self::text($row->age_label) ?? self::text($row->activity_age_label),
                'activity_id' => (string) $row->activity_id,
                'activity_title' => (string) $row->title,
                'activity_current' => $row->activity_status !== ActivityStatus::Archived->value,
                'location_id' => self::text($row->location_id),
                'capacity_max' => $row->capacity_max === null ? null : (int) $row->capacity_max,
                'lead_profile_id' => self::text($row->lead_instructor_profile_id),
                'lead_name' => self::text($row->lead_name),
            ];
        }

        return $result;
    }

    /**
     * The teachers on each session, as the schedule names them (the public name of the profile).
     *
     * @param  list<string>  $sessionIds
     * @return array<string, list<Teacher>> session => teachers
     */
    private function onSession(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }
        $organizationId = $this->context->organizationId();
        $result = [];
        foreach (DB::table('activity_session_instructors')
            ->join('instructor_profiles', 'instructor_profiles.id', '=', 'activity_session_instructors.instructor_profile_id')
            ->where('activity_session_instructors.organization_id', $organizationId)
            ->where('instructor_profiles.organization_id', $organizationId)
            ->whereIn('activity_session_instructors.session_id', $sessionIds)
            ->orderBy('instructor_profiles.id')
            ->get(['activity_session_instructors.session_id', 'instructor_profiles.id', 'instructor_profiles.public_name']) as $row) {
            $result[(string) $row->session_id][] = ['profile_id' => (string) $row->id, 'name' => (string) $row->public_name];
        }

        return $result;
    }

    /**
     * Every assignment of the groups, ended ones included: the role of a session's teacher is the one that holds on its day.
     *
     * @param  list<string>  $groupIds
     * @return array<string, list<Assignment>> group => assignments
     */
    private function assignments(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }
        $organizationId = $this->context->organizationId();
        $day = fn (mixed $date): ?string => $date === null || $date === '' ? null : substr((string) $date, 0, 10);
        $result = [];
        foreach (DB::table('activity_group_instructors')
            ->join('instructor_profiles', 'instructor_profiles.id', '=', 'activity_group_instructors.instructor_profile_id')
            ->where('activity_group_instructors.organization_id', $organizationId)
            ->where('instructor_profiles.organization_id', $organizationId)
            ->whereIn('activity_group_instructors.group_id', $groupIds)
            ->orderBy('activity_group_instructors.group_id')->orderBy('instructor_profiles.id')
            ->get([
                'activity_group_instructors.group_id', 'activity_group_instructors.instructor_profile_id', 'activity_group_instructors.role',
                'activity_group_instructors.starts_on', 'activity_group_instructors.ends_on', 'instructor_profiles.public_name',
            ]) as $row) {
            $result[(string) $row->group_id][] = [
                'profile_id' => (string) $row->instructor_profile_id,
                'name' => (string) $row->public_name,
                'role' => (string) $row->role,
                'starts_on' => $day($row->starts_on),
                'ends_on' => $day($row->ends_on),
            ];
        }

        return $result;
    }

    /**
     * @param  iterable<Session>  $sessions
     * @param  array<string, list<Teacher>>  $onSession
     * @param  array<string, list<Assignment>>  $assignments
     * @param  array<string, ScheduleGroup>  $groups
     * @return array<string, list<SessionTeacher>> session => teachers with their roles
     */
    private function teachers(iterable $sessions, array $onSession, array $assignments, array $groups): array
    {
        $result = [];
        foreach ($sessions as $session) {
            $sessionId = (string) $session->id;
            $groupId = (string) $session->group_id;
            $result[$sessionId] = SessionRoles::derive(
                $onSession[$sessionId] ?? [],
                $assignments[$groupId] ?? [],
                $groups[$groupId]['lead_profile_id'] ?? null,
                $session->starts_at->setTimezone(self::TIMEZONE)->toDateString(),
            );
        }

        return $result;
    }

    /**
     * The dictionaries (т. 6.1): the activities with their colour, the groups, the places and the people. Of a group
     * whose sessions the person sees all of, also its place, its assignments that have not ended and the lead of its
     * offering; of every session seen, its place and its teachers.
     *
     * @param  array<string, ScheduleGroup>  $groups
     * @param  list<string>  $covered  the groups whose every session the person sees
     * @param  Collection<int, Session>  $seen
     * @param  array<string, list<Teacher>>  $onSession
     * @param  array<string, list<Assignment>>  $assignments
     * @return ScheduleDictionary
     */
    private function dictionary(array $groups, array $covered, Collection $seen, array $onSession, array $assignments): array
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->toDateString();
        $activities = [];
        $locations = [];
        $people = [];
        foreach ($groups as $group) {
            $activities[$group['activity_id']] ??= ['id' => $group['activity_id'], 'title' => $group['activity_title'], 'current' => $group['activity_current']];
        }
        foreach ($covered as $groupId) {
            $group = $groups[$groupId];
            if ($group['location_id'] !== null) {
                $locations[$group['location_id']] = true;
            }
            if ($group['lead_profile_id'] !== null) {
                $people[$group['lead_profile_id']] = $group['lead_name'] ?? '';
            }
            foreach ($assignments[$groupId] ?? [] as $assignment) {
                if ($assignment['ends_on'] === null || $assignment['ends_on'] >= $today) {
                    $people[$assignment['profile_id']] = $assignment['name'];
                }
            }
        }
        foreach ($seen as $session) {
            if ($session->location_id !== null) {
                $locations[(string) $session->location_id] = true;
            }
            foreach ($onSession[(string) $session->id] ?? [] as $teacher) {
                $people[$teacher['profile_id']] = $teacher['name'];
            }
        }

        // The activities still running first, so that an archived one seen in a past week does not move the colours.
        $activities = array_values($activities);
        usort($activities, fn (array $a, array $b): int => [! $a['current'], mb_strtolower($a['title']), $a['id']] <=> [! $b['current'], mb_strtolower($b['title']), $b['id']]);
        $groupList = array_values($groups);
        usort($groupList, fn (array $a, array $b): int => self::groupKey($a) <=> self::groupKey($b));
        $instructors = [];
        foreach ($people as $profileId => $name) {
            $instructors[] = ['id' => (string) $profileId, 'name' => $name];
        }
        usort($instructors, fn (array $a, array $b): int => [mb_strtolower($a['name']), $a['id']] <=> [mb_strtolower($b['name']), $b['id']]);

        return [
            'activities' => array_map(fn (array $activity, int $index): array => [
                'id' => $activity['id'], 'title' => $activity['title'], 'color' => $index % self::COLORS,
            ], $activities, array_keys($activities)),
            'groups' => array_map(fn (array $group): array => [
                'id' => $group['id'], 'name' => $group['name'], 'activity_id' => $group['activity_id'], 'age_label' => $group['age_label'],
            ], $groupList),
            'locations' => $this->locations(array_map('strval', array_keys($locations))),
            'instructors' => $instructors,
        ];
    }

    /**
     * @param  list<string>  $locationIds
     * @return list<array{id: string, name: string}> by name
     */
    private function locations(array $locationIds): array
    {
        if ($locationIds === []) {
            return [];
        }
        $result = [];
        foreach (DB::table('locations')->where('organization_id', $this->context->organizationId())->whereIn('id', $locationIds)
            ->orderBy('name')->orderBy('id')->get(['id', 'name']) as $row) {
            $result[] = ['id' => (string) $row->id, 'name' => (string) $row->name];
        }

        return $result;
    }

    /**
     * @param  Collection<int, Session>  $sessions
     * @return list<string>
     */
    private static function groupsOf(Collection $sessions): array
    {
        $groups = [];
        foreach ($sessions as $session) {
            $groups[(string) $session->group_id] = true;
        }

        return array_map('strval', array_keys($groups));
    }

    /**
     * @param  array{activity: list<string>, group: list<string>, instructor: list<string>, location: list<string>}  $filters
     * @param  array<string, ScheduleGroup>  $groups
     * @param  array<string, list<SessionTeacher>>  $teachers
     */
    private static function matches(Session $session, array $filters, array $groups, array $teachers): bool
    {
        $groupId = (string) $session->group_id;
        $location = $session->location_id === null ? null : (string) $session->location_id;

        return ($filters['group'] === [] || in_array($groupId, $filters['group'], true))
            && ($filters['activity'] === [] || in_array($groups[$groupId]['activity_id'] ?? '', $filters['activity'], true))
            && ($filters['location'] === [] || ($location !== null && in_array($location, $filters['location'], true)))
            && ($filters['instructor'] === [] || array_intersect(array_column($teachers[(string) $session->id] ?? [], 'profile_id'), $filters['instructor']) !== []);
    }

    /**
     * By start, then the activity's title and the group's name.
     *
     * @param  array<string, ScheduleGroup>  $groups
     * @return array{int, string, string, string}
     */
    private static function order(Session $session, array $groups): array
    {
        $group = $groups[(string) $session->group_id] ?? null;

        return [
            $session->starts_at->getTimestamp(),
            mb_strtolower($group['activity_title'] ?? ''),
            mb_strtolower($group['name'] ?? ''),
            (string) $session->id,
        ];
    }

    /**
     * @param  ScheduleGroup  $group
     * @return array{string, string, string}
     */
    private static function groupKey(array $group): array
    {
        return [mb_strtolower($group['activity_title']), mb_strtolower($group['name']), $group['id']];
    }

    private static function text(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
