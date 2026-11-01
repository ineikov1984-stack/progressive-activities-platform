<?php

namespace Tests\Feature\Substitute;

use App\Domain\Catalog\Actions\ApplyScheduleChange;
use App\Domain\Catalog\Actions\SubmitSubstituteRequest;
use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Catalog\Models\Location;
use App\Domain\Catalog\ValueObjects\ScheduleChangeRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\VisitOfferFixture;
use Tests\TestCase;
use Throwable;

class SubstituteScheduleExpiryTest extends TestCase
{
    use RefreshDatabase, VisitOfferFixture;

    private function fixture(): array
    {
        $this->travelTo('2026-11-01 10:00:00');
        [$org, , $group, $session] = $this->visitFixture();
        $actor = User::factory()->create();
        $this->actingAs($actor);
        OrganizationMembership::query()->create(['organization_id' => $org->id, 'user_id' => $actor->id, 'role' => 'coordinator', 'status' => 'active']);
        $profile = InstructorProfile::query()->create(['user_id' => $actor->id, 'public_name' => 'Teacher']);
        $session->instructors()->attach($profile->id);
        $otherGroup = $group->offering->groups()->create(['name' => 'Second', 'status' => 'published']);
        $second = $otherGroup->sessions()->create(['starts_at' => '2026-11-05 14:00:00', 'ends_at' => '2026-11-05 15:00:00', 'status' => 'scheduled']);
        $second->instructors()->attach($profile->id);
        $request = app(SubmitSubstituteRequest::class)->execute($actor, [['id' => $session->id, 'version' => 1], ['id' => $second->id, 'version' => 1]], 'personal', 'Private note', null, 'request')['request'];

        return [$actor, $session->refresh(), $second->refresh(), $request];
    }

    private function apply($actor, ScheduleChangeRequest $request): void
    {
        app(ApplyScheduleChange::class)->handle($actor, $request, (string) Str::uuid());
    }

    public function test_cancel_expires_selected_items_and_locks_every_request_group(): void
    {
        [$actor, $session, $second, $request] = $this->fixture();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->apply($actor, new ScheduleChangeRequest('cancel', [['id' => $session->id, 'version' => 1]], 'Reason', false, false, []));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $groupLock = collect($queries)->first(fn ($q) => str_contains($q['query'], 'select "id" from "activity_groups"'));
        $this->assertNotNull($groupLock);
        $this->assertContains($session->group_id, $groupLock['bindings']);
        $this->assertContains($second->group_id, $groupLock['bindings']);
        $this->assertDatabaseHas('substitute_request_sessions', ['session_id' => $session->id, 'status' => 'expired', 'closed_reason' => 'cancelled']);
        $this->assertDatabaseHas('substitute_request_sessions', ['session_id' => $second->id, 'status' => 'open']);
        $this->assertSame(2, $request->refresh()->version);
        $this->assertNull($request->closed_at);
        $this->apply($actor, new ScheduleChangeRequest('cancel', [['id' => $second->id, 'version' => 1]], 'Reason', false, false, []));
        $this->assertSame(3, $request->refresh()->version);
        $this->assertNotNull($request->closed_at);
    }

    public function test_move_closes_items_but_location_change_keeps_them_open(): void
    {
        [$actor, $session, $second, $request] = $this->fixture();
        $location = Location::query()->create(['name' => 'New room', 'type' => 'physical', 'space_usage' => 'shared']);
        $this->apply($actor, new ScheduleChangeRequest('change_location', [['id' => $session->id, 'version' => 1]], null, false, false, [], locationProvided: true, locationId: $location->id));
        $this->assertDatabaseHas('substitute_request_sessions', ['session_id' => $session->id, 'status' => 'open']);
        $this->assertSame(1, $request->refresh()->version);
        $this->apply($actor, new ScheduleChangeRequest('move', [['id' => $session->id, 'version' => 2]], null, false, false, [], date: '2026-11-06', from: '16:00', to: '17:00'));
        $this->assertDatabaseHas('substitute_request_sessions', ['session_id' => $session->id, 'status' => 'expired', 'closed_reason' => 'moved']);
        $this->assertSame(2, $request->refresh()->version);
    }

    public function test_location_change_does_not_normalize_other_started_request_items(): void
    {
        [$actor, $session, $second, $request] = $this->fixture();
        $this->travelTo('2026-11-04 10:00:00');
        $location = Location::query()->create(['name' => 'Other room', 'type' => 'physical', 'space_usage' => 'shared']);
        $this->apply($actor, new ScheduleChangeRequest('change_location', [['id' => $second->id, 'version' => 1]], null, false, false, [], locationProvided: true, locationId: $location->id));
        $this->assertDatabaseHas('substitute_request_sessions', ['session_id' => $session->id, 'status' => 'open']);
        $this->assertSame(1, $request->refresh()->version);
    }

