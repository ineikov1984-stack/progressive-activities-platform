<?php

namespace App\Domain\Enrollment\Services;

use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\Session;
use App\Domain\Enrollment\Enums\EnrollmentScope;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Enrollment;
use Carbon\CarbonImmutable;

final class EnrollmentCapacity
{
    public function __construct(private readonly SessionApplicability $applicability) {}

    public function remainingForGroup(Group $group, ?CarbonImmutable $onDate = null): ?int
    {
        if ($group->capacity_max === null) {
            return null;
        }

        $date = ($onDate ?? CarbonImmutable::today())->toDateString();
        $occupied = Enrollment::query()->where('group_id', $group->id)
            ->where('scope', EnrollmentScope::FullPeriod)
            ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Paused])
            ->whereDate('starts_on', '<=', $date)
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date))
            ->count();

        return max(0, $group->capacity_max - $occupied);
    }

    public function remainingForSession(Session $session): ?int
    {
        $capacity = $session->capacity ?? $session->group->capacity_max;
        if ($capacity === null) {
            return null;
        }

        $occupied = Enrollment::query()->where('group_id', $session->group_id)
            ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Paused, EnrollmentStatus::Ended])
            ->get()
            ->filter(
                fn (Enrollment $enrollment): bool => $this->applicability
                    ->sessionsFor($enrollment)->contains('id', $session->id)
            )->count();

        return max(0, $capacity - $occupied);
    }
}
