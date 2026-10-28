<?php

namespace App\Domain\Enrollment\Enums;

enum EnrollmentSource: string
{
    case OrganizationCatalog = 'organization_catalog';
    case DirectLink = 'direct_link';
    case Marketplace = 'marketplace';
    case Staff = 'staff';
}
