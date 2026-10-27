<?php

namespace App\Domain\Organizations\Tenancy;

use App\Domain\Identity\Models\User;
use LogicException;

final class OperatorContext
{
    private ?string $userId = null;

    public function activate(User|string $user): void
    {
        $this->userId = $user instanceof User ? $user->getKey() : $user;
    }

    public function isActive(): bool
    {
        return $this->userId !== null;
    }

    public function userId(): string
    {
        return $this->userId ?? throw new LogicException('No platform operator is active for this request.');
    }

    public function clear(): void
    {
        $this->userId = null;
    }
}
