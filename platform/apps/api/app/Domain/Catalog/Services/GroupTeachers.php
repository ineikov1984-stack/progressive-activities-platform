<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\InstructorProfile;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Who leads and assists a group's session (docs/product/team.md, т. 5.2): the assignments whose period holds the
 * session's day in the group's time zone. A session whose teachers were changed by hand, or that has started, keeps them.
 */
final class GroupTeachers
{
    /**
     * @param  Collection<int, InstructorProfile>|null  $assignments  the group's assignments, when already loaded
     * @return array<string, array{role: string}>
     */
    public function forSession(Group $group, CarbonImmutable $startsAt, ?Collection $assignments = null): array
    {
        $timezone = is_string($group->schedule_pattern['timezone'] ?? null) && $group->schedule_pattern['timezone'] !== ''
            ? $group->schedule_pattern['timezone'] : 'Europe/Sofia';
        $day = $startsAt->setTimezone($timezone)->toDateString();
        $teachers = [];
        foreach ($assignments ?? $group->assignments()->get() as $profile) {
            $assignment = self::assignment($profile);
            if (self::holds($assignment, $day)) {
                $teachers[(string) $profile->getKey()] = ['role' => $assignment['role']];
            }
        }

        return $teachers;
    }

    /** Applies the assignments to the group's sessions that have not started and were not changed by hand. */
    public function apply(Group $group): void
    {
        $assignments = $group->assignments()->get();
        // A draft group has not run yet: all its sessions follow the assignments.
        $sessions = $group->sessions()->where('status', 'scheduled')
            ->when($group->status !== 'draft', fn ($query) => $query->where('starts_at', '>', now()))
            // Changed by hand, also to no teacher at all: the session keeps its teachers.
            ->where('instructors_source', 'assignment')
            ->orderBy('starts_at')->lockForUpdate()->get();
        foreach ($sessions as $session) {
            $changes = $session->instructors()->sync($this->forSession($group, $session->starts_at, $assignments));
            if ($changes['attached'] !== [] || $changes['detached'] !== [] || $changes['updated'] !== []) {
                $session->version++;
                $session->save();
            }
        }
        app(SubstituteRequestLocks::class)->noLongerTeaching($group);
    }

    /** @param array{starts_on: string|null, ends_on: string|null} $assignment */
    public static function holds(array $assignment, string $day): bool
    {
        return ($assignment['starts_on'] === null || $assignment['starts_on'] <= $day)
            && ($assignment['ends_on'] === null || $assignment['ends_on'] >= $day);
    }

    /**
     * The assignment on a teacher loaded through a group's `assignments()` or a session's `instructors()`.
     *
     * @return array{role: string, starts_on: string|null, ends_on: string|null, version: int}
     */
    public static function assignment(InstructorProfile $profile): array
    {
        $pivot = $profile->getRelation('pivot');
        $value = fn (string $key): mixed => $pivot instanceof Model ? $pivot->getAttribute($key) : null;
        $day = fn (mixed $date): ?string => $date === null || $date === '' ? null : substr((string) $date, 0, 10);

        return [
            'role' => (string) ($value('role') ?? 'lead'),
            'starts_on' => $day($value('starts_on')),
            'ends_on' => $day($value('ends_on')),
            'version' => (int) ($value('version') ?? 1),
        ];
    }
}
