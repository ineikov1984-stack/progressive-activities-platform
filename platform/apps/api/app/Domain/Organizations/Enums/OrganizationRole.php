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
            self::PlatformAdmin, self::OrganizationOwner => ['create', 'update', 'invite', 'finance', 'view', 'catalog.view', 'catalog.manage', 'catalog.publish'],
            self::OrganizationAdmin => ['create', 'update', 'invite', 'view', 'catalog.view', 'catalog.manage', 'catalog.publish'],
            self::Coordinator => ['create', 'update', 'view', 'catalog.view', 'catalog.manage'],
            self::Instructor, self::Viewer => ['view', 'catalog.view'],
            self::Finance => ['finance', 'view'],
        };
    }
}
