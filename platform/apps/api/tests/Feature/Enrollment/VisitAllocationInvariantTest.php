<?php

namespace Tests\Feature\Enrollment;

use App\Domain\Enrollment\Actions\RecordVisitOutcome;
use App\Domain\Enrollment\Actions\ReviewEnrollmentApplication;
use App\Domain\Enrollment\Actions\SubmitEnrollmentApplication;
use App\Domain\Enrollment\Models\EnrollmentTransition;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AcceptsPaymentTerms;
use Tests\Support\VisitOfferFixture;
use Tests\TestCase;

class VisitAllocationInvariantTest extends TestCase
{
    use AcceptsPaymentTerms;
    use RefreshDatabase, VisitOfferFixture;

    public static function modes(): array
    {
        return [['automatic', false], ['automatic', true], ['manual', false], ['manual', true]];
    }

    #[DataProvider('modes')]
    public function test_different_keys_cannot_request_the_same_child_session_even_through_competing_offers(string $mode, bool $competing): void
    {
        $this->travelTo('2026-10-28T10:00:00Z');
        [$org, , , $session, $offer, $child] = $this->visitFixture(['type' => 'single'], ['enrollment_approval_mode' => $mode]);
        $other = $offer->replicate();
        $other->save();
        $other->sessions()->attach($session);
        $payload = ['child_id' => $child->id, 'visit_offer_id' => $offer->id, 'session_id' => $session->id, 'accepted_terms_version' => 'v1'];
        app(SubmitEnrollmentApplication::class)->handle($child->owner, $payload, 'first');
        $payload['visit_offer_id'] = $competing ? $other->id : $offer->id;
        try {
            app(SubmitEnrollmentApplication::class)->handle($child->owner, $payload, 'second');
            $this->fail('Duplicate child/session request was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('session_id', $exception->errors());
        }
        app(TenantContext::class)->select($org);
        $this->assertDatabaseCount('enrollment_applications', 1);
        $this->assertDatabaseCount('enrollments', $mode === 'automatic' ? 1 : 0);
    }

    public function test_review_rechecks_live_allocation_for_a_legacy_pending_competing_offer(): void
    {
        $this->travelTo('2026-10-28T10:00:00Z');
        [$org, , , $session, $offer, $child] = $this->visitFixture(['type' => 'open_day'], ['enrollment_approval_mode' => 'automatic']);
        $payload = ['child_id' => $child->id, 'visit_offer_id' => $offer->id, 'session_id' => $session->id, 'accepted_terms_version' => 'v1'];
        $first = app(SubmitEnrollmentApplication::class)->handle($child->owner, $payload, 'first');
        app(TenantContext::class)->select($org);
        $other = $offer->replicate();
        $other->save();
        $other->sessions()->attach($session);
        $pending = $first->replicate();
        $pending->fill(['status' => 'submitted', 'version' => 1, 'idempotency_key' => 'legacy', 'visit_offer_id' => $other->id]);
        $pending->save();
        EnrollmentTransition::create(['application_id' => $pending->id, 'actor_user_id' => $child->owner_user_id,
            'to_status' => 'submitted', 'metadata' => ['visit_offer_type' => 'open_day']]);
        try {
            app(ReviewEnrollmentApplication::class)->handle($child->owner, $pending, 'approve', 1, null, null);
            $this->fail('Review allocated a second seat.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('session_id', $exception->errors());
        }
        $this->assertDatabaseHas('enrollment_applications', ['id' => $pending->id, 'status' => 'submitted', 'version' => 1]);
        $this->assertDatabaseCount('enrollments', 1);
    }

    public function test_timely_cancellation_allows_a_new_request_but_old_key_still_replays_history(): void
    {
        $this->travelTo('2026-10-28T10:00:00Z');
        [$org, , , $session, $offer, $child] = $this->visitFixture([], ['enrollment_approval_mode' => 'automatic']);
        $payload = ['child_id' => $child->id, 'visit_offer_id' => $offer->id, 'session_id' => $session->id, 'accepted_terms_version' => 'v1'];
        $first = app(SubmitEnrollmentApplication::class)->handle($child->owner, $payload, 'first');
        app(TenantContext::class)->select($org);
        app(RecordVisitOutcome::class)->handle($child->owner, $first->enrollment, 'cancelled_in_time', null);
        $second = app(SubmitEnrollmentApplication::class)->handle($child->owner, $payload, 'second');
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('approved', $second->status->value);
        $this->assertSame($first->id, app(SubmitEnrollmentApplication::class)->handle($child->owner, $payload, 'first')->id);
    }

    public function test_withdrawn_pending_request_allows_a_fresh_request(): void
    {
        $this->travelTo('2026-10-28T10:00:00Z');
        [$org, , , $session, $offer, $child] = $this->visitFixture(['type' => 'single']);
        $payload = ['child_id' => $child->id, 'visit_offer_id' => $offer->id, 'session_id' => $session->id, 'accepted_terms_version' => 'v1'];
        $first = app(SubmitEnrollmentApplication::class)->handle($child->owner, $payload, 'first');
        app(TenantContext::class)->select($org);
        $this->actingAs($child->owner)->postJson('/api/v1/parent/enrollment-applications/'.$first->id.'/withdraw', ['version' => 1])->assertOk();
        $second = app(SubmitEnrollmentApplication::class)->handle($child->owner, $payload, 'second');
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('submitted', $second->status->value);
    }

    public function test_full_period_approval_cannot_allocate_a_second_seat_for_an_existing_child_visit(): void
    {
        $this->travelTo('2026-10-28T10:00:00Z');
        [$org, , $group, $session, $offer, $child] = $this->visitFixture();
        $this->visitEnrollment($child, $group, $session, 'trial', $offer);
        $price = $group->offering->priceOptions()->create(['group_id' => $group->id, 'enrollment_mode' => 'monthly',
            'unit' => 'month', 'amount_cents' => 5000, 'currency' => 'EUR', 'is_available' => true]);
        $application = app(SubmitEnrollmentApplication::class)->handle($child->owner, ['child_id' => $child->id,
            'group_id' => $group->id, 'price_option_id' => $price->id, 'accepted_terms_version' => $this->standardTermsVersion($price->id)], 'regular');
        app(TenantContext::class)->select($org);
        try {
            app(ReviewEnrollmentApplication::class)->handle($child->owner, $application, 'approve', 1, null, null);
            $this->fail('Regular enrollment allocated a duplicate child/session seat.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('session_id', $exception->errors());
        }
        $this->assertDatabaseCount('enrollments', 1);
    }

    public static function concurrentChildren(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('concurrentChildren')]
    public function test_postgresql_concurrent_submissions_serialize_duplicate_and_last_seat_checks(bool $sameChild): void
    {
        try {
            DB::connection('pgsql_admin')->getPdo();
        } catch (\Throwable) {
            $this->markTestSkipped('PostgreSQL is required for concurrent submission verification.');
        }
        Artisan::call('migrate', ['--database' => 'pgsql_admin', '--force' => true]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('pgsql_admin');
        $connection = DB::connection('pgsql_admin');
        $orgId = null;
        $parentId = null;
        $workers = [];
        try {
            [$org, , $group, $session, $offer, $child] = $this->visitFixture(['type' => 'open_day'], ['enrollment_approval_mode' => 'automatic', 'capacity_max' => 1]);
            $orgId = $org->id;
            $parentId = $child->owner_user_id;
            $other = $sameChild ? $child : $child->owner->children()->create(['first_name' => 'Ben', 'last_name' => 'Smith']);
            $competing = $offer->replicate();
            $competing->save();
            $competing->sessions()->attach($session);
            $connection->beginTransaction();
            $connection->table('activity_groups')->where('id', $group->id)->lockForUpdate()->first();
            $worker = <<<'PHP'
                require 'vendor/autoload.php';
                $app = require 'bootstrap/app.php';
                $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                \Illuminate\Support\Facades\DB::setDefaultConnection('pgsql_admin');
                \Illuminate\Support\Facades\Date::setTestNow('2026-10-28T10:00:00Z');
                \Illuminate\Support\Facades\DB::statement("SET lock_timeout = '8000ms'");
                echo \Illuminate\Support\Facades\DB::selectOne('SELECT pg_backend_pid() AS pid')->pid."\n";
                flush();
                try {
                    $actor = \App\Domain\Identity\Models\User::findOrFail($argv[1]);
                    $application = app(\App\Domain\Enrollment\Actions\SubmitEnrollmentApplication::class)->handle($actor, [
                        'child_id' => $argv[2], 'visit_offer_id' => $argv[3], 'session_id' => $argv[4], 'accepted_terms_version' => 'v1',
                    ], $argv[5]);
                    echo $application->status->value;
                } catch (\Illuminate\Validation\ValidationException $exception) {
                    echo implode(',', array_keys($exception->errors()));
                }
                PHP;
            foreach ([[$child, $offer], [$other, $competing]] as $index => [$candidate, $candidateOffer]) {
                $process = proc_open([PHP_BINARY, '-r', $worker, $parentId, $candidate->id, $candidateOffer->id, $session->id, 'concurrent-'.$index],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                fclose($pipes[0]);
                stream_set_timeout($pipes[1], 10);
                $pid = (int) trim((string) fgets($pipes[1]));
                $workers[] = [$process, $pipes, $pid];
                $this->assertGreaterThan(0, $pid);
            }
            $waiting = false;
            for ($attempt = 0; $attempt < 150; $attempt++) {
                $waiting = $connection->table('pg_stat_activity')->whereIn('pid', array_column($workers, 2))->where('wait_event_type', 'Lock')->count() === 2;
                if ($waiting) {
                    break;
                }
                usleep(20000);
            }
            $this->assertTrue($waiting, 'Both requests must reach concurrent allocation locks.');
            $connection->commit();
            $results = [];
            foreach ($workers as $index => [$process, $pipes]) {
                $results[] = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exit = proc_close($process);
                unset($workers[$index]);
                $this->assertSame(0, $exit, $error);
            }
            $this->assertEqualsCanonicalizing(['approved', $sameChild ? 'session_id' : 'waitlisted'], $results);
            $this->assertSame(1, $connection->table('enrollments')->where('organization_id', $orgId)->count());
            $this->assertSame($sameChild ? 1 : 2, $connection->table('enrollment_applications')->where('organization_id', $orgId)->count());
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            foreach ($workers as [$process, $pipes]) {
                if (is_resource($process)) {
                    proc_terminate($process);
                }
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                if (is_resource($process)) {
                    proc_close($process);
                }
            }
            if ($orgId !== null) {
                $connection->table('organizations')->where('id', $orgId)->delete();
            }
            if ($parentId !== null) {
                $connection->table('users')->where('id', $parentId)->delete();
            }
            DB::setDefaultConnection($original);
        }
    }
}
