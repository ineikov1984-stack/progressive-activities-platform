<?php

namespace Tests\Feature\Enrollment;

use App\Domain\Catalog\Models\VisitOffer;
use App\Domain\Catalog\Services\VisitOfferAvailabilityService;
use App\Domain\Enrollment\Services\EnrollmentCapacity;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\VisitOfferFixture;
use Tests\TestCase;
use Throwable;

class VisitOfferCapacityTest extends TestCase
{
    use RefreshDatabase, VisitOfferFixture;

    public function test_session_capacity_is_the_hard_ceiling_even_when_the_group_is_larger(): void
    {
        [, , $group, $session, $offer, $child] = $this->visitFixture(
            ['visit_limit' => 4], ['capacity_max' => 12], ['capacity' => 2],
        );
        $this->visitEnrollment($child, $group, null, 'full_period', startsOn: '2026-11-01');

        $capacity = app(EnrollmentCapacity::class);
        $this->assertSame(1, $capacity->remainingForSession($session));
        $this->assertSame(1, $capacity->remainingForOffer($offer, $session));

        $other = $child->owner->children()->create(['first_name' => 'Ben', 'last_name' => 'Ivanov']);
        $this->visitEnrollment($other, $group, $session, 'single');
        $this->assertSame(0, $capacity->remainingForSession($session));
        $this->assertSame(0, $capacity->remainingForOffer($offer, $session));
    }

    public function test_future_lower_session_capacity_also_limits_full_period_places(): void
    {
        $this->travelTo('2026-10-28T10:00:00Z');
        [, , $group, $session, , $child] = $this->visitFixture(
            [], ['capacity_max' => 12], ['capacity' => 1],
        );
        $this->visitEnrollment($child, $group, null, 'full_period', startsOn: '2026-11-01');

        $this->assertSame(0, app(EnrollmentCapacity::class)->remainingForSession($session));
        $this->assertSame(0, app(EnrollmentCapacity::class)->remainingForGroup($group));
    }

    public function test_offer_limit_does_not_reserve_seats_from_full_period_enrollments(): void
    {
        [, , $group, $session, $offer, $child] = $this->visitFixture(
            ['visit_limit' => 2], ['capacity_max' => 3], ['capacity' => null],
        );
        $this->visitEnrollment($child, $group, null, 'full_period', startsOn: '2026-11-01');
        $other = $child->owner->children()->create(['first_name' => 'Ben', 'last_name' => 'Ivanov']);
        $this->visitEnrollment($other, $group, null, 'full_period', startsOn: '2026-11-01');

        $capacity = app(EnrollmentCapacity::class);
        $this->assertSame(1, $capacity->remainingForSession($session));
        $this->assertSame(1, $capacity->remainingForOffer($offer, $session));

        $third = $child->owner->children()->create(['first_name' => 'Cora', 'last_name' => 'Ivanova']);
        $this->visitEnrollment($third, $group, $session, 'trial', $offer);
        $this->assertSame(0, $capacity->remainingForSession($session));
        $this->assertSame(0, $capacity->remainingForOffer($offer, $session));
    }

    public function test_full_period_enrollment_does_not_use_an_offer_type_limit(): void
    {
        [, , $group, $session, $offer, $child] = $this->visitFixture(
            ['visit_limit' => 2], ['capacity_max' => 3], ['capacity' => 3],
        );
        $this->visitEnrollment($child, $group, $session, 'full_period', $offer, startsOn: '2026-11-01');

        $this->assertSame(2, app(EnrollmentCapacity::class)->remainingForSession($session));
        $this->assertSame(2, app(EnrollmentCapacity::class)->remainingForOffer($offer, $session));
    }

    public function test_offer_limit_counts_only_its_own_visit_enrollments(): void
    {
        [, , $group, $session, $offer, $child] = $this->visitFixture(['visit_limit' => 1]);
        $this->visitEnrollment($child, $group, $session, 'trial', $offer);
        $otherOffer = VisitOffer::query()->create([
            'activity_id' => $offer->activity_id, 'group_id' => $group->id,
            'type' => 'trial', 'status' => 'published', 'title' => 'Another trial', 'visit_limit' => 1,
        ]);
        $otherOffer->sessions()->attach($session->id);

        $this->assertSame(11, app(EnrollmentCapacity::class)->remainingForSession($session));
        $this->assertSame(0, app(EnrollmentCapacity::class)->remainingForOffer($offer, $session));
        $this->assertSame(1, app(EnrollmentCapacity::class)->remainingForOffer($otherOffer, $session));

        $otherChild = $child->owner->children()->create(['first_name' => 'Ben', 'last_name' => 'Ivanov']);
        $this->visitEnrollment($otherChild, $group, $session, 'trial', $otherOffer);
        $this->assertSame(10, app(EnrollmentCapacity::class)->remainingForSession($session));
        $this->assertSame(0, app(EnrollmentCapacity::class)->remainingForOffer($otherOffer, $session));
    }

