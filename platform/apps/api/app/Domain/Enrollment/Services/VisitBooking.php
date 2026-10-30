<?php

namespace App\Domain\Enrollment\Services;

use App\Domain\Catalog\Enums\VisitOfferStatus;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\Session;
use App\Domain\Enrollment\Enums\EnrollmentApplicationStatus;
use App\Domain\Enrollment\Enums\EnrollmentScope;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Child;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** The rules for a trial or a single visit booked from a price and a date (docs/product/visits.md, т. 5). */
final class VisitBooking
{
    /** Booking closes this long before the session starts (т. 2.6). */
    public const CLOSES_HOURS_BEFORE = 2;

    /** Booking opens this far ahead, so one far-off visit does not hold the group for months (т. 2.6). */
    public const OPENS_DAYS_BEFORE = 60;

    public static function isOpen(Session $session, CarbonImmutable $now): bool
    {
        return $session->status === 'scheduled'
            && $now->lessThan($session->starts_at->subHours(self::CLOSES_HOURS_BEFORE))
            && $session->starts_at->lessThanOrEqualTo($now->addDays(self::OPENS_DAYS_BEFORE));
    }

    /**
     * One trial of an activity for a child in a school year (1 September – 31 August by the session's day in the
     * group's time zone), by any route: a held or used place, or an application still waiting for a date that has
     * not started (т. 2.3). A trial cancelled in time, a rejected or a withdrawn application, a trial whose
     * session the organization cancelled, and one waiting on an offer that is closed or cancelled (it can no longer
     * be approved, т. 8a.5) leave the trial free.
     */
    public function assertTrialOnce(Child $child, Group $group, Session $session, ?string $exceptApplicationId = null): void
    {
        $timezone = is_string($group->schedule_pattern['timezone'] ?? null) && $group->schedule_pattern['timezone'] !== ''
            ? $group->schedule_pattern['timezone'] : 'Europe/Sofia';
        $day = $session->starts_at->setTimezone($timezone);
        $from = CarbonImmutable::create($day->month >= 9 ? $day->year : $day->year - 1, 9, 1, 0, 0, 0, $timezone);
        $until = $from->addYear();
        $activityId = $group->offering()->value('activity_id');
        $sameActivity = fn ($query) => $query->whereHas('group.offering', fn ($offering) => $offering->where('activity_id', $activityId));

        $used = Enrollment::query()->where('child_id', $child->id)->where('scope', EnrollmentScope::Trial)
            ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Paused, EnrollmentStatus::Ended])
            ->whereDate('starts_on', '>=', $from->toDateString())->whereDate('starts_on', '<', $until->toDateString())
            ->whereHas('session', fn ($query) => $query->where('status', 'scheduled'))
            ->where($sameActivity)->exists()
            || EnrollmentApplication::query()->where('child_id', $child->id)->where('scope', EnrollmentScope::Trial)
                ->whereIn('status', [EnrollmentApplicationStatus::Submitted, EnrollmentApplicationStatus::Waitlisted])
                ->when($exceptApplicationId !== null, fn ($query) => $query->whereKeyNot($exceptApplicationId))
                ->where(fn ($query) => $query->whereNull('visit_offer_id')
                    ->orWhereHas('visitOffer', fn ($offer) => $offer->where('status', VisitOfferStatus::Published)))
                // A forgotten application stops counting once its date has started.
                ->whereHas('session', fn ($query) => $query->where('status', 'scheduled')->where('starts_at', '>', now())
                    ->where('starts_at', '>=', $from->utc())->where('starts_at', '<', $until->utc()))
                ->where($sameActivity)->exists();
        if ($used) {
            throw ValidationException::withMessages(['trial_limit' => 'Детето вече има пробно занимание или заявка за пробно занимание по тази дейност през тази учебна година.']);
        }
    }
}
