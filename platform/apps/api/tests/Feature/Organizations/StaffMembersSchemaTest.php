<?php

namespace Tests\Feature\Organizations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Tests\TestCase;

/**
 * The database itself keeps the people of a team inside their organization (docs/product/team.md, т. 11, 15): another
 * organization's people are neither read, changed nor written, nobody is deleted, and a teacher profile cannot point at
 * another organization's person.
 */
class StaffMembersSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[TestGroup('tenant-isolation')]
    public function test_row_level_security_rights_and_the_composite_key_of_staff_members(): void
    {
        if (! $this->postgresIsAvailable()) {
            $this->markTestSkipped('PostgreSQL is required for the database-layer team checks.');
        }

        Artisan::call('migrate', ['--database' => 'pgsql_admin', '--force' => true]);
        $pdo = DB::connection('pgsql_admin')->getPdo();
        $pdo->beginTransaction();

        try {
            $first = $this->organizationWithPerson($pdo);
            $second = $this->organizationWithPerson($pdo);
            // A person of the other organization without a teacher profile, so that only the composite key can refuse.
            $loose = $this->insertPerson($pdo, $second['organization']);

            $pdo->exec('SET LOCAL ROLE progressive_app');
            $this->tenant($pdo, '');
            $this->assertSame([], $pdo->query('SELECT id FROM staff_members')->fetchAll(\PDO::FETCH_COLUMN));

            $this->tenant($pdo, $first['organization']);
            $this->assertSame([$first['person']], $pdo->query('SELECT id FROM staff_members')->fetchAll(\PDO::FETCH_COLUMN));
            // Another organization's person is neither changed nor written…
            $this->assertSame(0, $pdo->exec("UPDATE staff_members SET full_name = 'Чужд' WHERE id = '{$second['person']}'"));
            $this->refused($pdo, fn () => $this->insertPerson($pdo, $second['organization']), '42501');
            // …a person is archived, never deleted (no right for it)…
            $this->refused($pdo, fn () => $pdo->exec("DELETE FROM staff_members WHERE id = '{$first['person']}'"), '42501');
            // …and a teacher profile of this organization cannot point at another organization's person.
            $this->refused($pdo, fn () => $pdo->exec("UPDATE instructor_profiles SET staff_member_id = '{$loose}' WHERE id = '{$first['profile']}'"), '23503');
            $this->assertSame(1, $pdo->exec("UPDATE staff_members SET archived_at = NOW() WHERE id = '{$first['person']}'"));
            $this->insertPerson($pdo, $first['organization']);
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

    /** @return array{organization: string, person: string, profile: string} */
    private function organizationWithPerson(\PDO $pdo): array
    {
        $organization = (string) Str::uuid();
        $pdo->prepare("INSERT INTO organizations (id, name, slug, status, created_at, updated_at) VALUES (:id, :name, :slug, 'active', NOW(), NOW())")
            ->execute(['id' => $organization, 'name' => 'Организация '.$organization, 'slug' => 'org-'.$organization]);
        $person = $this->insertPerson($pdo, $organization);
        $profile = (string) Str::uuid();
        $pdo->prepare('INSERT INTO instructor_profiles (id, organization_id, public_name, staff_member_id, created_at, updated_at) '
            .'VALUES (:id, :organization, :name, :person, NOW(), NOW())')
            ->execute(['id' => $profile, 'organization' => $organization, 'name' => 'Треньор', 'person' => $person]);

        return ['organization' => $organization, 'person' => $person, 'profile' => $profile];
    }

    private function insertPerson(\PDO $pdo, string $organization): string
    {
        $id = (string) Str::uuid();
        $pdo->prepare('INSERT INTO staff_members (id, organization_id, full_name, phone, version, created_at, updated_at) '
            ."VALUES (:id, :organization, 'Синтетичен треньор', '0888000000', 1, NOW(), NOW())")
            ->execute(['id' => $id, 'organization' => $organization]);

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
