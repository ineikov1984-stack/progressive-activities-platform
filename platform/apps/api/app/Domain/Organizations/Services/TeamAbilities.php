<?php

namespace App\Domain\Organizations\Services;

use App\Domain\Organizations\Enums\AbilityKind;

/**
 * The rights in a team and how each meets the scope (docs/product/team-rights.md, т. 2). The keys are stable and never
 * shown on a screen. A role is a ready set of them (`OrganizationRole::abilities()`); a person may get more of the
 * grantable ones (`staff_grants`).
 */
final class TeamAbilities
{
    /** Exactly the list in the CHECK of `staff_grants.ability`. */
    public const GRANTABLE = [
        'enrollment.enroll', 'attendance.mark', 'attendance.view_month', 'schedule.view_all', 'schedule.manage',
        'contacts.view', 'contacts.message', 'team.view', 'team.assign', 'finance.view', 'finance.collect',
        'finance.pay_view', 'catalog.manage', 'catalog.publish',
    ];

    /** Given only with a role, never added. */
    public const ROLE_ONLY = ['attendance.correct_late', 'team.manage', 'terms.manage'];

    /** Hold in the person's own groups whatever the role: from the assignment, the substitution, the lead of the offering (т. 8). */
    public const TEACHING = ['enrollment.view_own', 'attendance.mark'];

    /** Given only by the owner, with an explicit confirmation, and only once the stage of finance is on (т. 5). */
    public const FINANCE = ['finance.view', 'finance.collect', 'finance.pay_view'];

    /** Given together, in the same scope, so that a right is never given that cannot be used (т. 2). */
    public const IMPLIES = [
        'enrollment.enroll' => 'enrollment.view',
        'team.assign' => 'team.view',
        'catalog.publish' => 'catalog.manage',
    ];

    /** The keys from before, kept in the Gate and in `GET /organizations` until the web moves to the new ones (т. 2.1). */
    public const SYNONYMS = [
        'enrollment.manage' => 'enrollment.enroll',
        'enrollment.contacts' => 'contacts.view',
    ];

    /** "Партньор от училище": a viewer with selected groups and these two rights (т. 7). */
    public const SCHOOL_PARTNER = ['enrollment.enroll', 'attendance.mark'];

    /** Hold with every scope: the base of every role, the old keys and the whole schedule for reading. */
    private const ORGANIZATION = [
        'view', 'catalog.view', 'create', 'update', 'invite', 'finance', 'enrollment.view_own', 'schedule.view_all',
    ];

    private const WHOLE_ORGANIZATION = [
        'schedule.manage', 'team.assign', 'team.manage', 'terms.manage', 'catalog.manage', 'catalog.publish',
        'finance.view', 'finance.collect', 'finance.pay_view',
    ];

    public static function canonical(string $ability): string
    {
        return self::SYNONYMS[$ability] ?? $ability;
    }

    /** Any other right holds in the groups of the scope: a right nobody listed is refused across the organization. */
    public static function kind(string $ability): AbilityKind
    {
        $ability = self::canonical($ability);

        return match (true) {
            in_array($ability, self::ORGANIZATION, true) => AbilityKind::Organization,
            in_array($ability, self::WHOLE_ORGANIZATION, true) => AbilityKind::WholeOrganization,
            default => AbilityKind::Group,
        };
    }

    public static function isGrantable(string $ability): bool
    {
        return in_array($ability, self::GRANTABLE, true);
    }

    public static function isTeaching(string $ability): bool
    {
        return in_array(self::canonical($ability), self::TEACHING, true);
    }

    /**
     * "enrollment.view_own" of the role says only that the person may see their own groups; which groups is the fact of
     * teaching them, never the scope.
     */
    public static function isOwnGroupsOnly(string $ability): bool
    {
        return self::canonical($ability) === 'enrollment.view_own';
    }

    public static function isFinance(string $ability): bool
    {
        return in_array($ability, self::FINANCE, true);
    }

    /** Finance is given only after its stage ("Счетоводна основа"); until then nobody adds it. */
    public static function financeEnabled(): bool
    {
        return config('rights.finance_enabled', false) === true;
    }

    /**
     * The rights with what they bring along.
     *
     * @param  list<string>  $abilities
     * @return list<string>
     */
    public static function withImplied(array $abilities): array
    {
        $result = $abilities;
        foreach ($abilities as $ability) {
            if (isset(self::IMPLIES[$ability])) {
                $result[] = self::IMPLIES[$ability];
            }
        }

        return array_values(array_unique($result));
    }
}
