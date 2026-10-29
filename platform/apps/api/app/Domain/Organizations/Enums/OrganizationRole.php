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
            self::PlatformAdmin, self::OrganizationOwner => ['create', 'update', 'invite', 'finance', 'view', 'catalog.view', 'catalog.manage', 'catalog.publish', 'enrollment.view', 'enrollment.manage'],
            self::OrganizationAdmin => ['create', 'update', 'invite', 'view', 'catalog.view', 'catalog.manage', 'catalog.publish', 'enrollment.view', 'enrollment.manage'],
            self::Coordinator => ['create', 'update', 'view', 'catalog.view', 'catalog.manage', 'enrollment.view', 'enrollment.manage'],
            // Instructors will see only their own groups (docs/product/staff-enrollment.md, Б2).
            self::Instructor => ['view', 'catalog.view'],
            self::Viewer => ['view', 'catalog.view', 'enrollment.view'],
            self::Finance => ['finance', 'view', 'enrollment.view'],
        };
    }
}
