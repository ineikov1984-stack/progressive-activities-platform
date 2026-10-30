<?php

namespace Tests\Feature\PaymentTerms;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Tests\TestCase;

/**
 * Step 2d (docs/product/payment-policies.md, т. 7.3, т. 11): the database keeps an activity's chosen sets (one per
 * way of paying) and an application's copy inside their organization, and the copy unchanged.
 */
class PaymentTermsCopySchemaTest extends TestCase
{
    use RefreshDatabase;

    private const KEYS = 'offering_payment_terms_terms_tenant_fk, enrollment_applications_terms_version_tenant_fk';

    #[TestGroup('tenant-isolation')]
    public function test_the_choice_and_the_copy_stay_in_their_organization_and_the_copy_never_changes(): void
    {
        if (! $this->postgresIsAvailable()) {
            $this->markTestSkipped('PostgreSQL is required for the database-layer payment terms checks.');
        }

        Artisan::call('migrate', ['--database' => 'pgsql_admin', '--force' => true]);
        $pdo = DB::connection('pgsql_admin')->getPdo();
        $pdo->beginTransaction();

        try {
            $first = $this->organization($pdo);
            $second = $this->organization($pdo);

            // An activity chooses, for one way of paying, a set of its own organization for that way of paying —
            // never another organization's, never a set for another way of paying (composite key).
            $this->choose($pdo, $first, 'monthly', $first['terms']);
            $this->checkKeys($pdo);
            // Only the organization differs here: the other organization's set is for the same way of paying.
            $secondCourse = $this->insertSet($pdo, $second['organization'], 'full_period');
            $this->refused($pdo, function () use ($pdo, $first, $secondCourse): void {
                $this->choose($pdo, $first, 'full_period', $secondCourse);
                $this->checkKeys($pdo);
            }, '23503');
            // A row of one organization never points at another organization's activity.
            $this->refused($pdo, function () use ($pdo, $first, $second): void {
                $pdo->prepare('INSERT INTO offering_payment_terms (offering_id, organization_id, billing_kind, payment_terms_id, created_at, updated_at) '
                    ."VALUES (:offering, :organization, 'monthly', :terms, NOW(), NOW())")
                    ->execute(['offering' => $first['offering'], 'organization' => $second['organization'], 'terms' => $second['terms']]);
            }, '23503');
            $this->refused($pdo, function () use ($pdo, $first): void {
                $this->choose($pdo, $first, 'visit', $first['terms']);
                $this->checkKeys($pdo);
            }, '23503');
            // One choice per way of paying.
            $this->refused($pdo, fn () => $this->choose($pdo, $first, 'monthly', $first['terms']), '23505');
            // The choices are read only inside their organization.
            $this->choose($pdo, $second, 'monthly', $second['terms']);
            $firstCourse = $this->insertSet($pdo, $first['organization'], 'full_period');
            $pdo->exec('SET LOCAL ROLE progressive_app');
            $this->tenant($pdo, $second['organization']);
            $this->assertSame([$second['offering']], $pdo->query('SELECT offering_id FROM offering_payment_terms')->fetchAll(\PDO::FETCH_COLUMN));
            $this->assertSame(0, $pdo->exec("DELETE FROM offering_payment_terms WHERE offering_id = '{$first['offering']}'"));
            // …and written only for the organization that is set (RLS WITH CHECK).
            $this->refused($pdo, fn () => $this->choose($pdo, $first, 'full_period', $firstCourse), '42501');
            $pdo->exec('RESET ROLE');

            // An application keeps a copy of a version of its own organization…
            $application = $this->insertApplication($pdo, $first, $first['version'], '{"key": "v"}');
            $this->checkKeys($pdo);
            $this->refused($pdo, function () use ($pdo, $first, $second): void {
                $this->insertApplication($pdo, $first, $second['version'], '{"key": "v"}');
                $this->checkKeys($pdo);
            }, '23503');
            // …always with the numbers, and the copy is an object.
            $this->refused($pdo, fn () => $this->insertApplication($pdo, $first, $first['version'], null), '23514');
            $this->refused($pdo, fn () => $this->insertApplication($pdo, $first, null, '[]'), '23514');
            // An application from before the terms has no copy.
            $this->insertApplication($pdo, $first, null, null);

            // The copy never changes, while the decision on the application still can.
            $pdo->exec('SET LOCAL ROLE progressive_app');
            $this->tenant($pdo, $first['organization']);
            $this->refused($pdo, fn () => $pdo->exec("UPDATE enrollment_applications SET payment_terms_snapshot = '{}' WHERE id = '{$application}'"), 'P0001');
            $this->refused($pdo, fn () => $pdo->exec("UPDATE enrollment_applications SET accepted_terms_version = 'друга' WHERE id = '{$application}'"), 'P0001');
            $this->refused($pdo, fn () => $pdo->exec("UPDATE enrollment_applications SET accepted_terms_at = NOW() - INTERVAL '1 day' WHERE id = '{$application}'"), 'P0001');
            $this->assertSame(1, $pdo->exec("UPDATE enrollment_applications SET status = 'approved', version = 2 WHERE id = '{$application}'"));
            // Nor is it replaced by deleting the application and writing it again.
            $this->refused($pdo, fn () => $pdo->exec("DELETE FROM enrollment_applications WHERE id = '{$application}'"), '42501');
            // Not even the database owner changes it.
            $pdo->exec('RESET ROLE');
            $this->refused($pdo, fn () => $pdo->exec("UPDATE enrollment_applications SET payment_terms_version_id = NULL, payment_terms_snapshot = NULL WHERE id = '{$application}'"), 'P0001');

            // Removing a whole organization still removes its sets, activities and applications together.
            $pdo->prepare('DELETE FROM organizations WHERE id = :id')->execute(['id' => $first['organization']]);
            $this->checkKeys($pdo);
            foreach (['payment_terms', 'payment_terms_versions', 'offerings', 'offering_payment_terms', 'enrollment_applications'] as $table) {
                $left = $pdo->prepare("SELECT count(*) FROM {$table} WHERE organization_id = :id");
                $left->execute(['id' => $first['organization']]);
                $this->assertSame(0, (int) $left->fetchColumn(), $table);
            }
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    /** The keys are checked at commit; the test never commits, so it asks for the check now. */
    private function checkKeys(\PDO $pdo): void
    {
        $pdo->exec('SET CONSTRAINTS '.self::KEYS.' IMMEDIATE');
        $pdo->exec('SET CONSTRAINTS '.self::KEYS.' DEFERRED');
    }

    private function refused(\PDO $pdo, callable $statement, string $code): void
    {
        $savepoint = 'sp_'.Str::lower(Str::random(8));
        $pdo->exec("SAVEPOINT {$savepoint}");
        try {
            $statement();
            $this->fail('The database accepted a statement it must refuse.');
        } catch (\PDOException $exception) {
            $this->assertSame($code, $exception->getCode(), $exception->getMessage());
            $pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
            $pdo->exec('SET CONSTRAINTS '.self::KEYS.' DEFERRED');
        }
    }

    private function tenant(\PDO $pdo, string $organization): void
    {
        $pdo->prepare("SELECT set_config('app.current_organization_id', :id, true)")->execute(['id' => $organization]);
    }

    /** @return array{organization: string, terms: string, version: string, offering: string, group: string, parent: string, child: string} */
    private function organization(\PDO $pdo): array
    {
        $ids = array_map(fn () => (string) Str::uuid(), array_flip(['organization', 'terms', 'version', 'activity', 'offering', 'group', 'parent', 'child']));
        $insert = fn (string $sql, array $values) => $pdo->prepare($sql)->execute($values);
        $insert("INSERT INTO organizations (id, name, slug, status, created_at, updated_at) VALUES (:id, :name, :slug, 'active', NOW(), NOW())",
            ['id' => $ids['organization'], 'name' => 'Организация '.$ids['organization'], 'slug' => 'org-'.$ids['organization']]);
        $insert('INSERT INTO payment_terms (id, organization_id, billing_kind, name, preset, is_default, self_service, status, version, created_at, updated_at) '
            ."VALUES (:id, :organization, 'monthly', 'Стандартни', 'standard', true, '{}', 'active', 1, NOW(), NOW())",
            ['id' => $ids['terms'], 'organization' => $ids['organization']]);
        $insert('INSERT INTO payment_terms_versions (id, organization_id, terms_id, number, schema_version, rules, created_at) '
            ."VALUES (:id, :organization, :terms, 1, 1, '{\"refund\": \"parent_choice\"}', NOW())",
            ['id' => $ids['version'], 'organization' => $ids['organization'], 'terms' => $ids['terms']]);
        $insert('INSERT INTO activities (id, organization_id, slug, title, category, summary, age_label, created_at, updated_at) '
            ."VALUES (:id, :organization, :slug, 'Шахмат', 'Наука', 'Синтетично', '7–9 г.', NOW(), NOW())",
            ['id' => $ids['activity'], 'organization' => $ids['organization'], 'slug' => 'chess-'.$ids['activity']]);
        $insert('INSERT INTO offerings (id, organization_id, activity_id, created_at, updated_at) VALUES (:id, :organization, :activity, NOW(), NOW())',
            ['id' => $ids['offering'], 'organization' => $ids['organization'], 'activity' => $ids['activity']]);
        $insert("INSERT INTO activity_groups (id, organization_id, offering_id, name, created_at, updated_at) VALUES (:id, :organization, :offering, 'Група 1', NOW(), NOW())",
            ['id' => $ids['group'], 'organization' => $ids['organization'], 'offering' => $ids['offering']]);
        $insert("INSERT INTO users (id, name, email, password, created_at, updated_at) VALUES (:id, 'Синтетичен родител', :email, 'x', NOW(), NOW())",
            ['id' => $ids['parent'], 'email' => "terms-copy-{$ids['parent']}@example.test"]);
        $insert("INSERT INTO children (id, owner_user_id, first_name, last_name, created_at, updated_at) VALUES (:id, :parent, 'Ния', 'Синтетична', NOW(), NOW())",
            ['id' => $ids['child'], 'parent' => $ids['parent']]);

        return $ids;
    }

    private function insertSet(\PDO $pdo, string $organization, string $kind): string
    {
        $id = (string) Str::uuid();
        $pdo->prepare('INSERT INTO payment_terms (id, organization_id, billing_kind, name, preset, is_default, self_service, status, version, created_at, updated_at) '
            ."VALUES (:id, :organization, :kind, 'Цял курс', 'standard', false, '{}', 'active', 1, NOW(), NOW())")
            ->execute(['id' => $id, 'organization' => $organization, 'kind' => $kind]);

        return $id;
    }

    /** @param array{organization: string, offering: string} $organization */
    private function choose(\PDO $pdo, array $organization, string $kind, string $terms): void
    {
        $pdo->prepare('INSERT INTO offering_payment_terms (offering_id, organization_id, billing_kind, payment_terms_id, created_at, updated_at) '
            .'VALUES (:offering, :organization, :kind, :terms, NOW(), NOW())')
            ->execute(['offering' => $organization['offering'], 'organization' => $organization['organization'], 'kind' => $kind, 'terms' => $terms]);
    }

    /** @param array{organization: string, group: string, parent: string, child: string} $organization */
    private function insertApplication(\PDO $pdo, array $organization, ?string $version, ?string $snapshot): string
    {
        $id = (string) Str::uuid();
        $pdo->prepare('INSERT INTO enrollment_applications (id, organization_id, parent_user_id, child_id, group_id, scope, source, status, '
            .'idempotency_key, accepted_terms_version, accepted_terms_at, amount_cents_snapshot, unit_snapshot, payment_terms_version_id, '
            .'payment_terms_snapshot, created_at, updated_at) '
            ."VALUES (:id, :organization, :parent, :child, :group, 'full_period', 'organization_catalog', 'submitted', :key, 'v', NOW(), 4500, "
            ."'month', :version, CAST(:snapshot AS jsonb), NOW(), NOW())")
            ->execute([
                'id' => $id, 'organization' => $organization['organization'], 'parent' => $organization['parent'],
                'child' => $organization['child'], 'group' => $organization['group'], 'key' => 'copy-'.$id,
                'version' => $version, 'snapshot' => $snapshot,
            ]);

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
