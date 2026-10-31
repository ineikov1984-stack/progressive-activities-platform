<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Actions\ApplyScheduleChange;
use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\ValueObjects\ScheduleChangeRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Throwable;

/**
 * Changes at the same time, with two PostgreSQL connections (docs/product/schedule-changes.md, т. 4.1а, 7.3):
 * - two requests with the same key for a repeat: the second waits for the lock of the group the first holds, then finds
 *   the first one's change and gets its answer — one change, no 409 for the versions the first has moved on (this
 *   process and a worker);
 * - a group another change holds longer than the wait (`platform.schedule_lock_timeout_ms`): 409 and nothing written.
 */
class ScheduleChangeConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_requests_with_the_same_key_make_one_change_and_the_second_gets_the_first_answer(): void
    {
        try {
            DB::connection('pgsql_admin')->getPdo();
        } catch (Throwable) {
            $this->markTestSkipped('PostgreSQL is required for the two-connection check of the key for a repeat.');
        }

        Artisan::call('migrate', ['--database' => 'pgsql_admin', '--force' => true]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('pgsql_admin');
        $connection = DB::connection('pgsql_admin');
        $context = app(TenantContext::class);
        $process = null;
        $pipes = [];
        $organization = null;
        $owner = null;

        try {
            $organization = Organization::query()->withoutGlobalScopes()->create(['name' => 'Школа', 'slug' => 'school-'.Str::random(12), 'status' => 'active']);
            $owner = User::factory()->create(['name' => 'Синтетичен собственик']);
            $context->select($organization);
            [$group, $session] = $this->publishedSession();
            $request = new ScheduleChangeRequest('cancel', [['id' => (string) $session->id, 'version' => 1]], 'Ремонт', false, true, []);

            // The first request holds the group, as a change being applied does.
            $connection->beginTransaction();
            $connection->table('activity_groups')->where('id', $group->id)->lockForUpdate()->first();

            $worker = <<<'PHP'
                require 'vendor/autoload.php';
                $app = require 'bootstrap/app.php';
                $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                \Illuminate\Support\Facades\DB::setDefaultConnection('pgsql_admin');
                app(\App\Domain\Organizations\Tenancy\TenantContext::class)->select($argv[1]);
                $pid = \Illuminate\Support\Facades\DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
                echo "READY:{$pid}\n";
                flush();
                try {
                    $actor = \App\Domain\Identity\Models\User::query()->findOrFail($argv[2]);
                    $request = new \App\Domain\Catalog\ValueObjects\ScheduleChangeRequest('cancel', [['id' => $argv[3], 'version' => 1]], 'Ремонт', false, true, []);
                    $result = app(\App\Domain\Catalog\Actions\ApplyScheduleChange::class)->handle($actor, $request, 'same-key');
                    echo 'DONE:'.$result['status'].':'.$result['body']['change']['id']."\n";
                } catch (\Throwable $exception) {
                    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
                    exit(1);
                }
                PHP;
            $process = proc_open(
                [PHP_BINARY, '-r', $worker, (string) $organization->id, (string) $owner->id, (string) $session->id],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                base_path(),
            );
            $this->assertIsResource($process);
            fclose($pipes[0]);
            stream_set_timeout($pipes[1], 5);
            $ready = trim((string) fgets($pipes[1]));
            $this->assertMatchesRegularExpression('/^READY:\d+$/', $ready);
            $workerPid = (int) substr($ready, 6);

            $waiting = false;
            for ($attempt = 0; $attempt < 150; $attempt++) {
                $activity = $connection->selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$workerPid]);
                if ($activity?->wait_event_type === 'Lock') {
                    $waiting = true;
                    break;
                }
                usleep(20000);
            }
            $this->assertTrue($waiting, 'The second request did not wait for the lock of the group.');

            // The first goes on and is applied; the second, let go, finds it.
            $first = app(ApplyScheduleChange::class)->handle($owner, $request, 'same-key');
            $connection->commit();

            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), (string) $error);
            $process = null;
            $this->assertSame(201, $first['status']);
            $this->assertStringContainsString('DONE:200:'.$first['body']['change']['id'], (string) $output);
            $this->assertSame(1, $connection->table('schedule_changes')->where('organization_id', $organization->id)->count());
            $context->select($organization);
            $this->assertSame(['cancelled', 2], [Session::query()->findOrFail($session->id)->status, Session::query()->findOrFail($session->id)->version]);
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            if (is_resource($process)) {
                proc_terminate($process);
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($process);
            }
            $context->clear();
            if ($organization !== null) {
                $connection->table('organizations')->where('id', $organization->id)->delete();
            }
            if ($owner !== null) {
                $connection->table('users')->where('id', $owner->id)->delete();
            }
            DB::setDefaultConnection($original);
        }
    }

    public function test_a_group_held_longer_than_the_wait_answers_409_and_writes_nothing(): void
    {
        try {
            DB::connection('pgsql_admin')->getPdo();
        } catch (Throwable) {
            $this->markTestSkipped('PostgreSQL is required for the check of the wait for a held group.');
        }

        Artisan::call('migrate', ['--database' => 'pgsql_admin', '--force' => true]);
        // The other change on a connection of its own; a short wait, not the five seconds.
        config([
            'database.connections.pgsql_other_change' => config('database.connections.pgsql_admin'),
            'platform.schedule_lock_timeout_ms' => 200,
        ]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('pgsql_admin');
        $connection = DB::connection('pgsql_admin');
        $other = DB::connection('pgsql_other_change');
        $context = app(TenantContext::class);
        $organization = null;
        $owner = null;

        try {
            $organization = Organization::query()->withoutGlobalScopes()->create(['name' => 'Школа', 'slug' => 'school-'.Str::random(12), 'status' => 'active']);
            $owner = User::factory()->create(['name' => 'Синтетичен собственик']);
            $context->select($organization);
            [$group, $session] = $this->publishedSession();
            $request = new ScheduleChangeRequest('cancel', [['id' => (string) $session->id, 'version' => 1]], 'Ремонт', false, true, []);

            $other->beginTransaction();
            $other->table('activity_groups')->where('id', $group->id)->lockForUpdate()->first();

            $started = microtime(true);
            try {
                app(ApplyScheduleChange::class)->handle($owner, $request, 'held-group');
                $this->fail('A change must not wait for a held group longer than the setting.');
            } catch (HttpException $exception) {
                $this->assertSame(409, $exception->getStatusCode());
                $this->assertSame('Някой друг променя тези групи в момента. Опитайте отново след малко.', $exception->getMessage());
            }
            $this->assertLessThan(4.0, microtime(true) - $started, 'The wait is the setting, not the five seconds.');
            $this->assertSame(0, $connection->transactionLevel());
            foreach (['schedule_changes', 'session_changes', 'enrollment_events'] as $table) {
                $this->assertSame(0, $connection->table($table)->where('organization_id', $organization->id)->count(), $table);
            }
            $this->assertSame(0, $connection->table('audit_events')->where('organization_id', $organization->id)
                ->where('action', 'schedule.change_applied')->count());
            $row = $connection->table('activity_sessions')->where('id', $session->id)->first(['status', 'version', 'change_reason']);
            $this->assertSame(['scheduled', 1, null], [$row?->status, (int) $row?->version, $row?->change_reason]);

            // Once the other change lets go, the same request goes through: the 409 kept neither the key nor a part of it.
            $other->rollBack();
            $this->assertSame(201, app(ApplyScheduleChange::class)->handle($owner, $request, 'held-group')['status']);
            $this->assertSame(1, $connection->table('schedule_changes')->where('organization_id', $organization->id)->count());
        } finally {
            if ($other->transactionLevel() > 0) {
                $other->rollBack();
            }
            DB::purge('pgsql_other_change');
            $context->clear();
            if ($organization !== null) {
                $connection->table('organizations')->where('id', $organization->id)->delete();
            }
            if ($owner !== null) {
                $connection->table('users')->where('id', $owner->id)->delete();
            }
            DB::setDefaultConnection($original);
        }
    }

    /**
     * A published group with one session far ahead, in the selected organization; committed — the test deletes it.
     *
     * @return array{0: Group, 1: Session}
     */
    private function publishedSession(): array
    {
        $activity = Activity::query()->create([
            'slug' => 'chess-'.Str::random(8), 'title' => 'Шах', 'category' => 'Наука', 'summary' => 'Описание', 'age_label' => '7+', 'status' => 'published',
        ]);
        $group = $activity->offerings()->create([])->groups()->create(['name' => 'Вторник', 'capacity_max' => 10, 'status' => 'published']);
        $session = $group->sessions()->create(['starts_at' => '2999-11-03T15:00:00Z', 'ends_at' => '2999-11-03T16:00:00Z', 'status' => 'scheduled']);

        return [$group, $session];
    }
}
