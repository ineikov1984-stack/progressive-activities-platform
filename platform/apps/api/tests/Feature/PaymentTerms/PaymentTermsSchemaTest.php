<?php

namespace Tests\Feature\PaymentTerms;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Tests\TestCase;

/** Step 2b: the database itself keeps sets and versions inside their organization and versions unchanged. */
class PaymentTermsSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[TestGroup('tenant-isolation')]
    public function test_row_level_security_composite_keys_and_immutable_versions(): void
    {
        if (! $this->postgresIsAvailable()) {
            $this->markTestSkipped('PostgreSQL is required for the database-layer payment terms checks.');
        }

        Artisan::call('migrate', ['--database' => 'pgsql_admin', '--force' => true]);
        $pdo = DB::connection('pgsql_admin')->getPdo();
        $pdo->beginTransaction();

        try {
            $first = $this->organizationWithTerms($pdo);
            $second = $this->organizationWithTerms($pdo);

            $pdo->exec('SET LOCAL ROLE progressive_app');
            $this->tenant($pdo, '');
            $this->assertSame([], $pdo->query('SELECT id FROM payment_terms')->fetchAll(\PDO::FETCH_COLUMN));
            $this->assertSame([], $pdo->query('SELECT id FROM payment_terms_versions')->fetchAll(\PDO::FETCH_COLUMN));

            $this->tenant($pdo, $first['organization']);
            $this->assertSame([$first['terms']], $pdo->query('SELECT id FROM payment_terms')->fetchAll(\PDO::FETCH_COLUMN));
            $this->assertSame([$first['version']], $pdo->query('SELECT id FROM payment_terms_versions')->fetchAll(\PDO::FETCH_COLUMN));
            // Another organization's rows are neither changed nor written.
            $this->assertSame(0, $pdo->exec("UPDATE payment_terms SET name = 'Чужди' WHERE id = '{$second['terms']}'"));
            $this->refused($pdo, fn () => $this->insertTerms($pdo, $second['organization'], 'Чужди'), null);
            // A version of this organization cannot point at another organization's set (composite key).
            $this->refused($pdo, fn () => $this->insertVersion($pdo, $first['organization'], $second['terms'], 2), '23503');
            // The application only adds versions and never deletes sets (no rights for it)…
            $this->refused($pdo, fn () => $pdo->exec("UPDATE payment_terms_versions SET rules = '{}' WHERE id = '{$first['version']}'"), '42501');
            $this->refused($pdo, fn () => $pdo->exec("DELETE FROM payment_terms_versions WHERE id = '{$first['version']}'"), '42501');
            $this->refused($pdo, fn () => $pdo->exec("DELETE FROM payment_terms WHERE id = '{$first['terms']}'"), '42501');
            // One default per way of paying, and never an archived one.
            $this->refused($pdo, fn () => $this->insertTerms($pdo, $first['organization'], 'Втори', isDefault: true), '23505');
            $this->refused($pdo, fn () => $pdo->exec("UPDATE payment_terms SET status = 'archived' WHERE id = '{$first['terms']}'"), '23514');
            $this->insertTerms($pdo, $first['organization'], 'Втори');

            // …and the triggers refuse it even to the database owner.
            $pdo->exec('RESET ROLE');
            $this->refused($pdo, fn () => $pdo->exec("UPDATE payment_terms_versions SET rules = '{}' WHERE id = '{$first['version']}'"), 'P0001');
            $this->refused($pdo, fn () => $pdo->exec("DELETE FROM payment_terms_versions WHERE id = '{$first['version']}'"), 'P0001');
            $this->refused($pdo, fn () => $pdo->exec("DELETE FROM payment_terms WHERE id = '{$first['terms']}'"), 'P0001');

            // Removing a whole organization still removes its sets and versions.
            $pdo->prepare('DELETE FROM organizations WHERE id = :id')->execute(['id' => $second['organization']]);
            $left = $pdo->prepare('SELECT count(*) FROM payment_terms_versions WHERE organization_id = :id');
            $left->execute(['id' => $second['organization']]);
            $this->assertSame(0, (int) $left->fetchColumn());
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

    /** @return array{organization: string, terms: string, version: string} */
    private function organizationWithTerms(\PDO $pdo): array
    {
        $organization = (string) Str::uuid();
        $pdo->prepare("INSERT INTO organizations (id, name, slug, status, created_at, updated_at) VALUES (:id, :name, :slug, 'active', NOW(), NOW())")
            ->execute(['id' => $organization, 'name' => 'Организация '.$organization, 'slug' => 'org-'.$organization]);
        $terms = $this->insertTerms($pdo, $organization, 'Стандартни', isDefault: true);

        return ['organization' => $organization, 'terms' => $terms, 'version' => $this->insertVersion($pdo, $organization, $terms, 1)];
    }

    private function insertTerms(\PDO $pdo, string $organization, string $name, bool $isDefault = false): string
    {
        $id = (string) Str::uuid();
        $pdo->prepare('INSERT INTO payment_terms (id, organization_id, billing_kind, name, preset, is_default, self_service, status, version, created_at, updated_at) '
            ."VALUES (:id, :organization, 'visit', :name, 'standard', :default, '{}', 'active', 1, NOW(), NOW())")
            ->execute(['id' => $id, 'organization' => $organization, 'name' => $name, 'default' => $isDefault ? 'true' : 'false']);

        return $id;
    }

    private function insertVersion(\PDO $pdo, string $organization, string $terms, int $number): string
    {
        $id = (string) Str::uuid();
        $pdo->prepare('INSERT INTO payment_terms_versions (id, organization_id, terms_id, number, schema_version, rules, created_at) '
            ."VALUES (:id, :organization, :terms, :number, 1, '{\"refund\": \"parent_choice\"}', NOW())")
            ->execute(['id' => $id, 'organization' => $organization, 'terms' => $terms, 'number' => $number]);

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
