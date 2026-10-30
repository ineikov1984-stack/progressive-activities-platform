<?php

namespace App\Domain\Enrollment\Services;

use App\Domain\Enrollment\Enums\EnrollmentApplicationStatus;
use App\Domain\Enrollment\Enums\EnrollmentScope;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Enrollment\Models\EnrollmentTransition;
use Carbon\CarbonImmutable;

/**
 * While the organization may see a parent's contacts (docs/product/enrollment-contacts-and-emails.md, т. 3.1):
 * an open application, an active or paused enrollment for the period, or one whose last day was up to
 * 90 days ago. A trial or one-off visit ends with its session, whatever its status says. A cancelled
 * enrollment — up to 90 days after it was cancelled, so the team can ask why (owner's decision of
 * 30.10.2026). A rejected or withdrawn application, and anything without a known day — never.
 */
final class ContactWindow
{
    public const DAYS_AFTER_END = 90;

    public function application(EnrollmentApplication $application): bool
    {
        if (in_array($application->status, [EnrollmentApplicationStatus::Submitted, EnrollmentApplicationStatus::Waitlisted], true)) {
            return true;
        }
        $enrollment = $application->status === EnrollmentApplicationStatus::Approved ? $application->enrollment : null;

        return $enrollment !== null && $this->enrollment($enrollment);
    }

    public function enrollment(Enrollment $enrollment): bool
    {
        if ($enrollment->status === EnrollmentStatus::Cancelled) {
            $day = $enrollment->scope === EnrollmentScope::FullPeriod ? self::cancelledOn($enrollment) : self::lastDay($enrollment);

            return $day !== null && $day >= self::oldestEnd();
        }
        if ($enrollment->scope === EnrollmentScope::FullPeriod
            && in_array($enrollment->status, [EnrollmentStatus::Active, EnrollmentStatus::Paused], true)) {
            return true;
        }
        $lastDay = self::lastDay($enrollment);

        return $lastDay !== null && $lastDay >= self::oldestEnd();
    }

    /** The last day of an enrollment as a calendar day where the group is: its end date, or a visit's session day. */
    public static function lastDay(Enrollment $enrollment): ?string
    {
        if ($enrollment->ends_on !== null) {
            return $enrollment->ends_on->toDateString();
        }
        if (in_array($enrollment->scope, [EnrollmentScope::Trial, EnrollmentScope::Single], true)) {
            return $enrollment->session?->starts_at->setTimezone(SessionApplicability::timezone($enrollment))->toDateString();
        }

        return null;
    }

    /** The day the enrollment was cancelled (its last step to "cancelled"), as a calendar day in Sofia. */
    public static function cancelledOn(Enrollment $enrollment): ?string
    {
        $at = EnrollmentTransition::query()->where('enrollment_id', $enrollment->id)
            ->where('to_status', EnrollmentStatus::Cancelled->value)
            // Only the step into "cancelled": a later step on a cancelled enrollment never restarts the 90 days.
            ->where('from_status', '!=', EnrollmentStatus::Cancelled->value)
            ->latest('created_at')->first(['created_at'])?->created_at;

        return $at === null ? null : CarbonImmutable::instance($at)->setTimezone('Europe/Sofia')->toDateString();
    }

    /** The earliest last day still in the window, as a calendar day in Sofia. */
    public static function oldestEnd(): string
    {
        return CarbonImmutable::now('Europe/Sofia')->subDays(self::DAYS_AFTER_END)->toDateString();
    }
}
