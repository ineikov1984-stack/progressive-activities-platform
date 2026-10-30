<?php

namespace App\Domain\Enrollment\Services;

use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Models\VisitOffer;
use App\Domain\Enrollment\Enums\EnrollmentScope;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Enrollment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
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

    /**
     * Places for a child moving into the group, for each of its next session days (read-only, no
     * locks). The same rule as remainingForGroup() with the move starting on that day — places held
     * on the day and the fullest session from then on — but from one query for the sessions and one
     * for the enrollments, so the team sees at a glance from when a move is possible.
     *
     * @return list<array{date: string, starts_at: string, remaining: int|null}>
     */
    public function moveInDays(Group $group, int $limit = 8): array
    {
        $timezone = $group->schedule_pattern['timezone'] ?? 'Europe/Sofia';
        $now = CarbonImmutable::now();
        // From the start of today: remainingForGroup() also counts a session earlier today.
        $sessions = Session::query()->where('group_id', $group->id)->where('status', 'scheduled')
            ->where('starts_at', '>', $now->setTimezone($timezone)->startOfDay()->utc())
            ->orderBy('starts_at')->orderBy('id')->get()->values();
        if ($sessions->isEmpty()) {
            return [];
        }
        $enrollments = Enrollment::query()->where('group_id', $group->id)
            ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Paused, EnrollmentStatus::Ended])
            ->with(['group', 'sessionExceptions', 'pauses'])->get();

        // The fullest session from each position to the end.
        $fullestFrom = [];
        $fullest = null;
        for ($index = $sessions->count() - 1; $index >= 0; $index--) {
            $session = $sessions[$index];
            $capacity = $session->capacity ?? $group->capacity_max;
            if ($capacity !== null) {
                $attending = $enrollments->filter(fn (Enrollment $enrollment): bool => $this->applicability->appliesTo($enrollment, $session))->count();
                $left = max(0, $capacity - $attending);
                $fullest = $fullest === null ? $left : min($fullest, $left);
            }
            $fullestFrom[$index] = $fullest;
        }

        $days = [];
        foreach ($sessions as $session) {
            if (count($days) >= $limit) {
                break;
            }
            if ($session->starts_at->lte($now)) {
                continue;
            }
            $date = $session->starts_at->setTimezone($timezone)->toDateString();
            if (isset($days[$date])) {
                continue;
            }
            $dayStart = CarbonImmutable::parse($date, $timezone);
            $first = $sessions->search(fn (Session $candidate): bool => $candidate->starts_at->gt($dayStart));
            $held = $enrollments->filter(fn (Enrollment $enrollment): bool => $enrollment->scope === EnrollmentScope::FullPeriod
                && ($enrollment->ends_on === null || $enrollment->ends_on->toDateString() >= $date))->count();
            $remaining = $group->capacity_max === null ? null : max(0, $group->capacity_max - $held);
            $fromSessions = $first === false ? null : $fullestFrom[$first];
            if ($fromSessions !== null) {
                $remaining = $remaining === null ? $fromSessions : min($remaining, $fromSessions);
            }
            $days[$date] = ['date' => $date, 'starts_at' => $session->starts_at->toIso8601String(), 'remaining' => $remaining];
        }

        return array_values($days);
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

    /**
     * Places for a trial or a single visit booked from a price (docs/product/visits.md, т. 5.3): the free
     * places of the session, and no more than the group's places for such visits. Visits from an offer
     * have their own limit and are not counted here. Null — no limit.
     */
    public function remainingForVisit(Session $session, bool $forAllocation = false): ?int
    {
        $free = $this->remainingForSession($session, $forAllocation);
        $limit = Group::query()->whereKey($session->group_id)->value('visit_places');
        if ($limit === null) {
            return $free;
        }
        $visits = $this->visitsFromPrices()->where('session_id', $session->id);
        if ($forAllocation) {
            $visits->orderBy('id')->lockForUpdate();
        }
        $left = max(0, (int) $limit - count($visits->get(['id'])->all()));

        return $free === null ? $left : min($free, $left);
    }

    /**
     * The next sessions a parent can book a visit for, with their places — the same rule as
     * remainingForVisit(), from one query for the group's enrollments (read-only, no locks).
     *
     * @param  Collection<int, Session>  $sessions  the group's scheduled sessions, earliest first
     * @return list<array{session: Session, remaining: int|null}>
     */
    public function visitDays(Group $group, Collection $sessions, CarbonImmutable $now, int $limit = 10): array
    {
        $open = $sessions->filter(fn (Session $session): bool => $session->group_id === $group->id
            && $session->status === 'scheduled'
            && $now->lessThan($session->starts_at->subHours(VisitBooking::CLOSES_HOURS_BEFORE)))
            ->take($limit)->values();
        if ($open->isEmpty()) {
            return [];
        }
        $enrollments = Enrollment::query()->where('group_id', $group->id)
            ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Paused, EnrollmentStatus::Ended])
            ->with(['group', 'sessionExceptions', 'pauses'])->get();
        $fromPrices = $this->visitsFromPrices()->where('group_id', $group->id)
            ->whereIn('session_id', $open->pluck('id'))->pluck('session_id')->countBy();

        return $open->map(function (Session $session) use ($group, $enrollments, $fromPrices): array {
            $capacity = $session->capacity ?? $group->capacity_max;
            $free = $capacity === null ? null : max(0, $capacity - $enrollments
                ->filter(fn (Enrollment $enrollment): bool => $this->applicability->appliesTo($enrollment, $session))->count());
            $left = $group->visit_places === null ? null : max(0, $group->visit_places - ($fromPrices[$session->id] ?? 0));
            $remaining = $free === null ? $left : ($left === null ? $free : min($free, $left));

            return ['session' => $session, 'remaining' => $remaining];
        })->all();
    }

    /** @return Builder<Enrollment> trials and single visits booked from a price, holding or having used their place */
    private function visitsFromPrices(): Builder
    {
        return Enrollment::query()->whereIn('scope', [EnrollmentScope::Trial, EnrollmentScope::Single])
            ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Paused, EnrollmentStatus::Ended])
            ->whereHas('application', fn ($query) => $query->whereNull('visit_offer_id'));
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Allocation capacity checks require a transaction.');
        }
    }
}
