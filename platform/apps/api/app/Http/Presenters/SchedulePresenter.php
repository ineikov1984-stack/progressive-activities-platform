<?php

namespace App\Http\Presenters;

use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Services\ScheduleReader;
use App\Domain\Catalog\Services\SessionCutoff;
use App\Domain\Catalog\Services\SessionRoles;
use stdClass;

/**
 * How the schedule looks to the team (docs/product/schedule.md, т. 6), built field by field: a session with its
 * teachers and places, the dictionaries, and what the web may show for each group. The children only where the caller
 * found that they may be seen, the reason for a change only to who changes the schedule — never contacts, health or
 * money.
 *
 * @phpstan-import-type SessionTeacher from SessionRoles
 * @phpstan-import-type ScheduleGroup from ScheduleReader
 * @phpstan-import-type ScheduleDictionary from ScheduleReader
 * @phpstan-import-type LastChange from ScheduleReader
 */
final class SchedulePresenter
{
    /**
     * `GET /schedule` (т. 6.1).
     *
     * @param  list<Session>  $sessions
     * @param  array<string, list<SessionTeacher>>  $teachers
     * @param  array<string, ScheduleGroup>  $groups
     * @param  ScheduleDictionary  $dictionary
     * @param  array<string, int>  $taken
     * @param  array<string, int>  $waiting
     * @param  list<string>|null  $childrenGroups  where the children may be seen; null — every group
     * @return array<string, mixed>
     */
    public static function board(array $sessions, array $teachers, array $groups, array $dictionary, array $taken, array $waiting, ?array $childrenGroups, bool $manage): array
    {
        $abilities = [];
        foreach ($dictionary['groups'] as $group) {
            $abilities[$group['id']] = ['children' => $childrenGroups === null || in_array($group['id'], $childrenGroups, true), 'manage' => $manage];
        }

        return [
            'sessions' => array_map(fn (Session $session): array => self::session(
                $session, $teachers[(string) $session->id] ?? [], $groups[(string) $session->group_id] ?? null, $taken, $waiting,
            ), $sessions),
            ...$dictionary,
            // An object by group, also when there is none.
            'abilities' => $abilities === [] ? new stdClass : $abilities,
        ];
    }

    /**
     * `GET /schedule/sessions/{session}` (т. 6.2): the session as in the list, the reason for a change and the children
     * when they may be seen, and the names of its group, activity and place. The latest change of the schedule
     * (docs/product/schedule-changes.md, т. 7.5): what and when for everybody, who and why only with "schedule.manage";
     * the actions the person may take now — only for the team, and none on a session that has started or of an archived
     * group (the change would be refused, т. 4.1).
     *
     * @param  array{session: Session, teachers: list<SessionTeacher>, group: ScheduleGroup, location: array{id: string, name: string}|null, last_change: LastChange|null}  $panel
     * @param  array<string, int>  $taken
     * @param  array<string, int>  $waiting
     * @param  list<array{enrollment_id: string, child_id?: string, name: string, class: string|null, state: string, scope: string}>|null  $children  null — not to be seen
     * @param  bool  $reasons  "schedule.manage" for the whole organization: why and who
     * @param  bool  $manage  and a person of the team: the actions
     * @return array<string, mixed>
     */
    public static function panel(array $panel, array $taken, array $waiting, ?array $children, bool $reasons, bool $manage): array
    {
        $session = $panel['session'];
        $group = $panel['group'];
        $last = $panel['last_change'];
        $actions = [];
        if ($manage && ! $group['archived'] && ! SessionCutoff::passed($session)) {
            $actions = $session->status === 'cancelled' ? ['restore'] : ['move', 'cancel'];
        }

        return [
            'session' => [
                ...self::session($session, $panel['teachers'], $group, $taken, $waiting),
                ...($reasons ? ['change_reason' => $session->change_reason] : []),
                ...($children === null ? [] : ['children' => $children]),
            ],
            'group' => ['id' => $group['id'], 'name' => $group['name'], 'age_label' => $group['age_label']],
            'activity' => ['id' => $group['activity_id'], 'title' => $group['activity_title']],
            'location' => $panel['location'],
            'last_change' => $last === null ? null : [
                'action' => $last['action'],
                'at' => $last['at'],
                ...($reasons ? ['by' => $last['by'], 'reason' => $last['reason']] : []),
            ],
            'actions' => $actions,
            'abilities' => ['children' => $children !== null, 'manage' => $manage],
        ];
    }

    /**
     * @param  list<SessionTeacher>  $teachers
     * @param  ScheduleGroup|null  $group
     * @param  array<string, int>  $taken
     * @param  array<string, int>  $waiting
     * @return array<string, mixed>
     */
    private static function session(Session $session, array $teachers, ?array $group, array $taken, array $waiting): array
    {
        $sessionId = (string) $session->id;
        $groupId = (string) $session->group_id;

        return [
            'id' => $sessionId,
            'starts_at' => $session->starts_at->utc()->toIso8601String(),
            'ends_at' => $session->ends_at->utc()->toIso8601String(),
            'status' => (string) $session->status,
            // The version to send with a change (docs/product/schedule-changes.md, т. 7.5).
            'version' => $session->version,
            // Moved from its time by the group's schedule: "Преместено от сряда, 17:30".
            'moved' => $session->original_starts_at !== null,
            'moved_from' => $session->original_starts_at?->utc()->toIso8601String(),
            'extra' => $session->extra === true,
            'group_id' => $groupId,
            'activity_id' => $group['activity_id'] ?? null,
            'location_id' => $session->location_id === null ? null : (string) $session->location_id,
            'instructors' => $teachers,
            // The places an enrollment for the session counts (`EnrollmentCapacity::remainingForSession`).
            'capacity' => $session->capacity ?? ($group['capacity_max'] ?? null),
            'taken' => $taken[$sessionId] ?? 0,
            'waiting' => $waiting[$groupId] ?? 0,
        ];
    }
}
