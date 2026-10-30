<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\InstructorProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Who leads and assists a group's session (docs/product/team.md, т. 5.2): the assignments whose period holds the
 * session's day in the group's time zone. A session whose teachers were changed by hand, or that has started, keeps them.
 */
final class GroupTeachers
{
    /**
     * @param  Collection<int, InstructorProfile>|null  $assignments  the group's assignments, when already loaded
     * @return array<string, array{role: string, source: string}>
     */
    public function forSession(Group $group, CarbonImmutable $startsAt, ?Collection $assignments = null): array
    {
        $timezone = is_string($group->schedule_pattern['timezone'] ?? null) && $group->schedule_pattern['timezone'] !== ''
            ? $group->schedule_pattern['timezone'] : 'Europe/Sofia';
        $day = $startsAt->setTimezone($timezone)->toDateString();
        $teachers = [];
        foreach ($assignments ?? $group->assignments()->get() as $profile) {
            $startsOn = $this->day($profile->pivot->starts_on ?? null);
            $endsOn = $this->day($profile->pivot->ends_on ?? null);
            if (($startsOn === null || $startsOn <= $day) && ($endsOn === null || $endsOn >= $day)) {
                $teachers[(string) $profile->getKey()] = ['role' => (string) ($profile->pivot->role ?? 'lead'), 'source' => 'assignment'];
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
            ->orderBy('starts_at')->lockForUpdate()->with('instructors')->get();
        foreach ($sessions as $session) {
            if ($session->instructors->contains(fn (InstructorProfile $teacher): bool => ($teacher->pivot->source ?? 'assignment') === 'manual')) {
                continue;
            }
            $changes = $session->instructors()->sync($this->forSession($group, $session->starts_at, $assignments));
            if ($changes['attached'] !== [] || $changes['detached'] !== [] || $changes['updated'] !== []) {
                $session->version++;
                $session->save();
            }
        }
    }

    private function day(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr((string) $value, 0, 10);
    }
}
