<?php

namespace App\Domain\Organizations\Enums;

enum OrganizationRole: string
{
    case PlatformAdmin = 'platform_admin';
    case OrganizationOwner = 'organization_owner';
    case OrganizationAdmin = 'organization_admin';
    case Coordinator = 'coordinator';
    case Instructor = 'instructor';
    case Finance = 'finance';
    case Viewer = 'viewer';

    public function allows(string $ability): bool
    {
        return in_array($ability, $this->abilities(), true);
    }

    /** @return list<string> */
    public function abilities(): array
    {
        return match ($this) {
            self::PlatformAdmin, self::OrganizationOwner => ['create', 'update', 'invite', 'finance', 'view'],
            self::OrganizationAdmin => ['create', 'update', 'invite', 'view'],
            self::Coordinator => ['create', 'update', 'view'],
            self::Instructor, self::Viewer => ['view'],
            self::Finance => ['finance', 'view'],
        };
    }
}
