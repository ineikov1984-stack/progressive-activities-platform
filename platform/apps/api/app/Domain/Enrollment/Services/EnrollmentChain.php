<?php

namespace App\Domain\Enrollment\Services;

use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Enrollment;
use Illuminate\Validation\ValidationException;

/**
 * Where a child went after a move. One enrollment can have several moves out of it — cancelled ones and at
 * most one that is not — and a cancelled move can itself have been moved on (A → B cancelled → C). The
 * answer is the first enrollment along those moves that is not cancelled, whichever branch it is on.
 */
final class EnrollmentChain
{
    private const MAX_STEPS = 50;

    public function next(Enrollment $enrollment): ?Enrollment
    {
        $frontier = [$enrollment->id];
        for ($step = 0; $step < self::MAX_STEPS && $frontier !== []; $step++) {
            $successors = Enrollment::query()->with('group')->whereIn('transferred_from_id', $frontier)->orderBy('created_at')->get();
            $live = $successors->first(fn (Enrollment $next): bool => $next->status !== EnrollmentStatus::Cancelled);
            if ($live !== null) {
                return $live;
            }
            $frontier = $successors->modelKeys();
        }
        if ($frontier === []) {
            return null;
        }

        throw ValidationException::withMessages(['enrollment' => 'Историята на преместванията е твърде дълга. Обърнете се към поддръжката.']);
    }
}