    public function test_holiday_expires_requests_and_restore_does_not_reopen_them(): void
    {
        [$actor, $session, , $request] = $this->fixture();
        $this->apply($actor, new ScheduleChangeRequest('holiday', [['id' => $session->id, 'version' => 1]], 'Holiday', false, false, [], days: ['2026-11-03']));
        $this->assertDatabaseHas('substitute_request_sessions', ['session_id' => $session->id, 'status' => 'expired', 'closed_reason' => 'cancelled']);
        $this->apply($actor, new ScheduleChangeRequest('restore', [['id' => $session->id, 'version' => 2]], null, false, false, []));
        $this->assertDatabaseHas('substitute_request_sessions', ['session_id' => $session->id, 'status' => 'expired', 'closed_reason' => 'cancelled']);
        $this->assertSame(2, $request->refresh()->version);
    }

    #[TestGroup('tenant-isolation')]
    public function test_request_second_group_lock_blocks_first_group_schedule_write_without_partial_changes(): void
    {
        try {
            DB::connection('pgsql_admin')->getPdo();
        } catch (Throwable) {
            $this->markTestSkipped('PostgreSQL is required for the two-group request lock check.');
        }
        Artisan::call('migrate', ['--database' => 'pgsql_admin', '--force' => true]);
        config(['database.connections.pgsql_substitute_other' => config('database.connections.pgsql_admin'), 'platform.schedule_lock_timeout_ms' => 150]);
        $originalConnection = DB::getDefaultConnection();
        DB::setDefaultConnection('pgsql_admin');
        $connection = DB::connection('pgsql_admin');
        $other = DB::connection('pgsql_substitute_other');
        $context = app(TenantContext::class);
        $organization = null;
        $actor = null;
        $this->travelTo('2026-11-01 10:00:00');
        try {
            $organization = Organization::query()->withoutGlobalScopes()->create(['name' => 'Synthetic request lock school', 'slug' => (string) Str::uuid(), 'status' => 'active']);
            $context->select($organization);
            $actor = User::factory()->create();
            $this->actingAs($actor);
            OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $actor->id, 'role' => 'coordinator', 'status' => 'active']);
            $activity = Activity::query()->create(['title' => 'Synthetic chess', 'slug' => (string) Str::uuid(), 'category' => 'games', 'summary' => 'Classes', 'age_label' => '7+', 'status' => 'published']);
            $offering = $activity->offerings()->create([]);
            $profile = InstructorProfile::query()->create(['public_name' => 'Synthetic teacher', 'user_id' => $actor->id]);
            $sessions = [];
            foreach ([3, 4] as $day) {
                $group = $offering->groups()->create(['name' => 'Synthetic group '.$day, 'capacity_max' => 10, 'status' => 'published']);
                $session = $group->sessions()->create(['starts_at' => '2026-11-0'.$day.' 14:00:00', 'ends_at' => '2026-11-0'.$day.' 15:00:00', 'status' => 'scheduled']);
                $session->instructors()->attach($profile->id);
                $sessions[] = $session->refresh();
            }
            $source = app(SubmitSubstituteRequest::class)->execute($actor, array_map(fn ($s) => ['id' => $s->id, 'version' => 1], $sessions), 'personal', null, null, 'synthetic-request')['request'];
            $other->beginTransaction();
            $other->table('activity_groups')->where('id', $sessions[1]->group_id)->lockForUpdate()->first();
            $request = new ScheduleChangeRequest('cancel', [['id' => $sessions[0]->id, 'version' => 1]], 'Synthetic reason', false, false, []);
            try {
                $this->apply($actor, $request);
                $this->fail('The other group of the same request must be locked first.');
            } catch (HttpException $e) {
                $this->assertSame(409, $e->getStatusCode());
            }
            $this->assertSame(0, $connection->transactionLevel());
            $this->assertSame(1, $source->refresh()->version);
            $this->assertSame(2, $connection->table('substitute_request_sessions')->where('request_id', $source->id)->where('status', 'open')->count());
            $this->assertSame('scheduled', $sessions[0]->refresh()->status);
            $this->assertSame(1, $sessions[0]->version);
            $this->assertSame(0, $connection->table('schedule_changes')->where('organization_id', $organization->id)->count());
            $other->rollBack();
            $this->apply($actor, $request);
            $this->assertSame('cancelled', $sessions[0]->refresh()->status);
        } finally {
            if ($other->transactionLevel() > 0) {
                $other->rollBack();
            }
            DB::purge('pgsql_substitute_other');
            $context->clear();
            if ($organization !== null) {
                $connection->table('organizations')->where('id', $organization->id)->delete();
            }
            if ($actor !== null) {
                $connection->table('users')->where('id', $actor->id)->delete();
            }
            DB::setDefaultConnection($originalConnection);
        }
    }
}