    public function test_exhausted_offer_limit_is_full_while_other_session_seats_remain(): void
    {
        [, , $group, $session, $offer, $child] = $this->visitFixture(['visit_limit' => 1]);
        $this->visitEnrollment($child, $group, $session, 'trial', $offer);

        $this->assertSame(11, app(EnrollmentCapacity::class)->remainingForSession($session));
        $this->assertSame(0, app(EnrollmentCapacity::class)->remainingForOffer($offer, $session));
        $this->assertSame('full', app(VisitOfferAvailabilityService::class)->for(
            $offer, $session, CarbonImmutable::parse('2026-11-02T12:00:00Z'),
        )->status);
    }

    public function test_last_place_is_rechecked_after_allocation_inside_a_transaction(): void
    {
        [, , $group, $session, $offer, $child] = $this->visitFixture(
            ['visit_limit' => 2], ['capacity_max' => 1], ['capacity' => 1],
        );
        $capacity = app(EnrollmentCapacity::class);

        DB::transaction(function () use ($capacity, $offer, $session, $child, $group): void {
            $this->assertSame(1, $capacity->remainingForOffer($offer, $session));
            $this->visitEnrollment($child, $group, $session, 'trial', $offer);
            $this->assertSame(0, $capacity->remainingForOffer($offer, $session));
        });
    }

    public function test_postgresql_group_and_session_capacity_checks_complete_without_deadlock(): void
    {
        try {
            DB::connection('pgsql_admin')->getPdo();
        } catch (Throwable) {
            $this->markTestSkipped('PostgreSQL is required for the two-connection lock-order check.');
        }

        Artisan::call('migrate', ['--database' => 'pgsql_admin', '--force' => true]);
        $originalConnection = DB::getDefaultConnection();
        DB::setDefaultConnection('pgsql_admin');
        $connection = DB::connection('pgsql_admin');
        $process = null;
        $pipes = [];
        $organizationId = null;
        $parentId = null;

        try {
            [$organization, , $group, $firstSession, , $child] = $this->visitFixture(
                ['visit_limit' => 3], ['capacity_max' => 3], ['capacity' => 3],
            );
            $organizationId = $organization->id;
            $parentId = $child->owner_user_id;
            $secondSession = $group->sessions()->create([
                'starts_at' => '2026-11-10T14:00:00Z', 'ends_at' => '2026-11-10T15:00:00Z',
                'capacity' => 3, 'status' => 'scheduled',
            ]);
            $enrollment = $this->visitEnrollment($child, $group, null, 'full_period', startsOn: '2026-11-01');

            $connection->beginTransaction();
            $connection->table('activity_groups')->where('id', $group->id)->lockForUpdate()->first();
            $connection->table('enrollments')->where('id', $enrollment->id)->lockForUpdate()->first();
            $connection->statement("SET LOCAL lock_timeout = '8000ms'");

            $worker = <<<'PHP'
                require 'vendor/autoload.php';
                $app = require 'bootstrap/app.php';
                $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                \Illuminate\Support\Facades\DB::setDefaultConnection('pgsql_admin');
                app(\App\Domain\Organizations\Tenancy\TenantContext::class)->select($argv[1]);
                $connection = \Illuminate\Support\Facades\DB::connection('pgsql_admin');
                $connection->beginTransaction();
                $connection->statement("SET LOCAL lock_timeout = '8000ms'");
                $pid = $connection->selectOne('SELECT pg_backend_pid() AS pid')->pid;
                echo "READY:{$pid}\n";
                flush();
                try {
                    $session = \App\Domain\Catalog\Models\Session::query()->findOrFail($argv[2]);
                    $remaining = app(\App\Domain\Enrollment\Services\EnrollmentCapacity::class)->remainingForSession($session);
                    $connection->commit();
                    echo "DONE:{$remaining}\n";
                } catch (\Throwable $exception) {
                    if ($connection->transactionLevel() > 0) $connection->rollBack();
                    fwrite(STDERR, $exception->getMessage());
                    exit(1);
                }
                PHP;
            $process = proc_open(
                [PHP_BINARY, '-r', $worker, $organizationId, $secondSession->id],
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
            $this->assertTrue($waiting, 'The second PostgreSQL connection did not reach a row lock.');
            $this->assertSame(2, app(EnrollmentCapacity::class)->remainingForGroup(
                $group, CarbonImmutable::parse('2026-10-28T10:00:00Z'),
            ));
            $connection->commit();

            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $error);
            $process = null;
            $this->assertStringContainsString('DONE:2', $output);
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
            if ($organizationId !== null) {
                $connection->table('organizations')->where('id', $organizationId)->delete();
            }
            if ($parentId !== null) {
                $connection->table('users')->where('id', $parentId)->delete();
            }
            DB::setDefaultConnection($originalConnection);
        }
    }
}
