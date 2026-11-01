<?php

namespace Tests\Feature\Attendance;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\VisitOfferFixture;
use Tests\TestCase;

#[Group('tenant-isolation')]
class AttendanceSchemaTest extends TestCase
{
    use RefreshDatabase, VisitOfferFixture;

    private \PDO $pdo;

    private string $originalConnection;

    private array $first;

    private array $second;

    private const TABLES = ['attendance_mark_changes', 'attendance_marks', 'attendance_session_changes', 'attendance_sessions'];

    protected function setUp(): void
    {
        parent::setUp();
        try {
            DB::connection('pgsql_admin')->getPdo();
        } catch (\Throwable) {
            $this->markTestSkipped('PostgreSQL is required for attendance database guarantees.');
        }
        Artisan::call('migrate', ['--database' => 'pgsql_admin', '--force' => true]);
        $this->originalConnection = DB::getDefaultConnection();
        DB::setDefaultConnection('pgsql_admin');
        $this->pdo = DB::connection()->getPdo();
        $this->pdo->beginTransaction();
        $this->assertSame(4, (int) $this->pdo->query("SELECT count(*) FROM pg_class WHERE relname IN ('attendance_marks','attendance_mark_changes','attendance_sessions','attendance_session_changes') AND relkind='r' AND relrowsecurity AND relforcerowsecurity")->fetchColumn());
        $this->first = $this->fixture();
        $this->second = $this->fixture();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if (isset($this->originalConnection)) {
            DB::setDefaultConnection($this->originalConnection);
        }
        parent::tearDown();
    }

    public function test_rls_and_column_privileges_keep_all_four_tables_in_the_selected_organization(): void
    {
        [$mark, $history, $session, $sessionHistory] = $this->complete($this->first);
        $this->complete($this->second);
        $this->pdo->exec('SET LOCAL ROLE progressive_app');
        $this->tenant('');
        foreach (self::TABLES as $table) {
            $this->assertSame(0, (int) $this->pdo->query("SELECT count(*) FROM {$table}")->fetchColumn());
        }
        $this->tenant($this->first['organization']);
        foreach (self::TABLES as $table) {
            $this->assertSame(1, (int) $this->pdo->query("SELECT count(*) FROM {$table}")->fetchColumn());
            $this->refused(fn () => $this->pdo->exec("DELETE FROM {$table}"), '42501');
        }
        $this->refused(fn () => $this->mark($this->second), '42501');
        $this->refused(fn () => $this->attendanceSession($this->second), '42501');
        $this->refused(fn () => $this->markChange($this->second, $mark, ['version' => 2]), '42501');
        $this->refused(fn () => $this->sessionChange($this->second, $session, ['version' => 2]), '42501');
        $this->refused(fn () => $this->pdo->exec("UPDATE attendance_marks SET session_id = session_id WHERE id = '{$mark}'"), '42501');
        $this->refused(fn () => $this->pdo->exec("UPDATE attendance_sessions SET organization_id = organization_id WHERE id = '{$session}'"), '42501');
        foreach ([['attendance_mark_changes', $history], ['attendance_session_changes', $sessionHistory]] as [$table, $id]) {
            $this->refused(fn () => $this->pdo->exec("UPDATE {$table} SET version = 2 WHERE id = '{$id}'"), '42501');
        }
        DB::table('attendance_marks')->where('id', $mark)->update(['status' => 'absent', 'version' => 2, 'marked_by' => $this->first['actor'], 'marked_at' => now(), 'updated_at' => now()]);
        $this->markChange($this->first, $mark, ['from_status' => 'present', 'to_status' => 'absent', 'version' => 2]);
        DB::table('attendance_sessions')->where('id', $session)->update(['topic' => 'Шахматна тема', 'version' => 2, 'updated_at' => now()]);
        $this->pdo->exec('RESET ROLE');
        // Constraint validation is independent of whichever tenant is selected at commit.
        $this->tenant($this->second['organization']);
        $this->pdo->exec('SET CONSTRAINTS ALL IMMEDIATE');
        $this->assertSame('absent', DB::table('attendance_marks')->where('id', $mark)->value('status'));
    }

