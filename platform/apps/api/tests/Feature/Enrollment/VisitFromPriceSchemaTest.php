<?php

namespace Tests\Feature\Enrollment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Tests\TestCase;

/**
 * Visits from a price and a date in PostgreSQL (docs/product/visits.md, т. 6): the group's places for visitors stay
 * in range, a new visit without an offer always carries the copy of its terms, and an older one keeps working.
 */
class VisitFromPriceSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[TestGroup('tenant-isolation')]
    public function test_visit_places_stay_in_range_and_a_new_visit_without_an_offer_has_a_copy_of_its_terms(): void
    {
        if (! $this->postgresIsAvailable()) {
            $this->markTestSkipped('PostgreSQL is required for the database-layer visit checks.');
        }

        Artisan::call('migrate', ['--database' => 'pgsql_admin', '--force' => true]);
        $pdo = DB::connection('pgsql_admin')->getPdo();
        $pdo->beginTransaction();

        try {
            $ids = $this->organization($pdo);

            // Every free place (null) or 1–500 children a session.
            foreach ([0, 501, -1] as $places) {
                $this->refused($pdo, fn () => $pdo->exec("UPDATE activity_groups SET visit_places = {$places} WHERE id = '{$ids['group']}'"), '23514');
            }
            $this->assertSame(1, $pdo->exec("UPDATE activity_groups SET visit_places = 2 WHERE id = '{$ids['group']}'"));
            $this->assertSame(1, $pdo->exec("UPDATE activity_groups SET visit_places = NULL WHERE id = '{$ids['group']}'"));

            // A new trial or single visit without an offer needs the copy of its terms…
            $pdo->exec('SET LOCAL ROLE progressive_app');
            $this->tenant($pdo, $ids['organization']);
            foreach (['trial', 'single'] as $scope) {
                $this->refused($pdo, fn () => $this->insertApplication($pdo, $ids, $scope, null), 'P0001');
            }
            $this->insertApplication($pdo, $ids, 'single', '{"key": "standard-visit", "billing_kind": "visit"}');
            // …while a period enrollment without a copy is still possible (applications from before the terms).
            $this->insertApplication($pdo, $ids, 'full_period', null);
            $pdo->exec('RESET ROLE');

            // An application written before this step, without a copy, can still be withdrawn or decided.
            // (Written past the triggers, as the database owner restoring old data would.)
            $pdo->exec('SET LOCAL session_replication_role = replica');
            $older = $this->insertApplication($pdo, $ids, 'trial', null);
            $pdo->exec('SET LOCAL session_replication_role = origin');
            $pdo->exec('SET LOCAL ROLE progressive_app');
            $this->tenant($pdo, $ids['organization']);
            $this->assertSame(1, $pdo->exec("UPDATE enrollment_applications SET status = 'withdrawn', version = 2 WHERE id = '{$older}'"));
            $pdo->exec('RESET ROLE');
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
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
        }
    }

    private function tenant(\PDO $pdo, string $organization): void
    {
        $pdo->prepare("SELECT set_config('app.current_organization_id', :id, true)")->execute(['id' => $organization]);
    }

    /** @return array{organization: string, group: string, session: string, parent: string, child: string} */
    private function organization(\PDO $pdo): array
    {
        $ids = array_map(fn () => (string) Str::uuid(), array_flip(['organization', 'activity', 'offering', 'group', 'session', 'parent', 'child']));
        $insert = fn (string $sql, array $values) => $pdo->prepare($sql)->execute($values);
        $insert("INSERT INTO organizations (id, name, slug, status, created_at, updated_at) VALUES (:id, :name, :slug, 'active', NOW(), NOW())",
            ['id' => $ids['organization'], 'name' => 'Организация '.$ids['organization'], 'slug' => 'org-'.$ids['organization']]);
        $insert('INSERT INTO activities (id, organization_id, slug, title, category, summary, age_label, created_at, updated_at) '
            ."VALUES (:id, :organization, :slug, 'Шахмат', 'Наука', 'Синтетично', '7–9 г.', NOW(), NOW())",
            ['id' => $ids['activity'], 'organization' => $ids['organization'], 'slug' => 'chess-'.$ids['activity']]);
        $insert('INSERT INTO offerings (id, organization_id, activity_id, created_at, updated_at) VALUES (:id, :organization, :activity, NOW(), NOW())',
            ['id' => $ids['offering'], 'organization' => $ids['organization'], 'activity' => $ids['activity']]);
        $insert("INSERT INTO activity_groups (id, organization_id, offering_id, name, created_at, updated_at) VALUES (:id, :organization, :offering, 'Група 1', NOW(), NOW())",
            ['id' => $ids['group'], 'organization' => $ids['organization'], 'offering' => $ids['offering']]);
        $insert('INSERT INTO activity_sessions (id, organization_id, group_id, starts_at, ends_at, created_at, updated_at) '
            ."VALUES (:id, :organization, :group, NOW() + INTERVAL '3 days', NOW() + INTERVAL '3 days 1 hour', NOW(), NOW())",
            ['id' => $ids['session'], 'organization' => $ids['organization'], 'group' => $ids['group']]);
        $insert("INSERT INTO users (id, name, email, password, created_at, updated_at) VALUES (:id, 'Синтетичен родител', :email, 'x', NOW(), NOW())",
            ['id' => $ids['parent'], 'email' => "visit-{$ids['parent']}@example.test"]);
        $insert("INSERT INTO children (id, owner_user_id, first_name, last_name, created_at, updated_at) VALUES (:id, :parent, 'Ния', 'Синтетична', NOW(), NOW())",
            ['id' => $ids['child'], 'parent' => $ids['parent']]);

        return $ids;
    }

    /** @param array{organization: string, group: string, session: string, parent: string, child: string} $ids */
    private function insertApplication(\PDO $pdo, array $ids, string $scope, ?string $snapshot): string
    {
        $id = (string) Str::uuid();
        $pdo->prepare('INSERT INTO enrollment_applications (id, organization_id, parent_user_id, child_id, group_id, session_id, scope, source, '
            .'status, idempotency_key, accepted_terms_version, accepted_terms_at, amount_cents_snapshot, unit_snapshot, payment_terms_snapshot, '
            .'created_at, updated_at) '
            ."VALUES (:id, :organization, :parent, :child, :group, :session, :scope, 'organization_catalog', 'submitted', :key, 'v', NOW(), 0, "
            ."'session', CAST(:snapshot AS jsonb), NOW(), NOW())")
            ->execute([
                'id' => $id, 'organization' => $ids['organization'], 'parent' => $ids['parent'], 'child' => $ids['child'],
                'group' => $ids['group'], 'session' => $scope === 'full_period' ? null : $ids['session'], 'scope' => $scope,
                'key' => 'visit-'.$id, 'snapshot' => $snapshot,
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
