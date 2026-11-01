<?php

namespace Tests\Feature\Substitute;

use App\Domain\Catalog\Models\InstructorProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\VisitOfferFixture;
use Tests\TestCase;

#[Group('tenant-isolation')]
class SubstituteSchemaTest extends TestCase
{
    use RefreshDatabase, VisitOfferFixture;

    private \PDO $pdo;

    private string $originalConnection;

    private array $first;

    private array $second;

    private const TABLES = ['substitute_requests', 'substitute_request_sessions'];

    protected function setUp(): void
    {
        parent::setUp();
        try {
            DB::connection('pgsql_admin')->getPdo();
        } catch (\Throwable) {
            $this->markTestSkipped('PostgreSQL is required for substitute database guarantees.');
        }
        Artisan::call('migrate', ['--database' => 'pgsql_admin', '--force' => true]);
        $this->originalConnection = DB::getDefaultConnection();
        DB::setDefaultConnection('pgsql_admin');
        $this->pdo = DB::connection()->getPdo();
        $this->pdo->beginTransaction();
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

    public function test_rls_and_privileges_deny_unselected_and_foreign_organizations(): void
    {
        $this->complete($this->first);
        $this->complete($this->second);
        $this->assertSame(2, (int) $this->pdo->query("SELECT count(*) FROM pg_class WHERE relname IN ('substitute_requests', 'substitute_request_sessions') AND relkind = 'r' AND relrowsecurity AND relforcerowsecurity")->fetchColumn());
        $this->pdo->exec('SET LOCAL ROLE progressive_app');
        $this->tenant('');
        foreach (self::TABLES as $table) {
            $this->assertSame(0, (int) DB::table($table)->count());
        }
        $this->tenant($this->first['organization']);
        foreach (self::TABLES as $table) {
            $this->assertSame(1, (int) DB::table($table)->count());
            $this->refused(fn () => DB::table($table)->delete(), '42501');
            $this->assertSame(0, DB::table($table)->where('organization_id', $this->second['organization'])->update(['updated_at' => now()]));
        }
        $this->refused(fn () => $this->request($this->second), '42501');
        $request = $this->request($this->first);
        $this->refused(fn () => $this->item($this->second, $request), '42501');
        $this->refused(fn () => DB::table('substitute_requests')->where('id', $request)->update(['organization_id' => $this->second['organization']]), 'P0001');
        $this->refused(fn () => $this->request($this->first, ['requester_profile_id' => $this->second['profile']]), '23503');
        $this->refused(fn () => $this->item($this->first, $request, ['session_id' => $this->second['session']]), '23503');
    }

    public function test_checks_composite_foreign_keys_and_partial_uniqueness(): void
    {
        $f = $this->first;
        foreach ([['reason' => 'medical'], ['note' => ''], ['note' => str_repeat('а', 301)], ['suggested_profile_id' => $f['profile']], ['version' => 0], ['idempotency_key' => ''], ['request_hash' => 'bad']] as $change) {
            $this->refused(fn () => $this->request($f, $change), '23514');
        }
        foreach (['requester_profile_id', 'suggested_profile_id'] as $column) {
            $this->refused(fn () => $this->request($f, [$column => $this->second['profile']]), '23503');
        }
        $request = $this->request($f, ['idempotency_key' => 'same']);
        $this->refused(fn () => $this->request($f, ['idempotency_key' => 'same']), '23505');
        foreach ([['status' => 'unknown'], ['closed_reason' => 'started'], ['status' => 'expired', 'closed_at' => now()], ['status' => 'expired', 'closed_reason' => 'invalid', 'closed_at' => now()], ['status' => 'assigned', 'closed_at' => now()], ['status' => 'declined', 'closed_at' => now()], ['status' => 'withdrawn'], ['closed_at' => now()], ['substitute_profile_id' => $f['other_profile']], ['decided_by_user_id' => $f['actor']]] as $change) {
            $this->refused(fn () => $this->item($f, $request, $change), '23514');
        }
        foreach (['session_id' => 'session', 'requester_profile_id' => 'profile'] as $column => $key) {
            $this->refused(fn () => $this->item($f, $request, [$column => $this->second[$key]]), '23503');
        }
        $this->refused(fn () => $this->item($f, $request, ['requester_profile_id' => $f['other_profile']]), '23503');
        $this->item($f, $request);
        $this->refused(fn () => $this->item($f, $request), '23505');
        $another = $this->request($f);
        $this->refused(fn () => $this->item($f, $another), '23505');
        $assistant = $this->request($f, ['requester_profile_id' => $f['other_profile']]);
        $this->item($f, $assistant, ['requester_profile_id' => $f['other_profile']]);
        $closed = $this->item($f, $another, ['status' => 'withdrawn', 'closed_at' => now()]);
        $this->assertNotEmpty($closed);
        $this->assertSame(3, (int) $this->pdo->query("SELECT count(*) FROM pg_indexes WHERE indexname IN ('substitute_requests_open_created', 'substitute_request_sessions_open_unique', 'substitute_request_sessions_open_session') AND indexdef LIKE '%WHERE%'")->fetchColumn());
        $change = $this->scheduleChange($f);
        foreach (['substitute_profile_id' => $this->second['profile'], 'schedule_change_id' => $this->scheduleChange($this->second)] as $column => $value) {
            $this->refused(fn () => $this->item($f, $this->request($f), ['status' => 'assigned', 'closed_at' => now(), 'decided_by_user_id' => $f['actor'], 'schedule_change_id' => $change, $column => $value]), '23503');
        }
        // Assigned without a substitute explicitly records "Без заместник".
        $this->item($f, $this->request($f), ['status' => 'assigned', 'closed_at' => now(), 'decided_by_user_id' => $f['actor'], 'schedule_change_id' => $change]);
    }

    public function test_owner_and_application_update_guards_preserve_history(): void
    {
        [$request, $item] = $this->complete($this->first);
        foreach (['owner', 'app'] as $role) {
            if ($role === 'app') {
                $this->pdo->exec('SET LOCAL ROLE progressive_app');
                $this->tenant($this->first['organization']);
            }
            foreach (['reason' => 'other', 'note' => 'Changed', 'requester_profile_id' => $this->first['other_profile'], 'idempotency_key' => 'changed', 'request_hash' => str_repeat('b', 64), 'created_at' => now()->subDay()] as $column => $value) {
                $this->refused(fn () => DB::table('substitute_requests')->where('id', $request)->update([$column => $value]), 'P0001');
            }
            foreach (['request_id' => (string) Str::uuid(), 'requester_profile_id' => $this->first['other_profile'], 'session_id' => $this->second['session'], 'created_at' => now()->subDay()] as $column => $value) {
                $this->refused(fn () => DB::table('substitute_request_sessions')->where('id', $item)->update([$column => $value]), 'P0001');
            }
        }
        DB::table('substitute_requests')->where('id', $request)->update(['version' => 2, 'updated_at' => now()]);
        DB::table('substitute_request_sessions')->where('id', $item)->update(['status' => 'expired', 'closed_reason' => 'started', 'closed_at' => now()]);
        $this->refused(fn () => DB::table('substitute_request_sessions')->where('id', $item)->update(['updated_at' => now()]), 'P0001');
        DB::table('substitute_requests')->where('id', $request)->update(['closed_at' => now(), 'version' => 3]);
        $this->refused(fn () => DB::table('substitute_requests')->where('id', $request)->update(['closed_at' => null]), 'P0001');
        $this->refused(fn () => DB::table('substitute_requests')->where('id', $request)->update(['closed_at' => now()->addDay()]), 'P0001');
    }

    public function test_all_terminal_states_can_be_recorded_once_by_the_application_role(): void
    {
        $f = $this->first;
        $change = $this->scheduleChange($f);
        $this->pdo->exec('SET LOCAL ROLE progressive_app');
        $this->tenant($f['organization']);
        $transitions = [
            ['status' => 'assigned', 'schedule_change_id' => $change, 'substitute_profile_id' => $f['other_profile'], 'decided_by_user_id' => $f['actor']],
            ['status' => 'assigned', 'schedule_change_id' => $change, 'decided_by_user_id' => $f['actor']],
            ['status' => 'declined', 'decided_by_user_id' => $f['actor']],
            ['status' => 'withdrawn'],
        ];
        foreach (['started', 'cancelled', 'moved', 'not_teaching', 'archived'] as $reason) {
            $transitions[] = ['status' => 'expired', 'closed_reason' => $reason];
        }
        foreach ($transitions as $transition) {
            [$request, $item] = $this->complete($f);
            $this->assertSame(1, DB::table('substitute_request_sessions')->where('id', $item)->update([...$transition, 'closed_at' => now(), 'updated_at' => now()]));
            $this->assertSame(1, DB::table('substitute_requests')->where('id', $request)->update(['closed_at' => now(), 'version' => 2]));
            $this->refused(fn () => DB::table('substitute_request_sessions')->where('id', $item)->update(['status' => 'open', 'closed_at' => null]), 'P0001');
        }
    }

    public function test_owner_deletion_is_blocked_but_organization_cascades_and_actor_session_restrictions_work(): void
    {
        $this->complete($this->first);
        $this->complete($this->second);
        foreach (self::TABLES as $table) {
            $this->refused(fn () => DB::table($table)->where('organization_id', $this->first['organization'])->delete(), 'P0001');
        }
        $this->refused(fn () => DB::table('users')->where('id', $this->first['actor'])->delete(), '23503');
        $this->refused(fn () => DB::table('activity_sessions')->where('id', $this->first['session'])->delete(), '23503');
        $this->refused(fn () => DB::table('instructor_profiles')->where('id', $this->first['profile'])->delete(), '23503');
        $this->assertSame(1, DB::table('organizations')->where('id', $this->first['organization'])->delete());
        foreach (self::TABLES as $table) {
            $this->assertSame(0, DB::table($table)->where('organization_id', $this->first['organization'])->count());
            $this->assertSame(1, DB::table($table)->where('organization_id', $this->second['organization'])->count());
        }
    }

    public function test_session_replacement_and_history_columns_preserve_tenant_and_check_rules(): void
    {
        $f = $this->first;
        $row = ['organization_id' => $f['organization'], 'session_id' => $f['session'], 'instructor_profile_id' => $f['other_profile'], 'role' => 'assistant', 'replaces_instructor_profile_id' => $f['profile'], 'created_at' => now(), 'updated_at' => now()];
        DB::table('activity_session_instructors')->insert($row);
        $this->refused(fn () => DB::table('activity_session_instructors')->where('session_id', $f['session'])->update(['replaces_instructor_profile_id' => $f['other_profile']]), '23514');
        $this->refused(fn () => DB::table('activity_session_instructors')->where('session_id', $f['session'])->update(['replaces_instructor_profile_id' => $this->second['profile']]), '23503');
        $change = $this->scheduleChange($f);
        $session = DB::table('activity_sessions')->where('id', $f['session'])->first();
        $history = ['organization_id' => $f['organization'], 'schedule_change_id' => $change, 'session_id' => $f['session'], 'from_status' => 'scheduled', 'to_status' => 'scheduled', 'from_starts_at' => $session->starts_at, 'from_ends_at' => $session->ends_at, 'to_starts_at' => $session->starts_at, 'to_ends_at' => $session->ends_at, 'confirmed_overlaps' => '[]', 'created_at' => now()];
        foreach ([['added_profile_id' => $f['profile']], ['removed_profile_id' => $f['profile']], ['replaced_profile_id' => $f['profile']]] as $overrides) {
            $this->refused(fn () => $this->insert('session_changes', [...$history, ...$overrides]), '23514');
        }
        foreach (['replaced_profile_id', 'removed_profile_id', 'added_profile_id'] as $column) {
            $this->refused(fn () => $this->insert('session_changes', [...$history, 'replaced_profile_id' => $f['profile'], 'removed_profile_id' => $f['profile'], $column => $this->second['profile']]), '23503');
        }
        $id = $this->insert('session_changes', [...$history, 'replaced_profile_id' => $f['profile'], 'added_profile_id' => $f['other_profile']]);
        $this->refused(fn () => DB::table('session_changes')->where('id', $id)->update(['added_profile_id' => $f['profile']]), 'P0001');
        $this->refused(fn () => $this->scheduleChange($f, ['action' => 'move']), '23514');
    }

    public function test_postgresql_empty_rollback_recreates_guards_and_each_data_source_refuses_rollback(): void
    {
        $migration = require database_path('migrations/2026_11_01_115259_create_substitute_requests.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('substitute_requests'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('substitute_request_sessions'));
        foreach (['requests', 'assignment', 'schedule'] as $kind) {
            $this->pdo->exec('SAVEPOINT populated_substitute');
            if ($kind === 'requests') {
                $this->complete($this->first);
            } elseif ($kind === 'schedule') {
                $this->scheduleChange($this->first);
            } else {
                DB::table('activity_session_instructors')->insert(['organization_id' => $this->first['organization'], 'session_id' => $this->first['session'], 'instructor_profile_id' => $this->first['other_profile'], 'role' => 'lead', 'replaces_instructor_profile_id' => $this->first['profile'], 'created_at' => now(), 'updated_at' => now()]);
            }
            try {
                $migration->down();
                $this->fail('Populated substitute rollback must fail.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('Substitute data', $exception->getMessage());
            }
            $this->pdo->exec('ROLLBACK TO SAVEPOINT populated_substitute');
            $this->pdo->exec('RELEASE SAVEPOINT populated_substitute');
        }
        $this->pdo->exec('SET LOCAL ROLE progressive_app');
        try {
            $migration->down();
            $this->fail('App role cannot migrate.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('database owner', $exception->getMessage());
        }
    }

    private function fixture(): array
    {
        [$organization, , , $session, , $child] = $this->visitFixture();
        $profile = InstructorProfile::create(['public_name' => 'Teacher']);
        $other = InstructorProfile::create(['public_name' => 'Assistant']);

        return ['organization' => $organization->id, 'session' => $session->id, 'actor' => $child->owner_user_id, 'profile' => $profile->id, 'other_profile' => $other->id];
    }

    private function complete(array $f): array
    {
        $request = $this->request($f);

        return [$request, $this->item($f, $request)];
    }

    private function request(array $f, array $overrides = []): string
    {
        return $this->insert('substitute_requests', ['organization_id' => $f['organization'], 'requester_user_id' => $f['actor'], 'requester_profile_id' => $f['profile'], 'reason' => 'personal', 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now(), ...$overrides]);
    }

    private function item(array $f, string $request, array $overrides = []): string
    {
        return $this->insert('substitute_request_sessions', ['organization_id' => $f['organization'], 'request_id' => $request, 'requester_profile_id' => $f['profile'], 'session_id' => $f['session'], 'status' => 'open', 'created_at' => now(), 'updated_at' => now(), ...$overrides]);
    }

    private function scheduleChange(array $f, array $overrides = []): string
    {
        return $this->insert('schedule_changes', ['organization_id' => $f['organization'], 'actor_user_id' => $f['actor'], 'action' => 'substitute', 'reason' => null, 'session_count' => 1, 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'result' => '{}', 'created_at' => now(), ...$overrides]);
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
        $this->pdo->exec('SAVEPOINT substitute_check');
        try {
            $statement();
            $this->fail('The database accepted a forbidden substitute write.');
        } catch (\PDOException $exception) {
            $this->assertSame($code, (string) $exception->getCode(), $exception->getMessage());
        } finally {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT substitute_check');
            $this->pdo->exec('RELEASE SAVEPOINT substitute_check');
        }
    }
}
