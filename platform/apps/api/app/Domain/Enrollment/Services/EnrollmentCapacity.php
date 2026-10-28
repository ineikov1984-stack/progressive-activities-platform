<?php

namespace App\Domain\Enrollment\Services;

use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Models\VisitOffer;
use App\Domain\Enrollment\Enums\EnrollmentScope;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Enrollment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class EnrollmentCapacity
{
    public function __construct(private readonly SessionApplicability $applicability) {}

    public function remainingForGroup(Group $group, ?CarbonImmutable $onDate = null): ?int
    {
        if (DB::transactionLevel() > 0) {
            $group = Group::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();
        }

        $timezone = $group->schedule_pattern['timezone'] ?? 'Europe/Sofia';
        $date = ($onDate ?? CarbonImmutable::today($timezone))->setTimezone($timezone)->toDateString();
        $occupied = Enrollment::query()->where('group_id', $group->id)
            ->where('scope', EnrollmentScope::FullPeriod)
            ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Paused])
            ->whereDate('starts_on', '<=', $date)
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date))
            ->count();

        $remaining = $group->capacity_max === null ? null : max(0, $group->capacity_max - $occupied);
        $from = CarbonImmutable::parse($date, $timezone)->startOfDay()->utc();
        $sessions = Session::query()->where('group_id', $group->id)
            ->where('status', 'scheduled')->where('starts_at', '>=', $from)->get();
        foreach ($sessions as $session) {
            $sessionRemaining = $this->remainingForSession($session);
            if ($sessionRemaining !== null) {
                $remaining = $remaining === null ? $sessionRemaining : min($remaining, $sessionRemaining);
            }
        }

        return $remaining;
    }

    public function remainingForSession(Session $session): ?int
    {
        // Every capacity path locks the group before sessions, enrollments and applications.
        if (DB::transactionLevel() > 0) {
            Group::query()->whereKey($session->group_id)->lockForUpdate()->firstOrFail();
            $session = Session::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
        }

        $capacity = $session->capacity ?? $session->group->capacity_max;
        if ($capacity === null) {
            return null;
        }

        $query = Enrollment::query()->where('group_id', $session->group_id)
            ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Paused, EnrollmentStatus::Ended]);
        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }

        $occupied = $query->get()
            ->filter(
                fn (Enrollment $enrollment): bool => $this->applicability
                    ->sessionsFor($enrollment)->contains('id', $session->id)
            )->count();

        return max(0, $capacity - $occupied);
    }

    public function remainingForOffer(VisitOffer $offer, Session $session): int
    {
        $total = $this->remainingForSession($session);
        $applications = $offer->applications()->where('session_id', $session->id);
        if (DB::transactionLevel() > 0) {
            $applications->lockForUpdate();
        }
        $applicationIds = $applications->pluck('id');
        $enrollments = Enrollment::query()->whereIn('application_id', $applicationIds)
            ->where('session_id', $session->id)
            ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Paused, EnrollmentStatus::Ended]);
        if (DB::transactionLevel() > 0) {
            $enrollments->lockForUpdate();
        }
        $offerRemaining = max(0, $offer->visit_limit - count($enrollments->get()->all()));

        return $total === null ? $offerRemaining : min($total, $offerRemaining);
    }
}
