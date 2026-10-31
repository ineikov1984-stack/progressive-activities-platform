<?php

namespace Tests\Feature\Organizations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Tests\TestCase;

/**
 * The database itself keeps the rights in a team inside their organization and their history whole
 * (docs/product/team-rights.md, т. 4.2, 4.3, 11): another organization's rows are neither read, written nor changed;
 * nothing is deleted; who, what and when never change; a closed row stays closed; an expired right keeps its end and
 * is closed only as expired; who closed a row is kept; a selected group or activity of another organization is refused;
 * a user who acted is not deleted; row level security binds the owner of the tables too.
 */
class StaffRightsSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[TestGroup('tenant-isolation')]
    public function test_row_level_security_rights_triggers_and_keys_of_staff_grants_and_scope_groups(): void
    {
        if (! $this->postgresIsAvailable()) {
            $this->markTestSkipped('PostgreSQL is required for the database-layer checks of rights in the team.');
        }

        Artisan::call('migrate', ['--database' => 'pgsql_admin', '--force' => true]);
        $pdo = DB::connection('pgsql_admin')->getPdo();
        $pdo->beginTransaction();

        try {
            // Row level security is on and binds the owner of the tables too.
            $this->assertSame(['staff_grants', 'staff_scope_groups'], $pdo->query(
                "SELECT relname FROM pg_class WHERE relname IN ('staff_grants', 'staff_scope_groups') AND relkind = 'r' "
                .'AND relrowsecurity AND relforcerowsecurity ORDER BY relname',
            )->fetchAll(\PDO::FETCH_COLUMN));

            $first = $this->organization($pdo);
            $second = $this->organization($pdo);
            $grant = $this->insertGrant($pdo, $first, 'contacts.view', null);
            $expired = $this->insertGrant($pdo, $first, 'team.view', "NOW() - INTERVAL '1 hour'", "NOW() - INTERVAL '2 days'");
            $closed = $this->insertGrant($pdo, $first, 'attendance.mark', null);
            $pdo->exec("UPDATE staff_grants SET revoked_at = NOW(), revoked_by = '{$first['user']}', revoked_reason = 'removed' WHERE id = '{$closed}'");
            $foreignGrant = $this->insertGrant($pdo, $second, 'contacts.view', null);
            $scope = $this->insertScopeGroup($pdo, $first, $first['group'], null);
            $activityScope = $this->insertScopeGroup($pdo, $first, null, $first['activity']);
            $foreignScope = $this->insertScopeGroup($pdo, $second, $second['group'], null);

            // Even the owner role: who, what and when stay; a closed row stays closed; an expired right keeps its end.
            $this->refused($pdo, fn () => $pdo->exec("UPDATE staff_grants SET ability = 'team.assign' WHERE id = '{$grant}'"), '23514');
            $this->refused($pdo, fn () => $pdo->exec("UPDATE staff_grants SET granted_at = NOW() - INTERVAL '1 day' WHERE id = '{$grant}'"), '23514');
            $this->refused($pdo, fn () => $pdo->exec("UPDATE staff_grants SET expires_at = NOW() + INTERVAL '1 day' WHERE id = '{$closed}'"), '23514');
            $this->refused($pdo, fn () => $pdo->exec("UPDATE staff_grants SET expires_at = NOW() + INTERVAL '1 day' WHERE id = '{$expired}'"), '23514');
            $this->refused($pdo, fn () => $pdo->exec("UPDATE staff_scope_groups SET group_id = NULL, activity_id = '{$first['activity']}' WHERE id = '{$scope}'"), '23514');
            // Only listed rights, one open row of a right, a reason with every revocation, one target per selected row.
            $this->refused($pdo, fn () => $this->insertGrant($pdo, $first, 'team.manage', null), '23514');
            $this->refused($pdo, fn () => $this->insertGrant($pdo, $first, 'contacts.view', null), '23505');
            $this->refused($pdo, fn () => $pdo->exec("UPDATE staff_grants SET revoked_at = NOW() WHERE id = '{$grant}'"), '23514');
            // Who revoked or removed is kept; only an expired right is closed without anyone, and only as expired.
            $this->refused($pdo, fn () => $pdo->exec("UPDATE staff_grants SET revoked_at = NOW(), revoked_reason = 'removed' WHERE id = '{$grant}'"), '23514');
            $this->refused($pdo, fn () => $pdo->exec("UPDATE staff_grants SET revoked_at = NOW(), revoked_by = '{$first['user']}', revoked_reason = 'removed' WHERE id = '{$expired}'"), '23514');
            $this->refused($pdo, fn () => $pdo->exec("UPDATE staff_scope_groups SET removed_at = NOW() WHERE id = '{$scope}'"), '23514');
            $this->refused($pdo, fn () => $this->insertScopeGroup($pdo, $first, $first['group'], $first['activity']), '23514');
            // A selected group or activity, and the person, of another organization.
            $this->refused($pdo, fn () => $this->insertScopeGroup($pdo, $first, $second['group'], null), '23503');
            $this->refused($pdo, fn () => $this->insertScopeGroup($pdo, $first, null, $second['activity']), '23503');
            $this->refused($pdo, fn () => $this->insertGrant($pdo, ['person' => $second['person']] + $first, 'contacts.message', null), '23503');
            // Who acted is not deleted.
            $this->refused($pdo, fn () => $pdo->exec("DELETE FROM users WHERE id = '{$first['user']}'"), '23503');

            $pdo->exec('SET LOCAL ROLE progressive_app');
            $this->tenant($pdo, '');
            $this->assertSame([], $pdo->query('SELECT id FROM staff_grants')->fetchAll(\PDO::FETCH_COLUMN));
            $this->assertSame([], $pdo->query('SELECT id FROM staff_scope_groups')->fetchAll(\PDO::FETCH_COLUMN));

            $this->tenant($pdo, $first['organization']);
            $this->assertEqualsCanonicalizing([$grant, $expired, $closed], $pdo->query('SELECT id FROM staff_grants')->fetchAll(\PDO::FETCH_COLUMN));
            $this->assertEqualsCanonicalizing([$scope, $activityScope], $pdo->query('SELECT id FROM staff_scope_groups')->fetchAll(\PDO::FETCH_COLUMN));
            // Another organization's rows are neither changed nor written…
            $this->assertSame(0, $pdo->exec("UPDATE staff_grants SET revoked_at = NOW(), revoked_reason = 'removed' WHERE id = '{$foreignGrant}'"));
            $this->assertSame(0, $pdo->exec("UPDATE staff_scope_groups SET removed_at = NOW() WHERE id = '{$foreignScope}'"));
            $this->refused($pdo, fn () => $this->insertGrant($pdo, $second, 'contacts.message', null), '42501');
            $this->refused($pdo, fn () => $this->insertScopeGroup($pdo, $second, $second['group'], null), '42501');
            // …nothing is deleted, and who, what and when are not even the role's to change.
            $this->refused($pdo, fn () => $pdo->exec("DELETE FROM staff_grants WHERE id = '{$grant}'"), '42501');
            $this->refused($pdo, fn () => $pdo->exec("DELETE FROM staff_scope_groups WHERE id = '{$scope}'"), '42501');
            $this->refused($pdo, fn () => $pdo->exec("UPDATE staff_grants SET ability = 'team.assign' WHERE id = '{$grant}'"), '42501');
            $this->refused($pdo, fn () => $pdo->exec("UPDATE staff_grants SET staff_member_id = '{$first['other']}' WHERE id = '{$grant}'"), '42501');
            $this->refused($pdo, fn () => $pdo->exec("UPDATE staff_scope_groups SET group_id = '{$first['group']}' WHERE id = '{$activityScope}'"), '42501');

            // What the application does: a new end, revoking, closing an expired right, removing a group, adding.
            $this->assertSame(1, $pdo->exec("UPDATE staff_grants SET expires_at = NOW() + INTERVAL '30 days', updated_at = NOW() WHERE id = '{$grant}'"));
            $this->assertSame(1, $pdo->exec("UPDATE staff_grants SET revoked_at = NOW(), revoked_by = '{$first['user']}', revoked_reason = 'removed', updated_at = NOW() WHERE id = '{$grant}'"));
            $this->refused($pdo, fn () => $pdo->exec("UPDATE staff_grants SET revoked_at = NOW(), revoked_by = '{$first['user']}', revoked_reason = 'role_changed', updated_at = NOW() WHERE id = '{$expired}'"), '23514');
            $this->assertSame(1, $pdo->exec("UPDATE staff_grants SET revoked_at = NOW(), revoked_reason = 'expired', updated_at = NOW() WHERE id = '{$expired}'"));
            $this->assertSame(1, $pdo->exec("UPDATE staff_scope_groups SET removed_at = NOW(), removed_by = '{$first['user']}', updated_at = NOW() WHERE id = '{$scope}'"));
            $this->refused($pdo, fn () => $pdo->exec("UPDATE staff_scope_groups SET removed_by = NULL WHERE id = '{$scope}'"), '23514');
            $this->insertGrant($pdo, $first, 'contacts.view', "NOW() + INTERVAL '7 days'");
            $this->insertScopeGroup($pdo, $first, $first['group'], null);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    private function refused(\PDO $pdo, callable $statement, ?string $code): void
    {
        $savepoint = 'sp_'.Str::lower(Str::random(8));
        $pdo->exec("SAVEPOINT {$savepoint}");
        try {
            $statement();
            $this->fail('The database accepted a statement it must refuse.');
        } catch (\PDOException $exception) {
            if ($code !== null) {
                $this->assertSame($code, $exception->getCode(), $exception->getMessage());
            }
            $pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
        }
    }

    private function tenant(\PDO $pdo, string $organization): void
    {
        $pdo->prepare("SELECT set_config('app.current_organization_id', :id, true)")->execute(['id' => $organization]);
    }

    /** @return array{organization: string, user: string, person: string, other: string, activity: string, group: string} */
    private function organization(\PDO $pdo): array
    {
        $organization = (string) Str::uuid();
        $pdo->prepare("INSERT INTO organizations (id, name, slug, status, created_at, updated_at) VALUES (:id, :name, :slug, 'active', NOW(), NOW())")
            ->execute(['id' => $organization, 'name' => 'Организация '.$organization, 'slug' => 'org-'.$organization]);
        $user = (string) Str::uuid();
        $pdo->prepare("INSERT INTO users (id, name, email, password, created_at, updated_at) VALUES (:id, 'Синтетичен собственик', :email, 'x', NOW(), NOW())")
            ->execute(['id' => $user, 'email' => 'owner-'.$user.'@example.test']);
        $person = $this->insertPerson($pdo, $organization);
        $other = $this->insertPerson($pdo, $organization);
        $activity = (string) Str::uuid();
        $pdo->prepare("INSERT INTO activities (id, organization_id, title, slug, category, summary, age_label, status, created_at, updated_at) VALUES (:id, :organization, 'Шах', :slug, 'Игри', 'Шах', '7+', 'draft', NOW(), NOW())")
            ->execute(['id' => $activity, 'organization' => $organization, 'slug' => 'chess-'.$activity]);
        $offering = (string) Str::uuid();
        $pdo->prepare('INSERT INTO offerings (id, organization_id, activity_id, created_at, updated_at) VALUES (:id, :organization, :activity, NOW(), NOW())')
            ->execute(['id' => $offering, 'organization' => $organization, 'activity' => $activity]);
        $group = (string) Str::uuid();
        $pdo->prepare("INSERT INTO activity_groups (id, organization_id, offering_id, name, capacity_min, status, created_at, updated_at) VALUES (:id, :organization, :offering, 'Вторник', 1, 'draft', NOW(), NOW())")
            ->execute(['id' => $group, 'organization' => $organization, 'offering' => $offering]);

        return compact('organization', 'user', 'person', 'other', 'activity', 'group');
    }

    private function insertPerson(\PDO $pdo, string $organization): string
    {
        $id = (string) Str::uuid();
        $pdo->prepare("INSERT INTO staff_members (id, organization_id, full_name, version, created_at, updated_at) VALUES (:id, :organization, 'Синтетичен служител', 1, NOW(), NOW())")
            ->execute(['id' => $id, 'organization' => $organization]);

        return $id;
    }

    /** @param array{organization: string, user: string, person: string} $tenant */
    private function insertGrant(\PDO $pdo, array $tenant, string $ability, ?string $expiresAt, string $grantedAt = 'NOW()'): string
    {
        $id = (string) Str::uuid();
        $expires = $expiresAt ?? 'NULL';
        $pdo->prepare('INSERT INTO staff_grants (id, organization_id, staff_member_id, ability, granted_by, granted_at, expires_at, created_at, updated_at) '
            ."VALUES (:id, :organization, :person, :ability, :user, {$grantedAt}, {$expires}, NOW(), NOW())")
            ->execute(['id' => $id, 'organization' => $tenant['organization'], 'person' => $tenant['person'], 'ability' => $ability, 'user' => $tenant['user']]);

        return $id;
    }

    /** @param array{organization: string, user: string, person: string} $tenant */
    private function insertScopeGroup(\PDO $pdo, array $tenant, ?string $group, ?string $activity): string
    {
        $id = (string) Str::uuid();
        $pdo->prepare('INSERT INTO staff_scope_groups (id, organization_id, staff_member_id, group_id, activity_id, added_by, added_at, created_at, updated_at) '
            .'VALUES (:id, :organization, :person, :group, :activity, :user, NOW(), NOW(), NOW())')
            ->execute(['id' => $id, 'organization' => $tenant['organization'], 'person' => $tenant['person'], 'group' => $group, 'activity' => $activity, 'user' => $tenant['user']]);

        return $id;
    }

    private function postgresIsAvailable(): bool
    {
        try {
            DB::connection('pgsql_admin')->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
