<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Actions\ApplyScheduleChange;
use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\ValueObjects\ScheduleChangeRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

/**
 * Two requests with the same key for a repeat at the same time (docs/product/schedule-changes.md, т. 7.3): the second
 * waits for the lock of the group the first holds, then finds the first one's change and gets its answer — one change,
 * no 409 for the versions the first has moved on. Two PostgreSQL connections: this process and a worker.
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
            $activity = Activity::query()->create([
                'slug' => 'chess-'.Str::random(8), 'title' => 'Шах', 'category' => 'Наука', 'summary' => 'Описание', 'age_label' => '7+', 'status' => 'published',
            ]);
            $group = $activity->offerings()->create([])->groups()->create(['name' => 'Вторник', 'capacity_max' => 10, 'status' => 'published']);
            $session = $group->sessions()->create(['starts_at' => '2999-11-03T15:00:00Z', 'ends_at' => '2999-11-03T16:00:00Z', 'status' => 'scheduled']);
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
}
