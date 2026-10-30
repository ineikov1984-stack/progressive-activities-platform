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
            // The platform operator does not see parents' contacts (docs/product/enrollment-contacts-and-emails.md, т. 3).
            self::PlatformAdmin => ['create', 'update', 'invite', 'finance', 'view', 'catalog.view', 'catalog.manage', 'catalog.publish', 'enrollment.view', 'enrollment.manage'],
            // Payment terms are changed only by the owner and the administrator (docs/product/payment-policies.md, т. 7.1).
            // The team: who is in it for most roles; phones, e-mails and changes for the owner and the administrator
            // (docs/product/team.md, т. 4).
            self::OrganizationOwner => ['create', 'update', 'invite', 'finance', 'view', 'catalog.view', 'catalog.manage', 'catalog.publish', 'enrollment.view', 'enrollment.manage', 'enrollment.contacts', 'terms.manage', 'team.view', 'team.manage'],
            self::OrganizationAdmin => ['create', 'update', 'invite', 'view', 'catalog.view', 'catalog.manage', 'catalog.publish', 'enrollment.view', 'enrollment.manage', 'enrollment.contacts', 'terms.manage', 'team.view', 'team.manage'],
            self::Coordinator => ['create', 'update', 'view', 'catalog.view', 'catalog.manage', 'enrollment.view', 'enrollment.manage', 'enrollment.contacts', 'team.view'],
            // Instructors will see only their own groups (docs/product/staff-enrollment.md, Б2).
            self::Instructor => ['view', 'catalog.view'],
            self::Viewer => ['view', 'catalog.view', 'enrollment.view', 'team.view'],
            self::Finance => ['finance', 'view', 'enrollment.view', 'team.view'],
        };
    }
}
