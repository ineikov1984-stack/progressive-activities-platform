<?php

namespace App\Domain\Organizations\Enums;

enum OrganizationStatus: string
{
    case Applicant = 'applicant';
    case Review = 'review';
    case Approved = 'approved';
    case Active = 'active';
    case Restricted = 'restricted';
    case Suspended = 'suspended';

    public function accessMode(): OrganizationAccessMode
    {
        return match ($this) {
            self::Applicant, self::Review, self::Approved, self::Suspended => OrganizationAccessMode::Onboarding,
            self::Restricted => OrganizationAccessMode::OperationalRead,
            self::Active => OrganizationAccessMode::OperationalWrite,
        };
    }

    public function allowsAccess(OrganizationAccessMode $required): bool
    {
        return $this->accessMode()->level() >= $required->level();
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Applicant => in_array($next, [self::Review, self::Suspended], true),
            self::Review => in_array($next, [self::Applicant, self::Approved, self::Suspended], true),
            self::Approved => in_array($next, [self::Active, self::Suspended], true),
            self::Active => in_array($next, [self::Restricted, self::Suspended], true),
            self::Restricted => in_array($next, [self::Active, self::Suspended], true),
            self::Suspended => $next === self::Review,
        };
    }
}
