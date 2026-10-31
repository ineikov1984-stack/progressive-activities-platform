<?php

namespace App\Domain\Enrollment\Services;

use App\Domain\Enrollment\Enums\EnrollmentScope;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Child;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentPause;
use Carbon\CarbonImmutable;

/**
 * The card of a child in the schedule (docs/product/schedule.md, т. 4.1): the child and their enrollments in the groups
 * given — never anywhere else. The `children` table has no RLS: the child is found only through the organization's
 * enrollments (the tenant scope of `Enrollment` and the RLS of `enrollments`) by the rule of the search for the filter
 * (`EnrolledChildSearch::enrolled`); without such an enrollment there is no card. Name, class, the age in full years
 * and the groups with their state — never the birth date, contacts, health or money.
 *
 * @phpstan-type CardGroup array{enrollment_id: string, group_id: string, activity: string|null, group: string|null, version: int, state: string, paused_until: string|null, ended_on: string|null}
 * @phpstan-type Card array{id: string, name: string, class: string|null, age: int|null, groups: list<CardGroup>}
 */
final readonly class ChildCard
{
    private const TIMEZONE = 'Europe/Sofia';

    /**
     * The card, or null when the child has no enrollment in these groups.
     *
     * @param  list<string>|null  $groupIds  the groups where the person sees the children; null — every group
     * @return Card|null
     */
    public function find(string $childId, ?array $groupIds): ?array
    {
        if ($groupIds === []) {
            return null;
        }
        $query = Enrollment::query()->where('child_id', $childId);
        EnrolledChildSearch::enrolled($query);
        $enrollments = $query->when($groupIds !== null, fn ($inGroups) => $inGroups->whereIn('group_id', $groupIds ?? []))
            ->with(['child', 'group.offering.activity', 'pauses' => fn ($pauses) => $pauses->whereNull('cancelled_at')->orderBy('starts_on')])
            ->get();
        $child = $enrollments->first()?->child;
        if ($child === null) {
            return null;
        }

        $groups = [];
        foreach ($enrollments as $enrollment) {
            $groups[] = $this->group($enrollment);
        }
        // By activity and group; the enrollment keeps the order the same for two of one group.
        usort($groups, fn (array $a, array $b): int => [mb_strtolower((string) $a['activity']), mb_strtolower((string) $a['group']), $a['enrollment_id']]
            <=> [mb_strtolower((string) $b['activity']), mb_strtolower((string) $b['group']), $b['enrollment_id']]);

        return [
            'id' => (string) $child->id,
            'name' => trim($child->first_name.' '.$child->last_name),
            'class' => $child->class_label,
            'age' => self::age($child),
            'groups' => $groups,
        ];
    }

    /**
     * One group of the child, with the state in a word: "trial" and "single" for a visit; "ended" — the last day has
     * passed (in the 90 days the search looks back); "paused" — a pause that holds today; otherwise "active", also for
     * an enrollment that begins later.
     *
     * @return CardGroup
     */
    private function group(Enrollment $enrollment): array
    {
        $today = CarbonImmutable::now(SessionApplicability::timezone($enrollment))->toDateString();
        $pause = $enrollment->pauses->first(
            fn (EnrollmentPause $pause): bool => $pause->starts_on->toDateString() <= $today && $pause->ends_on->toDateString() >= $today,
        );
        $endedOn = $enrollment->scope === EnrollmentScope::FullPeriod && $enrollment->ends_on !== null && $enrollment->ends_on->toDateString() < $today
            ? $enrollment->ends_on->toDateString() : null;
        $state = match (true) {
            $enrollment->scope === EnrollmentScope::Trial => 'trial',
            $enrollment->scope === EnrollmentScope::Single => 'single',
            $endedOn !== null => 'ended',
            $pause !== null, $enrollment->status === EnrollmentStatus::Paused => 'paused',
            default => 'active',
        };

        return [
            'enrollment_id' => (string) $enrollment->id,
            'group_id' => (string) $enrollment->group_id,
            'activity' => $enrollment->group?->offering?->activity?->title,
            'group' => $enrollment->group?->name,
            // For "Пропусни": a change is refused on an older version (409).
            'version' => (int) $enrollment->version,
            'state' => $state,
            'paused_until' => $state === 'paused' ? $pause?->ends_on->toDateString() : null,
            'ended_on' => $endedOn,
        ];
    }

    /** Full years by Sofia's calendar today — the birthday counts from its first minute; null without a birth date. */
    private static function age(Child $child): ?int
    {
        $birth = $child->birth_date;
        if ($birth === null) {
            return null;
        }
        // Two calendar days at the same hour: no time of day or zone moves the birthday.
        $born = CarbonImmutable::parse($birth->toDateString(), 'UTC');
        $today = CarbonImmutable::parse(CarbonImmutable::now(self::TIMEZONE)->toDateString(), 'UTC');

        return max(0, (int) floor($born->diffInYears($today)));
    }
}
