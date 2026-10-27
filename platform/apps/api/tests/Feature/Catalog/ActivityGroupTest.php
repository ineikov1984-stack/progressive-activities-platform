<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Catalog\Models\Location;
use App\Domain\Catalog\Services\ScheduleConflictDetector;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ActivityGroupTest extends TestCase
{
    use RefreshDatabase;

    public function test_group_defaults_and_multiple_instructors_generate_sessions_and_round_trip(): void
    {
        [$user, $organization, $activity] = $this->setupCatalog();
        $location = Location::query()->create(['name' => 'Зала', 'type' => 'physical']);
        $activity->offerings()->firstOrFail()->update(['location_id' => $location->id]);
        $first = InstructorProfile::query()->create(['public_name' => 'Анна']);
        $second = InstructorProfile::query()->create(['public_name' => 'Иван']);
        $response = $this->postJson($this->url($activity), [
            ...$this->payload(), 'instructor_ids' => [$first->id, $second->id],
            'organization_id' => Str::uuid()->toString(), 'status' => 'published',
        ])->assertCreated()->assertJsonPath('group.status', 'draft')
            ->assertJsonPath('group.capacity_min', 3)->assertJsonPath('group.capacity_max', 12)
            ->assertJsonPath('group.location_id', $location->id)
            ->assertJsonCount(2, 'group.instructor_ids')->assertJsonCount(2, 'group.sessions')
            ->assertJsonPath('group.sessions.0.starts_at', '2026-10-27T17:00:00+02:00')
            ->assertJsonPath('group.sessions.0.ends_at', '2026-10-27T18:00:00+02:00')
            ->assertJsonPath('group.sessions.0.location_id', $location->id)
            ->assertJsonPath('group.sessions.0.capacity', 12)
            ->assertJsonCount(2, 'group.sessions.0.instructor_ids');
        $this->assertDatabaseHas('activity_groups', ['id' => $response->json('group.id'), 'organization_id' => $organization->id]);
        $this->assertDatabaseHas('activity_sessions', ['id' => $response->json('group.sessions.0.id'), 'starts_at' => '2026-10-27 15:00:00']);
        $this->assertDatabaseCount('activity_group_instructors', 2);
        $this->assertDatabaseCount('activity_session_instructors', 4);
        $this->getJson($this->url($activity))->assertOk()->assertJsonCount(1, 'groups')->assertJsonCount(2, 'groups.0.sessions');
    }

    public function test_invalid_capacity_and_schedule_are_rejected_without_partial_writes(): void
    {
        [, , $activity] = $this->setupCatalog();
        foreach ([['capacity_min' => 13], ['capacity_max' => 0], ['capacity_min' => -1]] as $invalid) {
            $this->postJson($this->url($activity), [...$this->payload(), ...$invalid])->assertUnprocessable();
        }
        $payload = $this->payload();
        $payload['schedule_pattern']['slots'][0]['duration_minutes'] = 0;
        $this->postJson($this->url($activity), $payload)->assertUnprocessable();
        $payload['schedule_pattern'] = ['start_date' => '2026-03-29', 'end_date' => '2026-03-29', 'frequency' => 'weekly', 'slots' => [
            ['weekday' => 7, 'starts_at' => '03:30', 'duration_minutes' => 60],
        ]];
        $this->postJson($this->url($activity), $payload)->assertUnprocessable()->assertJsonValidationErrors('schedule_pattern');
        $this->assertDatabaseCount('activity_groups', 0);
        $this->assertDatabaseCount('activity_sessions', 0);
    }

    public function test_single_session_override_preserves_other_sessions_and_survives_metadata_update(): void
    {
        [, , $activity] = $this->setupCatalog();
        $location = Location::query()->create(['name' => 'Друга зала', 'type' => 'physical']);
        $teacher = InstructorProfile::query()->create(['public_name' => 'Мария']);
        $created = $this->postJson($this->url($activity), $this->payload())->assertCreated()->json('group');
        $response = $this->patchJson('/api/v1/catalog/groups/'.$created['id'], [
            'version' => 1, 'session_overrides' => [[
                'id' => $created['sessions'][0]['id'], 'starts_at' => '2026-10-28T18:30:00+02:00',
                'ends_at' => '2026-10-28T20:00:00+02:00', 'location_id' => $location->id,
                'instructor_ids' => [$teacher->id], 'capacity' => 8, 'change_reason' => 'Промяна на залата',
            ]],
        ])->assertOk()->assertJsonPath('group.version', 2)
            ->assertJsonPath('group.sessions.0.starts_at', '2026-10-28T18:30:00+02:00')
            ->assertJsonPath('group.sessions.0.location_id', $location->id)
            ->assertJsonPath('group.sessions.0.capacity', 8)
            ->assertJsonPath('group.sessions.0.instructor_ids.0', $teacher->id)
            ->assertJsonPath('group.sessions.1.id', $created['sessions'][1]['id'])
            ->assertJsonPath('group.sessions.1.starts_at', '2026-11-03T17:00:00+02:00');
        $this->patchJson('/api/v1/catalog/groups/'.$created['id'], ['version' => 2, 'name' => 'Ново име'])
            ->assertOk()->assertJsonPath('group.sessions', $response->json('group.sessions'));
        $this->patchJson('/api/v1/catalog/groups/'.$created['id'], ['version' => 3, 'schedule_pattern' => $created['schedule_pattern']])
            ->assertOk()->assertJsonPath('group.sessions', $response->json('group.sessions'));
    }

    public function test_conflicting_draft_is_saved_with_blocking_instructor_and_exclusive_location_conflicts(): void
    {
        [, , $activity] = $this->setupCatalog();
        $location = Location::query()->create(['name' => 'Зала', 'type' => 'physical', 'space_usage' => 'exclusive']);
        $teacher = InstructorProfile::query()->create(['public_name' => 'Анна']);
        $payload = [...$this->payload(), 'location_id' => $location->id, 'instructor_ids' => [$teacher->id]];
        $first = $this->postJson($this->url($activity), $payload)->assertCreated()->json('group');
        $second = $this->postJson($this->url($activity), $payload)->assertCreated()->assertJsonPath('group.status', 'draft');
        $conflicts = collect($second->json('group.conflicts'));
        $this->assertCount(2, $conflicts->where('type', 'instructor'));
        $this->assertCount(2, $conflicts->where('type', 'exclusive_location'));
        $this->assertTrue($conflicts->every(fn ($conflict) => $conflict['blocking'] === true));
        $this->assertContains($first['sessions'][0]['id'], $conflicts->pluck('conflicting_session_id')->all());
        $second->assertJsonStructure(['group' => ['conflicts' => [['type', 'session_id', 'conflicting_session_id', 'blocking', 'message']]]]);
        $this->assertDatabaseCount('activity_groups', 2);
    }

    public function test_changing_group_defaults_updates_inherited_assignments_but_preserves_session_overrides(): void
    {
        [, , $activity] = $this->setupCatalog();
        $original = Location::query()->create(['name' => 'Original', 'type' => 'physical']);
        $replacement = Location::query()->create(['name' => 'Replacement', 'type' => 'physical']);
        $special = Location::query()->create(['name' => 'Special', 'type' => 'physical']);
        $teacher = InstructorProfile::query()->create(['public_name' => 'First']);
        $newTeacher = InstructorProfile::query()->create(['public_name' => 'New']);
        $specialTeacher = InstructorProfile::query()->create(['public_name' => 'Special']);
        $group = $this->postJson($this->url($activity), [...$this->payload(), 'location_id' => $original->id, 'instructor_ids' => [$teacher->id]])->assertCreated()->json('group');
        $url = '/api/v1/catalog/groups/'.$group['id'];
        $this->patchJson($url, ['version' => 1, 'session_overrides' => [[
            'id' => $group['sessions'][0]['id'], 'location_id' => $special->id,
            'instructor_ids' => [$specialTeacher->id], 'capacity' => 8,
        ]]])->assertOk();
        $this->patchJson($url, [
            'version' => 2, 'location_id' => $replacement->id, 'instructor_ids' => [$newTeacher->id], 'capacity_max' => 15,
        ])->assertOk()->assertJsonPath('group.sessions.0.location_id', $special->id)
            ->assertJsonPath('group.sessions.0.instructor_ids.0', $specialTeacher->id)
            ->assertJsonPath('group.sessions.0.capacity', 8)
            ->assertJsonPath('group.sessions.1.location_id', $replacement->id)
            ->assertJsonPath('group.sessions.1.instructor_ids.0', $newTeacher->id)
            ->assertJsonPath('group.sessions.1.capacity', 15);
    }

    public function test_shared_spaces_warn_but_online_and_unconfirmed_places_do_not_conflict(): void
    {
        [, $organization, $activity] = $this->setupCatalog();
        foreach (['physical', 'online', 'tbc'] as $type) {
            app(TenantContext::class)->select($organization);
            $location = Location::query()->create(['name' => $type, 'type' => $type, 'space_usage' => 'shared']);
            $payload = [...$this->payload(), 'location_id' => $location->id];
            $this->postJson($this->url($activity), $payload)->assertCreated();
            $response = $this->postJson($this->url($activity), $payload)->assertCreated();
            if ($type === 'physical') {
                $response->assertJsonCount(2, 'group.conflicts')->assertJsonPath('group.conflicts.0.type', 'shared_location')->assertJsonPath('group.conflicts.0.blocking', false);
            } else {
                $response->assertJsonCount(0, 'group.conflicts');
            }
        }
    }

    public function test_adjacent_and_cancelled_sessions_do_not_conflict_but_same_group_overlap_does(): void
    {
        [, $organization, $activity] = $this->setupCatalog();
        $teacher = InstructorProfile::query()->create(['public_name' => 'Анна']);
        $payload = [...$this->payload(), 'instructor_ids' => [$teacher->id]];
        $first = $this->postJson($this->url($activity), $payload)->assertCreated()->json('group');
        $payload['schedule_pattern']['slots'][0]['starts_at'] = '18:00';
        $this->postJson($this->url($activity), $payload)->assertCreated()->assertJsonCount(0, 'group.conflicts');
        $this->patchJson('/api/v1/catalog/groups/'.$first['id'], [
            'version' => 1, 'session_overrides' => array_map(fn ($session) => ['id' => $session['id'], 'status' => 'cancelled'], $first['sessions']),
        ])->assertOk();
        $this->postJson($this->url($activity), [...$this->payload(), 'instructor_ids' => [$teacher->id]])->assertCreated()->assertJsonCount(0, 'group.conflicts');
        app(TenantContext::class)->select($organization);
        $other = InstructorProfile::query()->create(['public_name' => 'Иван']);
        $payload['instructor_ids'] = [$other->id];
        $payload['schedule_pattern']['slots'][] = ['weekday' => 2, 'starts_at' => '18:30', 'duration_minutes' => 60];
        $response = $this->postJson($this->url($activity), $payload)->assertCreated();
        $this->assertNotEmpty($response->json('group.conflicts'));
    }

    public function test_stale_update_and_invalid_session_override_do_not_partially_change_group(): void
    {
        [, , $activity] = $this->setupCatalog();
        $group = $this->postJson($this->url($activity), $this->payload())->assertCreated()->json('group');
        $other = $this->postJson($this->url($activity), $this->payload())->assertCreated()->json('group');
        $url = '/api/v1/catalog/groups/'.$group['id'];
        $this->patchJson($url, ['version' => 1, 'name' => 'Updated'])->assertOk()->assertJsonPath('group.version', 2);
        $this->patchJson($url, ['version' => 1, 'name' => 'Stale'])->assertConflict();
        $this->patchJson($url, ['version' => 2, 'name' => 'Must roll back', 'session_overrides' => [
            ['id' => $group['sessions'][0]['id'], 'capacity' => 7],
            ['id' => $other['sessions'][0]['id'], 'capacity' => 7],
        ]])->assertUnprocessable();
        $this->assertDatabaseHas('activity_groups', ['id' => $group['id'], 'name' => 'Updated', 'version' => 2]);
        $this->assertDatabaseHas('activity_sessions', ['id' => $group['sessions'][0]['id'], 'capacity' => 12, 'version' => 1]);
        $this->patchJson($url, ['version' => 2, 'capacity_max' => 2])->assertUnprocessable();
    }

    public function test_foreign_ids_are_rejected_and_foreign_groups_hidden_and_ignored_by_detector(): void
    {
        [$foreignUser, $foreignOrganization, $foreignActivity] = $this->setupCatalog();
        $foreignLocation = Location::query()->create(['name' => 'Foreign', 'type' => 'physical']);
        $foreignTeacher = InstructorProfile::query()->create(['public_name' => 'Foreign']);
        $foreignOfferingId = $foreignActivity->offerings()->firstOrFail()->id;
        $foreignGroup = $this->postJson($this->url($foreignActivity), $this->payload())->assertCreated()->json('group');
        [$user, $organization, $activity] = $this->setupCatalog();
        foreach ([['location_id' => $foreignLocation->id], ['instructor_ids' => [$foreignTeacher->id]], ['offering_id' => $foreignOfferingId]] as $invalid) {
            $this->postJson($this->url($activity), [...$this->payload(), ...$invalid])->assertUnprocessable();
        }
        $this->getJson($this->url($foreignActivity))->assertNotFound();
        $this->patchJson('/api/v1/catalog/groups/'.$foreignGroup['id'], ['version' => 1, 'name' => 'Hacked'])->assertNotFound();
        $response = $this->postJson($this->url($activity), $this->payload())->assertCreated()->assertJsonCount(0, 'group.conflicts');
        $this->getJson($this->url($activity))->assertOk()->assertJsonCount(1, 'groups');
        // Even a previously loaded foreign model cannot make the detector disclose its sessions.
        app(TenantContext::class)->select($foreignOrganization);
        $foreignModel = Group::query()->findOrFail($foreignGroup['id']);
        app(TenantContext::class)->select($organization);
        $this->assertSame([], app(ScheduleConflictDetector::class)->forGroup($foreignModel));
    }

    public function test_viewer_cannot_write_and_suspended_organization_cannot_change_schedule(): void
    {
        [$user, $organization, $activity] = $this->setupCatalog();
        $group = $this->postJson($this->url($activity), $this->payload())->assertCreated()->json('group');
        OrganizationMembership::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->update(['role' => 'viewer']);
        $this->getJson($this->url($activity))->assertOk();
        $this->postJson($this->url($activity), $this->payload())->assertForbidden();
        $this->patchJson('/api/v1/catalog/groups/'.$group['id'], ['version' => 1, 'name' => 'No'])->assertForbidden();
        OrganizationMembership::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->update(['role' => 'organization_owner']);
        $organization->update(['status' => 'suspended']);
        $this->postJson($this->url($activity), $this->payload())->assertForbidden();
    }

    /** @return array{User, Organization, Activity} */
    private function setupCatalog(): array
    {
        $user = User::factory()->create();
        $organization = Organization::query()->withoutGlobalScopes()->create(['name' => 'School', 'slug' => 'school-'.Str::random(12), 'status' => 'active']);
        OrganizationMembership::query()->withoutGlobalScopes()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => 'organization_owner', 'status' => 'active']);
        app(TenantContext::class)->select($organization);
        $activity = Activity::query()->create(['title' => 'Art', 'slug' => 'art', 'category' => 'Art', 'summary' => 'Art class', 'age_label' => '6–9']);
        $activity->offerings()->create([]);
        $this->actingAs($user)->withSession(['active_organization_id' => $organization->id])->withHeader('Accept', 'application/json');

        return [$user, $organization, $activity];
    }

    private function url(Activity $activity): string
    {
        return '/api/v1/catalog/activities/'.$activity->id.'/groups';
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['name' => 'Вторник', 'capacity_min' => 3, 'capacity_max' => 12, 'schedule_pattern' => [
            'start_date' => '2026-10-27', 'end_date' => '2026-11-03', 'frequency' => 'weekly',
            'slots' => [['weekday' => 2, 'starts_at' => '17:00', 'duration_minutes' => 60]],
        ]];
    }
}
