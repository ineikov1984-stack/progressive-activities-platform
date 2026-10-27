<?php

namespace App\Domain\Organizations\Enums;

enum OrganizationAccessMode: string
{
    case Onboarding = 'onboarding';
    case OperationalRead = 'operational.read';
    case OperationalWrite = 'operational.write';

    public function level(): int
    {
        return match ($this) {
            self::Onboarding => 0,
            self::OperationalRead => 1,
            self::OperationalWrite => 2,
        };
    }
}
