<?php

namespace App\Domain\Enrollment\Services;

use App\Domain\Enrollment\Enums\EnrollmentApplicationStatus;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use Carbon\CarbonImmutable;

/**
 * While the organization may see a parent's contacts (docs/product/enrollment-contacts-and-emails.md, т. 3.1):
 * an open application, an active or paused enrollment, or one that ended up to 90 days ago.
 * A cancelled enrollment and a rejected or withdrawn application — never.
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
        return match ($enrollment->status) {
            EnrollmentStatus::Active, EnrollmentStatus::Paused => true,
            EnrollmentStatus::Ended => $enrollment->ends_on === null
                || $enrollment->ends_on->toDateString() >= self::oldestEnd(),
            EnrollmentStatus::Cancelled => false,
        };
    }

    /** The earliest last day still in the window, as a calendar day in Sofia. */
    public static function oldestEnd(): string
    {
        return CarbonImmutable::now('Europe/Sofia')->subDays(self::DAYS_AFTER_END)->toDateString();
    }
}
