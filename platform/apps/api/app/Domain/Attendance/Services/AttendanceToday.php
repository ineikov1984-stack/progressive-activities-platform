<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Services\AccessScope;
use App\Domain\Catalog\Services\ScheduleReader;
use App\Domain\Catalog\Services\TeachingScope;
use App\Domain\Catalog\ValueObjects\ScheduleScope;
use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Actionable teacher and office lists, assembled in batches (attendance.md 5.4–5.5).
 * @phpstan-import-type ScheduleGroup from ScheduleReader
 * @phpstan-import-type ScheduleDictionary from ScheduleReader
 * @phpstan-import-type SessionTeacher from \App\Domain\Catalog\Services\SessionRoles
 */
final readonly class AttendanceToday
{
    public function __construct(private ScheduleReader $reader, private AccessScope $scope, private TeachingScope $teaching,
        private AttendanceAccess $access, private AttendanceSheet $sheet) {}

    /** @return array<string, mixed> */
    public function today(User $user): array
    {
        $now = CarbonImmutable::now(ScheduleReader::TIMEZONE);
        $empty = ['now' => $now->toIso8601String(), 'sessions' => [], 'pending' => []];
        abort_unless($this->scope->access($user)->role?->isTeam() === true, 403, 'Нямате достъп до присъствията.');
        $profiles = $this->teaching->profilesFor($user);
        if ($profiles === []) {
            return $empty;
        }
        $board = $this->board($this->scope->scheduleGroups($user, includeYesterdayAssignments: true), $now->startOfDay()->subDay(), $now->startOfDay()->addDay());
        $sessions = new Collection($board['sessions']);
        $taught = $this->teaching->sessionsTaught($user, $sessions);
        $sessions = $sessions->filter(fn (Session $session): bool => in_array((string) $session->id, $taught, true))->values();
        $assignmentRoles = $this->teaching->assignmentRoles($user, $sessions);
        $notHeld = $this->sheet->notHeld($sessions);
        $rights = $this->access->forSessions($user, $sessions, $notHeld);
        $summaries = $this->sheet->summariesFor($sessions);
        $locations = array_column($board['dictionary']['locations'], 'name', 'id');
        foreach ($sessions as $session) {
            $id = (string) $session->id;
            $group = $board['groups'][(string) $session->group_id];
            $teachers = $board['teachers'][$id] ?? [];
            $mine = array_values(array_filter($teachers, fn (array $teacher): bool => in_array($teacher['profile_id'], $profiles, true)));
            $role = $assignmentRoles[$id] ?? ($mine[0]['role'] ?? 'lead');
            $state = $session->status === 'cancelled' ? 'cancelled' : (isset($notHeld[$id]) ? 'not_held'
                : ($now->lt($session->starts_at) ? 'later' : ($now->lt($session->ends_at) ? 'now' : 'done')));
            $row = ['id' => $id, 'starts_at' => $session->starts_at->utc()->toIso8601String(), 'ends_at' => $session->ends_at->utc()->toIso8601String(),
                'status' => $session->status, 'state' => $state, 'activity' => $group['activity_title'], 'group' => $group['name'],
                'age_label' => $group['age_label'], 'location' => $locations[(string) $session->location_id] ?? null,
                'my_role' => $role,
                'leads' => $role === 'assistant' ? array_column(array_filter($teachers, fn (array $teacher): bool => $teacher['role'] === 'lead' && ! in_array($teacher['profile_id'], $profiles, true)), 'name') : [],
                'replaces' => array_values(array_unique(array_column(array_merge(...array_map(fn (array $teacher): array => $teacher['replaces'] ?? [], $mine)), 'name'))),
                ...$summaries[$id], 'can_mark' => $rights[$id]['mark'], 'opens_at' => $rights[$id]['opens_at'], 'deadline' => $rights[$id]['deadline']];
            if ($session->starts_at->setTimezone(ScheduleReader::TIMEZONE)->isSameDay($now)) {
                $empty['sessions'][] = $row;
            } elseif ($rights[$id]['mark'] && $state === 'done' && $row['counts']['unmarked'] > 0) {
                $empty['pending'][] = $row;
            }
        }

        return $empty;
    }

    /** @return array<string, mixed> */
    public function pending(User $user): array
    {
        $staff = $this->scope->access($user);
        $late = $this->scope->allowsEverywhere($user, 'attendance.correct_late');
        abort_unless($staff->role?->isTeam() === true, 403, 'Нямате право да отбелязвате присъствия.');
        $groups = $late ? null : $this->scope->groups($user, 'attendance.mark');
        abort_if($groups === [] && ! $staff->has('attendance.mark'), 403, 'Нямате право да отбелязвате присъствия.');
        $now = CarbonImmutable::now(ScheduleReader::TIMEZONE);
        $board = $this->board($groups === null ? ScheduleScope::everything() : new ScheduleScope(false, $groups, [], []), $now->startOfDay()->subDays(13), $now->startOfDay()->addDay());
        $sessions = (new Collection($board['sessions']))->filter(fn (Session $session): bool => $session->status === 'scheduled' && $session->starts_at->lte($now));
        $notHeld = $this->sheet->notHeld($sessions);
        $rights = $this->access->forSessions($user, $sessions, $notHeld);
        $counts = $this->sheet->countsFor($sessions);
        $rows = [];
        foreach ($sessions as $session) {
            $id = (string) $session->id;
            if (! $rights[$id]['mark'] || ($counts[$id]['unmarked'] ?? 0) === 0) {
                continue;
            }
            $group = $board['groups'][(string) $session->group_id];
            $rows[] = ['id' => $id, 'starts_at' => $session->starts_at->utc()->toIso8601String(), 'activity' => $group['activity_title'], 'group' => $group['name'],
                'teachers' => implode(', ', array_column(array_filter($board['teachers'][$id] ?? [], fn (array $teacher): bool => in_array($teacher['role'], ['lead', 'substitute'], true)), 'name')),
                'unmarked' => $counts[$id]['unmarked'], 'late' => $rights[$id]['late']];
        }

        return ['total' => count($rows), 'sessions' => array_slice($rows, 0, 20)];
    }

    /** @return array{sessions: list<Session>, teachers: array<string, list<SessionTeacher>>, groups: array<string, ScheduleGroup>, dictionary: ScheduleDictionary} */
    private function board(ScheduleScope $scope, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->reader->board($scope, $from, $to, ['activity' => [], 'group' => [], 'location' => [], 'instructor' => []]);
    }
}
