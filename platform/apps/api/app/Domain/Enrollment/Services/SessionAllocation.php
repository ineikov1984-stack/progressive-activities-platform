<?php

namespace App\Domain\Enrollment\Services;

use App\Domain\Catalog\Models\Session;
use App\Domain\Enrollment\Enums\EnrollmentApplicationStatus;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Child;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use Illuminate\Validation\ValidationException;

final readonly class SessionAllocation
{
    public function __construct(private SessionApplicability $applicability) {}

    /** Called with the child and group locked, before submission or approval. */
    public function assertAvailable(Child $child, Session $session, ?string $exceptApplicationId = null): void
    {
        $pending = EnrollmentApplication::query()->where('organization_id', $session->organization_id)
            ->where('child_id', $child->id)->where('session_id', $session->id)
            ->whereIn('status', [EnrollmentApplicationStatus::Submitted, EnrollmentApplicationStatus::Waitlisted])
            ->when($exceptApplicationId !== null, fn ($query) => $query->whereKeyNot($exceptApplicationId))->exists();
        // Withdrawn/rejected applications and timely-cancelled enrollments permit a fresh key.
        // Ended visits (including late cancellations) still consume their accepted allocation.
        $allocated = Enrollment::query()->where('organization_id', $session->organization_id)
            ->where('child_id', $child->id)->where('group_id', $session->group_id)
            ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Paused, EnrollmentStatus::Ended])
            ->with(['group', 'sessionExceptions', 'pauses'])
            ->get()->contains(fn (Enrollment $enrollment): bool => $this->applicability->appliesTo($enrollment, $session));
        if ($pending || $allocated) {
            throw ValidationException::withMessages(['session_id' => 'Детето вече има заявка или потвърдено място за това занимание.']);
        }
    }
}
