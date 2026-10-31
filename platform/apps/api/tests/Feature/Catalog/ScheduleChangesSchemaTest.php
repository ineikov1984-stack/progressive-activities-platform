<?php

namespace Tests\Feature\Catalog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Tests\TestCase;

/**
 * The database itself keeps the history of the schedule in its organization and whole (docs/product/schedule-changes.md,
 * т. 3.4, 9): another organization's rows are neither read nor written; the application only reads and adds; nothing is
 * changed or deleted, also by the owner of the tables, except a deleted place clearing itself; a session, a change or a
 * place of another organization is refused; who acted is not deleted; a session with history is not deleted, a draft's
 * without history is; the organization takes all of it along; a continuation never starts before its day.
 */
class ScheduleChangesSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[TestGroup('tenant-isolation')]
    public function test_row_level_security_rights_triggers_and_keys_of_the_history_of_the_schedule(): void
    {
        if (! $this->postgresIsAvailable()) {
            $this->markTestSkipped('PostgreSQL is required for the database-layer checks of the history of the schedule.');
        }

        Artisan::call('migrate', ['--database' => 'pgsql_admin', '--force' => true]);
        $pdo = DB::connection('pgsql_admin')->getPdo();
        $pdo->beginTransaction();

        try {
            $this->assertSame(['schedule_changes', 'session_changes'], $pdo->query(
                "SELECT relname FROM pg_class WHERE relname IN ('schedule_changes', 'session_changes') AND relkind = 'r' "
                .'AND relrowsecurity AND relforcerowsecurity ORDER BY relname',
            )->fetchAll(\PDO::FETCH_COLUMN));

            $first = $this->organization($pdo);
            $second = $this->organization($pdo);
            $change = $this->insertChange($pdo, $first, 'cancel', 'Ремонт', 'same-key-twice');
            $history = $this->insertSessionChange($pdo, $first, $change, $first['session'], $first['place'], $first['other_place']);
            $foreignChange = $this->insertChange($pdo, $second, 'cancel', 'Ремонт', 'same-key-twice');
            $this->insertSessionChange($pdo, $second, $foreignChange, $second['session'], null, null);

            // Even the owner role: the history is neither changed nor deleted on its own.
            $this->refused($pdo, fn () => $pdo->exec("UPDATE schedule_changes SET reason = 'Друго' WHERE id = '{$change}'"), 'P0001');
            $this->refused($pdo, fn () => $pdo->exec("UPDATE session_changes SET to_status = 'scheduled' WHERE id = '{$history}'"), 'P0001');
            $this->refused($pdo, fn () => $pdo->exec("UPDATE session_changes SET from_location_id = NULL WHERE id = '{$history}'"), 'P0001');
            $this->refused($pdo, fn () => $pdo->exec("DELETE FROM session_changes WHERE id = '{$history}'"), 'P0001');
            $this->refused($pdo, fn () => $pdo->exec("DELETE FROM schedule_changes WHERE id = '{$change}'"), 'P0001');
            // A reason for a cancellation, a known action and a status; one key for a repeat by a person.
            $this->refused($pdo, fn () => $this->insertChange($pdo, $first, 'cancel', null), '23514');
            $this->refused($pdo, fn () => $this->insertChange($pdo, $first, 'nope', 'Ремонт'), '23514');
            $this->insertChange($pdo, $first, 'restore', null);
            $this->refused($pdo, fn () => $this->insertChange($pdo, $first, 'cancel', 'Ремонт', 'same-key-twice', 2), '23505');
            $this->refused($pdo, fn () => $pdo->exec("INSERT INTO session_changes (id, organization_id, schedule_change_id, session_id, from_status, to_status, from_starts_at, from_ends_at, to_starts_at, to_ends_at, confirmed_overlaps, created_at) VALUES ('"
                .Str::uuid()."', '{$first['organization']}', '{$change}', '{$first['draft_session']}', 'scheduled', 'moved', NOW(), NOW(), NOW(), NOW(), '[]', NOW())"), '23514');
            // A session, a change or a place of another organization.
            $this->refused($pdo, fn () => $this->insertSessionChange($pdo, $first, $change, $second['session'], null, null), '23503');
            $this->refused($pdo, fn () => $this->insertSessionChange($pdo, $first, $foreignChange, $first['draft_session'], null, null), '23503');
            $this->refused($pdo, fn () => $this->insertSessionChange($pdo, $first, $change, $first['draft_session'], $second['place'], null), '23503');
            // Who acted is not deleted; a session with history is not deleted, a draft's without history is.
            $this->refused($pdo, fn () => $pdo->exec("DELETE FROM users WHERE id = '{$first['actor']}'"), '23503');
            $this->refused($pdo, fn () => $pdo->exec("DELETE FROM activity_sessions WHERE id = '{$first['session']}'"), '23503');
            $this->assertSame(1, $pdo->exec("DELETE FROM activity_sessions WHERE id = '{$first['draft_session']}'"));
            // A deleted place clears itself out of the history; nothing else of the row changes.
            $this->assertSame(1, $pdo->exec("DELETE FROM locations WHERE id = '{$first['place']}'"));
            $row = $pdo->query("SELECT from_location_id, to_location_id, to_status FROM session_changes WHERE id = '{$history}'")->fetch(\PDO::FETCH_ASSOC);
            $this->assertSame([null, $first['other_place'], 'cancelled'], [$row['from_location_id'], $row['to_location_id'], $row['to_status']]);
            // A continuation never starts before the day of its transfer.
            $this->refused($pdo, fn () => $pdo->exec("UPDATE enrollments SET starts_not_before = '2026-11-20' WHERE id = '{$first['enrollment']}'"), '23514');
            $this->assertSame(1, $pdo->exec("UPDATE enrollments SET starts_not_before = '2026-11-12' WHERE id = '{$first['enrollment']}'"));

            $pdo->exec('SET LOCAL ROLE progressive_app');
            $this->tenant($pdo, '');
            $this->assertSame([], $pdo->query('SELECT id FROM schedule_changes')->fetchAll(\PDO::FETCH_COLUMN));
            $this->assertSame([], $pdo->query('SELECT id FROM session_changes')->fetchAll(\PDO::FETCH_COLUMN));
            $this->tenant($pdo, $first['organization']);
            $this->assertSame([$history], $pdo->query('SELECT id FROM session_changes')->fetchAll(\PDO::FETCH_COLUMN));
            $this->assertNotContains($foreignChange, $pdo->query('SELECT id FROM schedule_changes')->fetchAll(\PDO::FETCH_COLUMN));
            // Another organization's rows are not written; the application only reads and adds.
            $this->refused($pdo, fn () => $this->insertChange($pdo, $second, 'cancel', 'Ремонт'), '42501');
            $this->refused($pdo, fn () => $pdo->exec("UPDATE schedule_changes SET reason = 'Друго' WHERE id = '{$change}'"), '42501');
            $this->refused($pdo, fn () => $pdo->exec("UPDATE session_changes SET to_status = 'scheduled' WHERE id = '{$history}'"), '42501');
            $this->refused($pdo, fn () => $pdo->exec("DELETE FROM session_changes WHERE id = '{$history}'"), '42501');
            $this->refused($pdo, fn () => $pdo->exec("DELETE FROM schedule_changes WHERE id = '{$change}'"), '42501');
            $added = $this->insertChange($pdo, $first, 'restore', null, 'from-the-application');
            $this->insertSessionChange($pdo, $first, $added, $first['session'], null, null);
            $pdo->exec('RESET ROLE');

            // The organization takes its whole history along, the other's stays.
            $this->assertSame(1, $pdo->exec("DELETE FROM organizations WHERE id = '{$first['organization']}'"));
            $this->assertSame(0, (int) $pdo->query("SELECT count(*) FROM session_changes WHERE organization_id = '{$first['organization']}'")->fetchColumn());
            $this->assertSame(0, (int) $pdo->query("SELECT count(*) FROM schedule_changes WHERE organization_id = '{$first['organization']}'")->fetchColumn());
            $this->assertSame(1, (int) $pdo->query("SELECT count(*) FROM session_changes WHERE organization_id = '{$second['organization']}'")->fetchColumn());
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

    /** @return array{organization: string, user: string, actor: string, session: string, draft_session: string, place: string, other_place: string, enrollment: string} */
    private function organization(\PDO $pdo): array
    {
        $organization = (string) Str::uuid();
        $pdo->prepare("INSERT INTO organizations (id, name, slug, status, created_at, updated_at) VALUES (:id, :name, :slug, 'active', NOW(), NOW())")
            ->execute(['id' => $organization, 'name' => 'Организация '.$organization, 'slug' => 'org-'.$organization]);
        $user = (string) Str::uuid();
        $pdo->prepare("INSERT INTO users (id, name, email, password, created_at, updated_at) VALUES (:id, 'Синтетичен собственик', :email, 'x', NOW(), NOW())")
            ->execute(['id' => $user, 'email' => 'owner-'.$user.'@example.test']);
        // Who acts in the office: no child of their own, so only the history holds them.
        $actor = (string) Str::uuid();
        $pdo->prepare("INSERT INTO users (id, name, email, password, created_at, updated_at) VALUES (:id, 'Синтетичен служител', :email, 'x', NOW(), NOW())")
            ->execute(['id' => $actor, 'email' => 'office-'.$actor.'@example.test']);
        $activity = (string) Str::uuid();
        $pdo->prepare("INSERT INTO activities (id, organization_id, title, slug, category, summary, age_label, status, created_at, updated_at) VALUES (:id, :organization, 'Шах', :slug, 'Игри', 'Шах', '7+', 'published', NOW(), NOW())")
            ->execute(['id' => $activity, 'organization' => $organization, 'slug' => 'chess-'.$activity]);
        $offering = (string) Str::uuid();
        $pdo->prepare('INSERT INTO offerings (id, organization_id, activity_id, created_at, updated_at) VALUES (:id, :organization, :activity, NOW(), NOW())')
            ->execute(['id' => $offering, 'organization' => $organization, 'activity' => $activity]);
        $group = $this->insertGroup($pdo, $organization, $offering, 'published');
        $draft = $this->insertGroup($pdo, $organization, $offering, 'draft');
        $place = $this->insertPlace($pdo, $organization);
        $otherPlace = $this->insertPlace($pdo, $organization);
        $session = $this->insertSession($pdo, $organization, $group);
        $draftSession = $this->insertSession($pdo, $organization, $draft);
        $price = (string) Str::uuid();
        $pdo->prepare("INSERT INTO price_options (id, organization_id, offering_id, enrollment_mode, unit, amount_cents, currency, created_at, updated_at) VALUES (:id, :organization, :offering, 'monthly', 'month', 4500, 'EUR', NOW(), NOW())")
            ->execute(['id' => $price, 'organization' => $organization, 'offering' => $offering]);
        $child = (string) Str::uuid();
        $pdo->prepare("INSERT INTO children (id, owner_user_id, first_name, last_name, status, version, created_at, updated_at) VALUES (:id, :user, 'Ани', 'Тестова', 'active', 1, NOW(), NOW())")
            ->execute(['id' => $child, 'user' => $user]);
        $application = (string) Str::uuid();
        $pdo->prepare("INSERT INTO enrollment_applications (id, organization_id, parent_user_id, child_id, group_id, price_option_id, scope, source, status, idempotency_key, accepted_terms_version, accepted_terms_at, amount_cents_snapshot, currency_snapshot, unit_snapshot, version, created_at, updated_at) VALUES (:id, :organization, :user, :child, :group, :price, 'full_period', 'staff', 'approved', :key, 'v1', NOW(), 4500, 'EUR', 'month', 1, NOW(), NOW())")
            ->execute(['id' => $application, 'organization' => $organization, 'user' => $user, 'child' => $child, 'group' => $group, 'price' => $price, 'key' => (string) Str::uuid()]);
        $enrollment = (string) Str::uuid();
        $pdo->prepare("INSERT INTO enrollments (id, organization_id, application_id, child_id, group_id, price_option_id, scope, status, starts_on, version, created_at, updated_at) VALUES (:id, :organization, :application, :child, :group, :price, 'full_period', 'active', '2026-11-12', 1, NOW(), NOW())")
            ->execute(['id' => $enrollment, 'organization' => $organization, 'application' => $application, 'child' => $child, 'group' => $group, 'price' => $price]);

        return ['organization' => $organization, 'user' => $user, 'actor' => $actor, 'session' => $session, 'draft_session' => $draftSession,
            'place' => $place, 'other_place' => $otherPlace, 'enrollment' => $enrollment];
    }

    private function insertGroup(\PDO $pdo, string $organization, string $offering, string $status): string
    {
        $id = (string) Str::uuid();
        $pdo->prepare('INSERT INTO activity_groups (id, organization_id, offering_id, name, capacity_min, status, created_at, updated_at) VALUES (:id, :organization, :offering, :name, 1, :status, NOW(), NOW())')
            ->execute(['id' => $id, 'organization' => $organization, 'offering' => $offering, 'name' => 'Група '.$status, 'status' => $status]);

        return $id;
    }

    private function insertPlace(\PDO $pdo, string $organization): string
    {
        $id = (string) Str::uuid();
        $pdo->prepare("INSERT INTO locations (id, organization_id, name, type, space_usage, created_at, updated_at) VALUES (:id, :organization, 'Зала', 'physical', 'exclusive', NOW(), NOW())")
            ->execute(['id' => $id, 'organization' => $organization]);

        return $id;
    }

    private function insertSession(\PDO $pdo, string $organization, string $group): string
    {
        $id = (string) Str::uuid();
        $pdo->prepare("INSERT INTO activity_sessions (id, organization_id, group_id, starts_at, ends_at, status, created_at, updated_at) VALUES (:id, :organization, :group, '2026-11-03 15:00:00+00', '2026-11-03 16:00:00+00', 'scheduled', NOW(), NOW())")
            ->execute(['id' => $id, 'organization' => $organization, 'group' => $group]);

        return $id;
    }

    /** @param  array{organization: string, actor: string}  $tenant */
    private function insertChange(\PDO $pdo, array $tenant, string $action, ?string $reason, ?string $key = null, int $count = 1): string
    {
        $id = (string) Str::uuid();
        $pdo->prepare('INSERT INTO schedule_changes (id, organization_id, action, reason, session_count, idempotency_key, request_hash, result, actor_user_id, created_at) '
            ."VALUES (:id, :organization, :action, :reason, :count, :key, :hash, '{}', :user, NOW())")
            ->execute(['id' => $id, 'organization' => $tenant['organization'], 'action' => $action, 'reason' => $reason, 'count' => $count,
                'key' => $key ?? (string) Str::uuid(), 'hash' => str_repeat('a', 64), 'user' => $tenant['actor']]);

        return $id;
    }

    /** @param  array{organization: string}  $tenant */
    private function insertSessionChange(\PDO $pdo, array $tenant, string $change, string $session, ?string $from, ?string $to): string
    {
        $id = (string) Str::uuid();
        $pdo->prepare('INSERT INTO session_changes (id, organization_id, schedule_change_id, session_id, from_status, to_status, from_starts_at, from_ends_at, to_starts_at, to_ends_at, from_location_id, to_location_id, confirmed_overlaps, created_at) '
            ."VALUES (:id, :organization, :change, :session, 'scheduled', 'cancelled', '2026-11-03 15:00:00+00', '2026-11-03 16:00:00+00', '2026-11-03 15:00:00+00', '2026-11-03 16:00:00+00', :from, :to, '[]', NOW())")
            ->execute(['id' => $id, 'organization' => $tenant['organization'], 'change' => $change, 'session' => $session, 'from' => $from, 'to' => $to]);

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
