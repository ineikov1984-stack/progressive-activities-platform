<?php

namespace App\Domain\Enrollment\Policies;

use App\Domain\Enrollment\Models\Child;
use App\Domain\Identity\Models\User;

final class EnrollmentPolicy
{
    public function viewChild(User $user, Child $child): bool
    {
        return $this->owns($user, $child);
    }

    public function manageChild(User $user, Child $child): bool
    {
        return $this->owns($user, $child);
    }

    private function owns(User $user, Child $child): bool
    {
        return (string) $child->owner_user_id === (string) $user->getAuthIdentifier();
    }
}
