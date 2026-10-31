<?php

namespace Tests\Unit\Organizations;

use App\Domain\Organizations\Enums\AbilityKind;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Enums\StaffScope;
use App\Domain\Organizations\Services\TeamAbilities;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Rights in the team stay in one place (docs/product/team-rights.md, т. 4.5, 11): a list of roles written by hand only
 * where a role is not a right; Organizations reads the catalog only to stop the archive of a person who still teaches;
 * the rights of the roles, the database's list and the old keys agree.
 */
class TeamRightsArchitectureTest extends TestCase
{
    /** Where a role is not a right: who gives which role, the archive's protected roles, e-mail recipients, the operator. */
    private const ROLE_LISTS = [
        'app/Domain/Organizations/Services/TeamRoles.php',
        'app/Domain/Organizations/Actions/ArchiveStaffMember.php',
        'app/Domain/Enrollment/Delivery/DeliveryRecipients.php',
        'app/Http/Middleware/ResolvePlatformOperator.php',
    ];

    private const MIGRATION = 'database/migrations/2026_10_31_114900_create_staff_grants_and_scope_groups.php';

    public function test_lists_of_roles_are_written_by_hand_only_where_a_role_is_not_a_right(): void
    {
        $found = [];
        foreach ($this->files('app') as $path => $contents) {
            if (preg_match("/(?:where|whereIn|orWhere|orWhereIn)\\(\\s*'role'/", $contents) === 1 && ! in_array($path, self::ROLE_LISTS, true)) {
                $found[] = $path;
            }
        }

        $this->assertSame([], $found, 'Ask StaffRights or AccessScope instead of a list of roles.');
    }

    public function test_in_organizations_only_the_archive_uses_the_catalog(): void
    {
        $found = [];
        foreach ($this->files('app/Domain/Organizations') as $path => $contents) {
            if (str_contains($contents, 'App\\Domain\\Catalog') && $path !== 'app/Domain/Organizations/Actions/ArchiveStaffMember.php') {
                $found[] = $path;
            }
        }

        $this->assertSame([], $found, 'Rights go one way: Catalog reads Organizations, never back.');
    }

    public function test_the_grantable_rights_are_the_databases_list(): void
    {
        $migration = (string) file_get_contents($this->root().'/'.self::MIGRATION);
        $this->assertSame(1, preg_match('/private const GRANTABLE = \[(.*?)\];/s', $migration, $match));
        preg_match_all("/'([a-z_.]+)'/", $match[1], $keys);

        $this->assertEqualsCanonicalizing(TeamAbilities::GRANTABLE, $keys[1]);
        $this->assertSame([], array_intersect(TeamAbilities::GRANTABLE, TeamAbilities::ROLE_ONLY));
        foreach (TeamAbilities::FINANCE as $ability) {
            $this->assertSame(AbilityKind::WholeOrganization, TeamAbilities::kind($ability));
        }
    }

    public function test_the_roles_keep_the_old_keys_with_the_new_ones_and_their_scope_fits_their_rights(): void
    {
        foreach (OrganizationRole::cases() as $role) {
            $abilities = $role->abilities();
            if (in_array('enrollment.manage', $abilities, true)) {
                $this->assertContains('enrollment.enroll', $abilities, $role->value);
            }
            if (in_array('enrollment.contacts', $abilities, true)) {
                $this->assertContains('contacts.view', $abilities, $role->value);
                $this->assertContains('contacts.message', $abilities, $role->value);
            }
            // "catalog.manage" was split: the settings, the assignments and the schedule.
            if (in_array('catalog.manage', $abilities, true)) {
                $this->assertContains('team.assign', $abilities, $role->value);
                $this->assertContains('schedule.manage', $abilities, $role->value);
            }
            // A role that may be narrower than the whole organization has no right for the whole organization only.
            if ($role->allowedScopes() !== [StaffScope::All]) {
                foreach ($abilities as $ability) {
                    $this->assertNotSame(AbilityKind::WholeOrganization, TeamAbilities::kind($ability), "{$role->value}: {$ability}");
                }
            }
            $this->assertContains($role->defaultScope(), $role->allowedScopes(), $role->value);
            // What a right brings along is in the role too, except the operator, who does not see the team.
            if ($role->isTeam()) {
                foreach (TeamAbilities::IMPLIES as $right => $implied) {
                    if (in_array($right, $abilities, true)) {
                        $this->assertContains($implied, $abilities, "{$role->value}: {$right}");
                    }
                }
            }
        }
        $this->assertFalse(OrganizationRole::Instructor->allows('attendance.mark'), 'A teacher marks attendance from the assignment, not the role.');
    }

    /** @return iterable<string, string> path from the root of the API => contents */
    private function files(string $directory): iterable
    {
        $root = $this->root();
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            /** @var SplFileInfo $file */
            if ($file->getExtension() === 'php') {
                yield substr($file->getPathname(), strlen($root) + 1) => (string) file_get_contents($file->getPathname());
            }
        }
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
