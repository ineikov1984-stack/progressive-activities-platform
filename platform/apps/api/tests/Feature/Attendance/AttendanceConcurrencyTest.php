<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Actions\RecordAttendance;
use App\Domain\Attendance\Actions\RecordAttendanceSession;
use App\Domain\Catalog\Models\Session;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\VisitOfferFixture;
use Tests\TestCase;

#[Group('tenant-isolation')]
class AttendanceConcurrencyTest extends TestCase
{
    use VisitOfferFixture;

    private string $original;

    private array $fixture;

    private User $actor;

    private string $enrollment;

    private mixed $process = null;

    private array $pipes = [];

    protected function setUp(): void
    {
        parent::setUp();
        try {
            DB::connection('pgsql_admin')->getPdo();
        } catch (\Throwable) {
            $this->markTestSkipped('PostgreSQL is required for attendance concurrency.');
        }
        $this->original = DB::getDefaultConnection();
        Artisan::call('migrate', ['--database' => 'pgsql_admin', '--force' => true]);
        DB::setDefaultConnection('pgsql_admin');
        config(['database.connections.attendance_other' => config('database.connections.pgsql_admin'), 'platform.attendance_lock_timeout_ms' => 250]);
        $start = CarbonImmutable::now()->addMinutes(10);
        $this->fixture = $this->visitFixture(sessionAttributes: ['starts_at' => $start, 'ends_at' => $start->addHour()]);
        [$org, , $group, $session, , $child] = $this->fixture;
        $this->actor = User::factory()->create();
        OrganizationMembership::query()->create(['organization_id' => $org->id, 'user_id' => $this->actor->id, 'role' => 'organization_owner', 'status' => 'active']);
        $this->enrollment = $this->visitEnrollment($child, $group, null, 'full_period', startsOn: $start->setTimezone('Europe/Sofia')->toDateString())->id;
    }

