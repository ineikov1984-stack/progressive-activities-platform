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

    public function remainingForGroup(Group $group, ?CarbonImmutable $at = null, bool $forAllocation = false): ?int
    {
        if ($forAllocation) {
            $this->requireTransaction();
            $group = Group::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();
        }

        $timezone = $group->schedule_pattern['timezone'] ?? 'Europe/Sofia';
        $at ??= CarbonImmutable::now();
        $date = $at->setTimezone($timezone)->toDateString();
        $occupied = $this->placesTaken($group, $date);

        $remaining = $group->capacity_max === null ? null : max(0, $group->capacity_max - $occupied);
        $sessions = Session::query()->where('group_id', $group->id)
            ->where('status', 'scheduled')->where('starts_at', '>', $at->utc())->orderBy('id')->get();
        foreach ($sessions as $session) {
            $sessionRemaining = $this->remainingForSession($session, $forAllocation);
            if ($sessionRemaining !== null) {
                $remaining = $remaining === null ? $sessionRemaining : min($remaining, $sessionRemaining);
            }
        }

        return $remaining;
    }

    public function remainingForSession(Session $session, bool $forAllocation = false): ?int
    {
        // Tenant transactions do not imply allocation. Only write actions request locks.
        if ($forAllocation) {
            $this->requireTransaction();
            Group::query()->whereKey($session->group_id)->lockForUpdate()->firstOrFail();
            $session = Session::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
        }

        $capacity = $session->capacity ?? $session->group->capacity_max;
        if ($capacity === null) {
            return null;
        }

        return max(0, $capacity - $this->attending($session, $forAllocation));
    }

    /**
     * Places in the group held on a date by full-period enrollments: not cancelled and not ended
     * before the date, whatever their start (a future transfer holds its place already, a scheduled
     * end holds it until the last day). docs/product/staff-enrollment.md, т. 9.1.
     */
    public function placesTaken(Group $group, string $date): int
    {
        return Enrollment::query()->where('group_id', $group->id)
            ->where('scope', EnrollmentScope::FullPeriod)
            ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Paused, EnrollmentStatus::Ended])
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date))
            ->count();
    }

    /** True when more children attend the session than it has places (e.g. after a pause ends early). */
    public function overbooked(Session $session): bool
    {
        $capacity = $session->capacity ?? $session->group->capacity_max;

        return $capacity !== null && $this->attending($session, false) > $capacity;
    }

    private function attending(Session $session, bool $forAllocation): int
    {
        $query = Enrollment::query()->where('group_id', $session->group_id)
            ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Paused, EnrollmentStatus::Ended])
            ->with(['group', 'sessionExceptions', 'pauses']);
        if ($forAllocation) {
            $query->orderBy('id')->lockForUpdate();
        }

        return $query->get()
            ->filter(fn (Enrollment $enrollment): bool => $this->applicability->appliesTo($enrollment, $session))
            ->count();
    }

    public function remainingForOffer(VisitOffer $offer, Session $session, bool $forAllocation = false): int
    {
        $total = $this->remainingForSession($session, $forAllocation);
        $applications = $offer->applications()->where('session_id', $session->id);
        if ($forAllocation) {
            $applications->orderBy('id')->lockForUpdate();
        }
        $applicationIds = $applications->pluck('id');
        $enrollments = Enrollment::query()->whereIn('application_id', $applicationIds)
            ->where('session_id', $session->id)
            ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Paused, EnrollmentStatus::Ended]);
        if ($forAllocation) {
            $enrollments->orderBy('id')->lockForUpdate();
        }
        $offerRemaining = max(0, $offer->visit_limit - count($enrollments->get()->all()));

        return $total === null ? $offerRemaining : min($total, $offerRemaining);
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Allocation capacity checks require a transaction.');
        }
    }
}