    public function test_owner_cannot_mutate_identity_versions_or_immutable_history(): void
    {
        [$mark, $history, $session, $sessionHistory] = $this->complete($this->first);
        foreach ([['attendance_marks', $mark], ['attendance_mark_changes', $history], ['attendance_sessions', $session], ['attendance_session_changes', $sessionHistory]] as [$table, $id]) {
            $this->refused(fn () => $this->pdo->exec("DELETE FROM {$table} WHERE id = '{$id}'"), 'P0001');
        }
        foreach (['id', 'organization_id', 'session_id', 'enrollment_id', 'created_at'] as $column) {
            $value = $column === 'created_at' ? now()->subDay()->toDateTimeString() : (string) Str::uuid();
            $this->refused(fn () => DB::table('attendance_marks')->where('id', $mark)->update([$column => $value, 'version' => 2]), 'P0001');
        }
        foreach (['id', 'organization_id', 'session_id', 'created_at'] as $column) {
            $value = $column === 'created_at' ? now()->subDay()->toDateTimeString() : (string) Str::uuid();
            $this->refused(fn () => DB::table('attendance_sessions')->where('id', $session)->update([$column => $value, 'version' => 2]), 'P0001');
        }
        foreach (['attendance_marks' => $mark, 'attendance_sessions' => $session] as $table => $id) {
            $this->refused(fn () => DB::table($table)->where('id', $id)->update(['version' => 1]), 'P0001');
            $this->refused(fn () => DB::table($table)->where('id', $id)->update(['version' => 3]), 'P0001');
        }
        $this->refused(fn () => DB::table('attendance_mark_changes')->where('id', $history)->update(['to_status' => 'absent']), 'P0001');
        $this->refused(fn () => DB::table('attendance_session_changes')->where('id', $sessionHistory)->update(['reason' => 'Друга']), 'P0001');
    }

    public function test_checks_composite_foreign_keys_uniqueness_and_required_index(): void
    {
        $f = $this->first;
        foreach ([['status' => 'unknown'], ['version' => 0]] as $change) {
            $this->refused(fn () => $this->mark($f, $change), '23514');
        }
        $this->refused(fn () => $this->mark($f, ['session_id' => $this->second['session']]), '23503');
        $this->refused(fn () => $this->mark($f, ['enrollment_id' => $this->second['enrollment']]), '23503');
        $mark = $this->mark($f);
        $this->refused(fn () => $this->mark($f), '23505');
        foreach ([['kind' => 'wrong'], ['kind' => 'late'], ['reason' => 'Ненужна'], ['kind' => 'late', 'reason' => '  '],
            ['kind' => 'late', 'reason' => str_repeat('а', 501)], ['from_status' => 'present'], ['to_status' => 'wrong'],
            ['request_key' => ''], ['request_hash' => 'bad'], ['request_hash' => str_repeat('A', 64)], ['version' => 0]] as $change) {
            $this->refused(fn () => $this->markChange($f, $mark, $change), '23514');
        }
        $this->refused(fn () => $this->markChange($f, $mark, ['request_key' => str_repeat('a', 121)]), '22001');
        $this->markChange($f, $mark);
        $this->refused(fn () => $this->markChange($f, $mark), '23505');
        $this->refused(fn () => $this->markChange($this->second, $mark, ['version' => 2]), '23503');
        foreach ([['topic' => ''], ['topic' => str_repeat('а', 201)], ['version' => 0], ['not_held_at' => now()],
            ['not_held_at' => now(), 'not_held_by' => $f['actor'], 'not_held_reason' => '  ']] as $change) {
            $this->refused(fn () => $this->attendanceSession($f, $change), '23514');
        }
        $this->refused(fn () => $this->attendanceSession($f, ['session_id' => $this->second['session']]), '23503');
        $session = $this->attendanceSession($f);
        foreach ([['action' => 'wrong'], ['reason' => ''], ['reason' => str_repeat('а', 501)], ['version' => 0]] as $change) {
            $this->refused(fn () => $this->sessionChange($f, $session, $change), '23514');
        }
        $this->refused(fn () => $this->sessionChange($this->second, $session), '23503');
        $this->sessionChange($f, $session);
        $this->refused(fn () => $this->sessionChange($f, $session), '23505');
        $this->assertGreaterThan(0, (int) $this->pdo->query("SELECT count(*) FROM pg_indexes WHERE tablename='attendance_marks' AND indexdef LIKE '%(enrollment_id)%'")->fetchColumn());
    }

    public static function missingHistory(): array
    {
        return [['missing'], ['wrong_status'], ['session_missing'], ['changed_context']];
    }

    #[DataProvider('missingHistory')]
    public function test_history_is_required_at_actual_commit_even_when_the_tenant_context_changes(string $case): void
    {
        if ($case === 'session_missing') {
            $id = $this->attendanceSession($this->first, ['not_held_at' => now(), 'not_held_by' => $this->first['actor'], 'not_held_reason' => 'Не се проведе']);
        } else {
            if ($case === 'changed_context') {
                $this->pdo->exec('SET LOCAL ROLE progressive_app');
                $this->tenant($this->first['organization']);
            }
            $id = $this->mark($this->first);
            if ($case === 'wrong_status') {
                $this->markChange($this->first, $id, ['to_status' => 'absent']);
            }
            if ($case === 'changed_context') {
                $this->tenant($this->second['organization']);
            }
        }
        try {
            $this->pdo->commit();
            $this->fail('Commit without the matching history must fail.');
        } catch (\PDOException $exception) {
            $this->assertSame('P0001', $exception->getCode());
        }
        $this->pdo->exec('RESET ROLE');
        $table = $case === 'session_missing' ? 'attendance_sessions' : 'attendance_marks';
        $this->assertSame(0, (int) DB::table($table)->where('id', $id)->count());
    }