    protected function tearDown(): void
    {
        if (isset($this->original)) {
            foreach (['pgsql_admin', 'attendance_other'] as $name) {
                $connection = DB::connection($name);
                while ($connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
            }
            if (is_resource($this->process)) {
                proc_terminate($this->process);
                foreach ($this->pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($this->process);
            }
            DB::connection('pgsql_admin')->statement('RESET ROLE');
            app(TenantContext::class)->clear();
            if (isset($this->fixture)) {
                DB::connection('pgsql_admin')->table('organizations')->where('id', $this->fixture[0]->id)->delete();
                DB::connection('pgsql_admin')->table('users')->whereIn('id', [$this->fixture[5]->owner_user_id, $this->actor->id])->delete();
            }
            DB::purge('attendance_other');
            DB::setDefaultConnection($this->original);
        }
        parent::tearDown();
    }

    public static function simultaneous(): array
    {
        return [['same', 'present', 'present', 200, 1], ['different', 'absent', 'present', 409, 0], ['all', 'all_present', 'absent', 200, 0]];
    }

    #[DataProvider('simultaneous')]
    public function test_simultaneous_marks_serialize_and_replay_without_duplicate_history(string $key, string $second, string $first, int $status, int $applied): void
    {
        $connection = DB::connection();
        $connection->beginTransaction();
        $connection->table('activity_sessions')->where('id', $this->fixture[3]->id)->lockForUpdate()->first();
        $pid = $this->worker($key, $second);
        $this->waitForLock($pid);
        $result = app(RecordAttendance::class)->handle($this->actor, $this->fixture[3], $this->body($first), $key === 'same' ? $key : 'first');
        $connection->commit();
        $answer = $this->answer();
        $this->assertSame(200, $result['status']);
        $this->assertSame($status, $answer['status']);
        $this->assertCount($applied, $answer['applied'] ?? []);
        if ($key === 'same') {
            $this->assertSame($result['applied'], $answer['applied']);
        }
        $this->assertSame(1, DB::table('attendance_marks')->where('organization_id', $this->fixture[0]->id)->count());
        $this->assertSame(1, DB::table('attendance_mark_changes')->where('organization_id', $this->fixture[0]->id)->count());
    }

    public function test_held_enrollment_times_out_without_partial_writes_and_the_same_key_can_retry(): void
    {
        $other = DB::connection('attendance_other');
        $other->beginTransaction();
        $other->table('enrollments')->where('id', $this->enrollment)->lockForUpdate()->first();
        DB::statement('SET ROLE progressive_app');
        DB::select("SELECT set_config('app.current_organization_id', ?, false)", [$this->fixture[0]->id]);
        $result = app(RecordAttendance::class)->handle($this->actor, $this->fixture[3], $this->body('present'), 'held');
        $this->assertSame(409, $result['status']);
        $this->assertTrue($result['retry']);
        $this->assertSame(0, DB::table('attendance_marks')->count());
        $this->assertSame(0, DB::table('attendance_mark_changes')->count());
        $other->rollBack();
        $answer = app(RecordAttendance::class)->handle($this->actor, $this->fixture[3], $this->body('present'), 'held');
        $this->assertSame(200, $answer['status']);
        $this->assertSame(1, DB::table('attendance_mark_changes')->count());
    }

    public static function boundaries(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('boundaries')]
    public function test_a_real_deadlock_rolls_back_the_mark_and_returns_retry_before_the_lock_timeout(bool $http): void
    {
        $connection = DB::connection();
        $connection->beginTransaction();
        $connection->statement("SET LOCAL deadlock_timeout = '10s'");
        $connection->table('enrollments')->where('id', $this->enrollment)->lockForUpdate()->first();
        $pid = $this->worker($http ? 'deadlock_http' : 'deadlock', 'present');
        $this->waitForLock($pid);
        $started = microtime(true);
        // The worker holds the session and waits for our enrollment: completing the opposite lock order makes a cycle.
        $connection->table('activity_sessions')->where('id', $this->fixture[3]->id)->lockForUpdate()->first();
        $connection->rollBack();
        $answer = $this->answer();
        $this->assertSame(409, $answer['status']);
        $this->assertTrue($answer['retry']);
        $this->assertLessThan(5, microtime(true) - $started, 'The worker must be the deadlock victim, not its 15-second lock timeout.');
        $this->assertSame(0, DB::table('attendance_marks')->where('organization_id', $this->fixture[0]->id)->count());
        $this->assertSame(0, DB::table('attendance_mark_changes')->where('organization_id', $this->fixture[0]->id)->count());
    }

    public function test_session_changes_commit_with_the_restricted_role_and_matching_deferred_history(): void
    {
        DB::statement('SET ROLE progressive_app');
        DB::select("SELECT set_config('app.current_organization_id', ?, false)", [$this->fixture[0]->id]);
        Auth::guard('web')->setUser($this->actor);
        $action = app(RecordAttendanceSession::class);
        foreach ([['action' => 'topic', 'topic' => 'Мат с топ'], ['action' => 'not_held', 'reason' => 'Затворена зала'], ['action' => 'held', 'reason' => 'Проведе се']] as $body) {
            $this->assertSame(200, $action->handle($this->actor, $this->fixture[3], $body)['status']);
        }
        $state = DB::table('attendance_sessions')->where('session_id', $this->fixture[3]->id)->first();
        $this->assertSame(3, $state->version);
        $this->assertSame('Мат с топ', $state->topic);
        $this->assertNull($state->not_held_at);
        $this->assertSame(['not_held', 'held'], DB::table('attendance_session_changes')->where('attendance_session_id', $state->id)->orderBy('version')->pluck('action')->all());
        $this->assertSame(2, DB::table('audit_events')->where('organization_id', $this->fixture[0]->id)->where('action', 'like', 'attendance.session_%')->count());
        $this->assertSame(1, DB::table('activity_sessions')->where('id', $this->fixture[3]->id)->value('version'));
    }

    private function body(string $status): array
    {
        return $status === 'all_present' ? ['action' => 'all_present'] : ['action' => 'mark', 'marks' => [['enrollment_id' => $this->enrollment, 'status' => $status, 'version' => 0]]];
    }

    private function worker(string $key, string $status): int
    {
        $script = <<<'WORKER'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        \Illuminate\Support\Facades\DB::setDefaultConnection('pgsql_admin');
        \Illuminate\Support\Facades\DB::statement("SET deadlock_timeout = '1s'");
        \Illuminate\Support\Facades\DB::statement('SET ROLE progressive_app');
        \Illuminate\Support\Facades\DB::select("SELECT set_config('app.current_organization_id', ?, false)", [$argv[1]]);
        app(\App\Domain\Organizations\Tenancy\TenantContext::class)->select($argv[1]);
        config(['platform.attendance_lock_timeout_ms' => str_starts_with($argv[5], 'deadlock') ? 15000 : 5000]);
        $pid = \Illuminate\Support\Facades\DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
        echo "READY:{$pid}\n"; flush();
        try {
            $actor = \App\Domain\Identity\Models\User::query()->findOrFail($argv[2]);
            $session = \App\Domain\Catalog\Models\Session::query()->findOrFail($argv[3]);
            $body = $argv[6] === 'all_present' ? ['action' => 'all_present'] : ['action' => 'mark', 'marks' => [['enrollment_id' => $argv[4], 'status' => $argv[6], 'version' => 0]]];
            if ($argv[5] === 'deadlock_http') {
                $app->instance('env', 'testing');
                config(['session.driver' => 'array', 'cache.default' => 'array']);
                \Illuminate\Support\Facades\Auth::guard('web')->setUser($actor);
                $request = \Illuminate\Http\Request::create('/api/v1/attendance/sessions/'.$session->id.'/changes', 'POST', [], [], [],
                    ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_X_ORGANIZATION_ID' => $argv[1], 'HTTP_IDEMPOTENCY_KEY' => $argv[5]], json_encode($body));
                $response = $app->make(\Illuminate\Contracts\Http\Kernel::class)->handle($request);
                $result = ['status' => $response->getStatusCode(), ...json_decode($response->getContent(), true)];
            } else {
            $result = app(\App\Domain\Attendance\Actions\RecordAttendance::class)->handle($actor, $session, $body, $argv[5]);
            }
            echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
        } catch (\Throwable $exception) { fwrite(STDERR, get_class($exception).': '.$exception->getMessage()); exit(1); }
        WORKER;
        $this->process = proc_open([PHP_BINARY, '-r', $script, $this->fixture[0]->id, $this->actor->id, $this->fixture[3]->id, $this->enrollment, $key, $status],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes, base_path());
        $this->assertIsResource($this->process);
        fclose($this->pipes[0]);
        stream_set_timeout($this->pipes[1], 20);
        $ready = trim((string) fgets($this->pipes[1]));
        $this->assertMatchesRegularExpression('/^READY:\d+$/', $ready);

        return (int) substr($ready, 6);
    }

    private function waitForLock(int $pid): void
    {
        for ($i = 0; $i < 150; $i++) {
            if (DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$pid])?->wait_event_type === 'Lock') {
                $this->assertTrue(true);

                return;
            }
            usleep(20000);
        }
        $this->fail('The worker never waited for the competing database lock.');
    }

    private function answer(): array
    {
        $output = stream_get_contents($this->pipes[1]);
        $errors = stream_get_contents($this->pipes[2]);
        fclose($this->pipes[1]);
        fclose($this->pipes[2]);
        $this->assertSame(0, proc_close($this->process), (string) $errors);
        $this->process = null;

        return json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);
    }
}
