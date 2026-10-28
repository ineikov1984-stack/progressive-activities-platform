<?php

namespace App\Domain\Enrollment\Services;

use App\Domain\Catalog\Models\Session;
use App\Domain\Enrollment\Enums\EnrollmentScope;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Enrollment;
use Illuminate\Database\Eloquent\Collection;

final class SessionApplicability
{
    /** @return Collection<int, Session> */
    public function sessionsFor(Enrollment $enrollment): Collection
    {
        if ($enrollment->status === EnrollmentStatus::Cancelled) {
            return new Collection;
        }

        if (in_array($enrollment->scope, [EnrollmentScope::Trial, EnrollmentScope::Single], true)) {
            return Session::query()->whereKey($enrollment->session_id)
                ->where('group_id', $enrollment->group_id)
                ->where('status', 'scheduled')->get();
        }

        $excludedSessionIds = $enrollment->sessionExceptions()
            ->where('kind', 'excluded')->pluck('session_id');

        return Session::query()->where('group_id', $enrollment->group_id)
            ->where('status', 'scheduled')
            ->whereDate('starts_at', '>=', $enrollment->starts_on)
            ->when($enrollment->ends_on !== null, fn ($query) => $query
                ->whereDate('starts_at', '<=', $enrollment->ends_on))
            ->when($excludedSessionIds->isNotEmpty(), fn ($query) => $query
                ->whereNotIn('id', $excludedSessionIds))
            ->orderBy('starts_at')->get();
    }
}