    public function test_cascades_keep_history_with_its_enrollment_or_organization_and_protect_sessions_and_actors(): void
    {
        [$mark] = $this->complete($this->first);
        $this->complete($this->second);
        $f = $this->first;
        $this->refused(fn () => DB::table('activity_sessions')->where('id', $f['session'])->delete(), '23503');
        $this->refused(fn () => DB::table('users')->where('id', $f['actor'])->delete(), '23503');
        $this->assertSame(1, DB::table('enrollments')->where('id', $f['enrollment'])->delete());
        $this->assertSame(0, DB::table('attendance_marks')->where('id', $mark)->count());
        $this->assertSame(0, DB::table('attendance_mark_changes')->where('mark_id', $mark)->count());
        $this->assertSame(1, DB::table('organizations')->where('id', $f['organization'])->delete());
        $this->pdo->exec('SET CONSTRAINTS ALL IMMEDIATE');
        foreach (self::TABLES as $table) {
            $this->assertSame(0, DB::table($table)->where('organization_id', $f['organization'])->count());
            $this->assertSame(1, DB::table($table)->where('organization_id', $this->second['organization'])->count());
        }
        $draft = DB::table('activity_sessions')->where('id', $this->second['session'])->first();
        $new = (array) $draft;
        $new['id'] = (string) Str::uuid();
        DB::table('activity_sessions')->insert($new);
        $this->assertSame(1, DB::table('activity_sessions')->where('id', $new['id'])->delete());
    }

    public function test_started_skips_cannot_be_added_changed_or_removed_but_future_skips_and_cascades_work(): void
    {
        $f = $this->first;
        $future = $this->exception($f);
        DB::table('enrollment_session_exceptions')->where('id', $future)->update(['reason' => 'Друга']);
        $this->assertSame(1, DB::table('enrollment_session_exceptions')->where('id', $future)->delete());
        $past = $this->exception($f);
        DB::table('activity_sessions')->where('id', $f['session'])->update(['starts_at' => '2000-01-01T10:00:00Z', 'ends_at' => '2000-01-01T11:00:00Z']);
        $this->refused(fn () => $this->exception($f, ['enrollment_id' => $this->second['enrollment']]), 'P0001');
        $this->refused(fn () => DB::table('enrollment_session_exceptions')->where('id', $past)->update(['reason' => 'Друга']), 'P0001');
        $this->refused(fn () => DB::table('enrollment_session_exceptions')->where('id', $past)->update(['session_id' => $this->second['session']]), 'P0001');
        $this->refused(fn () => DB::table('enrollment_session_exceptions')->where('id', $past)->delete(), 'P0001');
        $this->pdo->exec('SET LOCAL ROLE progressive_app');
        $this->tenant($f['organization']);
        $this->refused(fn () => DB::table('enrollment_session_exceptions')->where('id', $past)->update(['reason' => 'Друга']), 'P0001');
        $this->refused(fn () => DB::table('enrollment_session_exceptions')->where('id', $past)->delete(), 'P0001');
        $this->pdo->exec('RESET ROLE');
        $this->assertSame(1, DB::table('enrollments')->where('id', $f['enrollment'])->delete());
        $this->assertSame(0, DB::table('enrollment_session_exceptions')->where('id', $past)->count());
    }

