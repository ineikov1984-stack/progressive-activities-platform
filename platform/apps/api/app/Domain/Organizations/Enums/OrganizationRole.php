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

    /** The role in words, as the team and the invitation say it (docs/product/team.md, т. 4). */
    public function label(): string
    {
        return match ($this) {
            self::PlatformAdmin => 'Оператор на платформата',
            self::OrganizationOwner => 'Собственик',
            self::OrganizationAdmin => 'Администратор',
            self::Coordinator => 'Координатор',
            self::Instructor => 'Преподавател',
            self::Finance => 'Счетоводител',
            self::Viewer => 'Само преглед',
        };
    }

    /** Every role but the platform operator, who is not a person of the team (docs/product/team-rights.md, т. 3). */
    public function isTeam(): bool
    {
        return $this !== self::PlatformAdmin;
    }

    /** The scope when the person has none chosen (docs/product/team-rights.md, т. 3, 4.1). */
    public function defaultScope(): StaffScope
    {
        return $this === self::Instructor ? StaffScope::Own : StaffScope::All;
    }

    /**
     * The scopes the role may have: the owner, the administrator, the coordinator (settings) and the accountant
     * (finance) are locked on the whole organization.
     *
     * @return list<StaffScope>
     */
    public function allowedScopes(): array
    {
        return match ($this) {
            self::Instructor => [StaffScope::Own, StaffScope::Groups, StaffScope::All],
            self::Viewer => [StaffScope::All, StaffScope::Groups],
            default => [StaffScope::All],
        };
    }

    /**
     * The ready set of rights of the role (docs/product/team-rights.md, т. 2, 3). The old keys stay as they were —
     * "enrollment.manage" and "enrollment.contacts" are synonyms of "enrollment.enroll" and "contacts.view" until the web
     * moves to the new ones; "create", "update", "invite" and "finance" go in a PR of their own.
     *
     * @return list<string>
     */
    public function abilities(): array
    {
        return match ($this) {
            // The platform operator keeps its set with the new names of the rights that were split; no contacts
            // (docs/product/enrollment-contacts-and-emails.md, т. 3) and no team page.
            self::PlatformAdmin => [
                'create', 'update', 'invite', 'finance', 'view', 'catalog.view', 'catalog.manage', 'catalog.publish',
                'enrollment.view', 'enrollment.manage',
                'enrollment.enroll', 'team.assign', 'schedule.manage', 'terms.view',
            ],
            // Payment terms are changed only by the owner and the administrator (docs/product/payment-policies.md, т. 7.1);
            // read by them, the coordinator and the accountant — and the platform operator, who edits activities
            // (DECISIONS 31.10.2026 „Учителят — само своите групи“).
            // The team: who is in it for most roles; phones, e-mails and changes for the owner and the administrator
            // (docs/product/team.md, т. 4).
            self::OrganizationOwner => [
                'create', 'update', 'invite', 'finance', 'view', 'catalog.view', 'catalog.manage', 'catalog.publish',
                'enrollment.view', 'enrollment.manage', 'enrollment.contacts', 'terms.manage', 'team.view', 'team.manage',
                'enrollment.enroll', 'attendance.mark', 'attendance.correct_late', 'attendance.view_month', 'schedule.manage',
                'contacts.view', 'contacts.message', 'team.assign', 'finance.view', 'finance.collect', 'finance.pay_view', 'terms.view',
            ],
            self::OrganizationAdmin => [
                'create', 'update', 'invite', 'view', 'catalog.view', 'catalog.manage', 'catalog.publish',
                'enrollment.view', 'enrollment.manage', 'enrollment.contacts', 'terms.manage', 'team.view', 'team.manage',
                'enrollment.enroll', 'attendance.mark', 'attendance.correct_late', 'attendance.view_month', 'schedule.manage',
                'contacts.view', 'contacts.message', 'team.assign', 'terms.view',
            ],
            self::Coordinator => [
                'create', 'update', 'view', 'catalog.view', 'catalog.manage',
                'enrollment.view', 'enrollment.manage', 'enrollment.contacts', 'team.view',
                'enrollment.enroll', 'attendance.mark', 'attendance.view_month', 'schedule.manage',
                'contacts.view', 'contacts.message', 'team.assign', 'terms.view',
            ],
            // Only their own groups and, in them, only what the teacher needs about each child — never applications,
            // contacts or changes (docs/product/team.md, т. 8). "enrollment.view_own" does not give "enrollment.view";
            // marking attendance comes from the assignment, not from the role (docs/product/team-rights.md, т. 8).
            // No activities, open days or payment terms either: the teacher's menu is "Начало" and their own groups
            // (DECISIONS 31.10.2026); the catalog comes only with an added right that needs it (TeamAbilities::IMPLIES).
            self::Instructor => ['view', 'enrollment.view_own'],
            self::Viewer => ['view', 'catalog.view', 'enrollment.view', 'team.view', 'attendance.view_month'],
            // The accountant still has no catalog (т. 2.1), but reads the payment terms.
            self::Finance => [
                'finance', 'view', 'enrollment.view', 'team.view',
                'attendance.view_month', 'finance.view', 'finance.collect', 'finance.pay_view', 'terms.view',
            ],
        };
    }
}
