<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\User;

class AcceptInvitation
{
    public function execute(User $user): User
    {
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return $user->refresh();
    }
}
