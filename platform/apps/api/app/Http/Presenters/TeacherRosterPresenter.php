<?php

namespace App\Http\Presenters;

use App\Domain\Catalog\Models\Group;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentPause;
use App\Domain\Enrollment\Models\EnrollmentSessionException;

/**
 * How a group and its children look to a teacher who sees only their own groups (docs/product/team.md, т. 8): for each
 * child the name, the class, from–to, pauses and skipped sessions. Built field by field, not by hiding fields of the
 * team's roster: never parents, contacts, prices, the waiting list, reasons, notes or versions.
 */
final class TeacherRosterPresenter
{
    /**
     * A row of the teacher's group list: the team's row without the waiting list and the approval setting.
     *
     * @return array<string, mixed>
     */
    public static function group(Group $group, int $placesTaken): array
    {
        $activity = $group->offering?->activity;

        return [
            'id' => $group->id,
            'name' => $group->name,
            'status' => $group->status,
            'capacity_max' => $group->capacity_max,
            'activity_id' => $activity?->id,
            'activity_title' => $activity?->title,
            'activity_category' => $activity?->category,
            'schedule' => $group->weeklySlots(),
            'location_name' => $group->location->name ?? $group->offering?->location?->name,
            'enrolled_for_period' => $placesTaken,
        ];
    }

    /**
     * @param  iterable<Enrollment>  $enrollments  loaded with `child`, `pauses` and `sessionExceptions.session`
     * @param  bool  $canRevealContact  "sees the phone" added in the group: each row may show the parent's contact on
     *                                  request — never in the list itself, not even the parent's name (docs/product/team-rights.md, т. 6)
     * @return array{group: array<string, mixed>, enrollments: list<array<string, mixed>>}
     */
    public static function roster(Group $group, iterable $enrollments, string $today, bool $canRevealContact = false): array
    {
        $rows = [];
        foreach ($enrollments as $enrollment) {
            $rows[] = self::enrollment($enrollment, $today) + ($canRevealContact
                ? ['can_reveal_contact' => true, 'enrollment_id' => $enrollment->id] : []);
        }

        return [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'activity_id' => $group->offering?->activity?->id,
                'activity_title' => $group->offering?->activity?->title,
            ],
            'enrollments' => $rows,
        ];
    }

    /** @return array<string, mixed> */
    private static function enrollment(Enrollment $enrollment, string $today): array
    {
        $child = $enrollment->child;

        return [
            'id' => $enrollment->id,
            'child' => [
                'name' => $child === null ? null : trim($child->first_name.' '.$child->last_name),
                'class_label' => $child?->class_label,
            ],
            'starts_on' => $enrollment->starts_on->format('Y-m-d'),
            'ends_on' => $enrollment->ends_on?->format('Y-m-d'),
            'paused_today' => $enrollment->pauses->contains(
                fn (EnrollmentPause $pause): bool => $pause->starts_on->toDateString() <= $today && $pause->ends_on->toDateString() >= $today,
            ),
            // The days only: the category of a pause can say "health".
            'pauses' => $enrollment->pauses->map(fn (EnrollmentPause $pause): array => [
                'starts_on' => $pause->starts_on->format('Y-m-d'),
                'ends_on' => $pause->ends_on->format('Y-m-d'),
            ])->values()->all(),
            'skipped_sessions' => $enrollment->sessionExceptions
                ->filter(fn (EnrollmentSessionException $exception): bool => $exception->session !== null && $exception->session->starts_at->isFuture())
                ->sortBy(fn (EnrollmentSessionException $exception): string => $exception->session?->starts_at->toIso8601String() ?? '')
                ->map(fn (EnrollmentSessionException $exception): array => [
                    'session_id' => $exception->session_id,
                    'starts_at' => $exception->session?->starts_at->toIso8601String(),
                ])->values()->all(),
        ];
    }
}