    public function test_postgresql_empty_rollback_recreates_guards_and_populated_rollback_is_refused(): void
    {
        $migration = require database_path('migrations/2026_11_01_075042_create_attendance_tables.php');
        $migration->down();
        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        $migration->up();
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $this->complete($this->first);
        try {
            $migration->down();
            $this->fail('Populated PostgreSQL attendance must be preserved.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Attendance data', $exception->getMessage());
        }
        $this->pdo->exec('SET LOCAL ROLE progressive_app');
        try {
            $migration->down();
            $this->fail('The application role cannot migrate.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('database owner', $exception->getMessage());
        }
    }

    public function test_every_update_requires_matching_history_including_clearing_marks_and_returning_to_held(): void
    {
        [$mark, , $session] = $this->complete($this->first);
        $this->pdo->exec('SET CONSTRAINTS ALL IMMEDIATE');
        $this->pdo->exec('SET CONSTRAINTS ALL DEFERRED');
        DB::table('attendance_marks')->where('id', $mark)->update(['status' => null, 'version' => 2]);
        $this->refused(fn () => $this->pdo->exec('SET CONSTRAINTS ALL IMMEDIATE'), 'P0001');
        $this->markChange($this->first, $mark, ['from_status' => 'present', 'to_status' => null, 'version' => 2]);
        DB::table('attendance_sessions')->where('id', $session)->update(['not_held_at' => null, 'not_held_by' => null, 'not_held_reason' => null, 'version' => 2]);
        $this->refused(fn () => $this->pdo->exec('SET CONSTRAINTS ALL IMMEDIATE'), 'P0001');
        $this->sessionChange($this->first, $session, ['action' => 'held', 'version' => 2]);
        $this->pdo->exec('SET CONSTRAINTS ALL IMMEDIATE');
        $this->assertNull(DB::table('attendance_marks')->where('id', $mark)->value('status'));
    }

    private function fixture(): array
    {
        [$organization, , $group, $session, , $child] = $this->visitFixture([], [], ['starts_at' => '2099-11-03T15:00:00Z', 'ends_at' => '2099-11-03T16:00:00Z']);
        $enrollment = $this->visitEnrollment($child, $group, null, 'full_period', startsOn: '2099-11-01');

        return ['organization' => $organization->id, 'session' => $session->id, 'enrollment' => $enrollment->id, 'actor' => User::factory()->create()->id];
    }

    private function complete(array $f): array
    {
        $mark = $this->mark($f);
        $history = $this->markChange($f, $mark);
        $session = $this->attendanceSession($f, ['not_held_at' => now(), 'not_held_by' => $f['actor'], 'not_held_reason' => 'Не се проведе']);

        return [$mark, $history, $session, $this->sessionChange($f, $session)];
    }

    private function mark(array $f, array $overrides = []): string
    {
        return $this->insert('attendance_marks', ['organization_id' => $f['organization'], 'session_id' => $f['session'], 'enrollment_id' => $f['enrollment'],
            'status' => 'present', 'version' => 1, 'marked_by' => $f['actor'], 'marked_at' => now(), 'created_at' => now(), 'updated_at' => now(), ...$overrides]);
    }

    private function markChange(array $f, string $mark, array $overrides = []): string
    {
        return $this->insert('attendance_mark_changes', ['organization_id' => $f['organization'], 'mark_id' => $mark, 'from_status' => null, 'to_status' => 'present',
            'version' => 1, 'kind' => 'mark', 'reason' => null, 'actor_user_id' => $f['actor'], 'request_key' => 'key', 'request_hash' => str_repeat('a', 64), 'created_at' => now(), ...$overrides]);
    }

    private function attendanceSession(array $f, array $overrides = []): string
    {
        return $this->insert('attendance_sessions', ['organization_id' => $f['organization'], 'session_id' => $f['session'], 'topic' => null,
            'not_held_at' => null, 'not_held_by' => null, 'not_held_reason' => null, 'version' => 1, 'created_at' => now(), 'updated_at' => now(), ...$overrides]);
    }

    private function sessionChange(array $f, string $session, array $overrides = []): string
    {
        return $this->insert('attendance_session_changes', ['organization_id' => $f['organization'], 'attendance_session_id' => $session, 'action' => 'not_held',
            'reason' => 'Не се проведе', 'actor_user_id' => $f['actor'], 'version' => 1, 'created_at' => now(), ...$overrides]);
    }

    private function exception(array $f, array $overrides = []): string
    {
        return $this->insert('enrollment_session_exceptions', ['organization_id' => $f['organization'], 'enrollment_id' => $f['enrollment'], 'session_id' => $f['session'],
            'kind' => 'excluded', 'created_by' => $f['actor'], 'created_at' => now(), 'updated_at' => now(), ...$overrides]);
    }

    private function insert(string $table, array $values): string
    {
        $id = (string) Str::uuid();
        DB::table($table)->insert(['id' => $id, ...$values]);

        return $id;
    }

    private function tenant(string $organization): void
    {
        $this->pdo->prepare("SELECT set_config('app.current_organization_id', ?, true)")->execute([$organization]);
    }

    private function refused(callable $statement, string $code): void
    {
        $this->pdo->exec('SAVEPOINT attendance_check');
        try {
            $statement();
            $this->fail('The database accepted a forbidden attendance write.');
        } catch (\PDOException $exception) {
            $this->assertSame($code, (string) $exception->getCode(), $exception->getMessage());
        } finally {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT attendance_check');
            $this->pdo->exec('RELEASE SAVEPOINT attendance_check');
        }
    }
}
