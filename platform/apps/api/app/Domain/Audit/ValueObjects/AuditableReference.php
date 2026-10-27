<?php

namespace App\Domain\Audit\ValueObjects;

use InvalidArgumentException;

final readonly class AuditableReference
{
    public function __construct(public string $type, public string $id)
    {
        if ($type === '' || $id === '') {
            throw new InvalidArgumentException('An auditable subject requires both type and id.');
        }
    }
}
