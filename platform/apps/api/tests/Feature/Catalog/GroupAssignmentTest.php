<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Catalog\Models\Session;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Models\StaffMember;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Lead and assistant with a period in a group, also a published one (docs/product/team.md, т. 5). */
class GroupAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignments_follow_their_period_in_a_published_group_and_keep_sessions_changed_by_hand(): void
    {
        $this->travelTo('2026-11-01 10:00:00');
        [, $organization, $activity] = $this->setupCatalog();
        $anna = InstructorProfile::query()->create(['public_name' => 'Анна']);
        $boris = InstructorProfile::query()->create(['public_name' => 'Борис']);
        $chris = InstructorProfile::query()->create(['public_name' => 'Христо']);
        $group = $this->postJson($this->groupsUrl($activity), [...$this->payload(), 'instructor_ids' => [$anna->id]])->assertCreated()->json('group');
        // Before publishing, one session is changed by hand.
        $this->patchJson('/api/v1/catalog/groups/'.$group['id'], ['version' => 1, 'session_overrides' => [[
            'id' => $group['sessions'][1]['id'], 'instructor_ids' => [$chris->id],
        ]]])->assertOk();
        Group::query()->whereKey($group['id'])->update(['status' => 'published']);
        $url = '/api/v1/catalog/groups/'.$group['id'].'/assignments';

        $this->postJson($url, ['instructor_profile_id' => $boris->id, 'role' => 'assistant', 'starts_on' => '2026-11-15'])
            ->assertCreated()->assertJsonCount(2, 'group.assignments');
        $this->assertDatabaseHas('activity_group_instructors', ['group_id' => $group['id'], 'instructor_profile_id' => $boris->id, 'role' => 'assistant', 'starts_on' => '2026-11-15', 'ends_on' => null]);
        $this->assertSame([$anna->id => 'lead'], $this->teachersOn($group['id'], '2026-11-03'));
        $this->assertSame([$chris->id => 'lead'], $this->teachersOn($group['id'], '2026-11-10'), 'A session changed by hand keeps its teachers.');
        $this->assertEqualsCanonicalizing([$anna->id => 'lead', $boris->id => 'assistant'], $this->teachersOn($group['id'], '2026-11-17'));
        $this->assertSame('manual', DB::table('activity_session_instructors')->where('instructor_profile_id', $chris->id)->value('source'));

        $this->postJson($url, ['instructor_profile_id' => $boris->id])->assertUnprocessable()->assertJsonValidationErrors('instructor_profile_id');
        $this->postJson($url, ['instructor_profile_id' => $chris->id, 'starts_on' => '2026-11-20', 'ends_on' => '2026-11-19'])
            ->assertUnprocessable()->assertJsonValidationErrors('ends_on');

        $end = $url.'/'.$anna->id.'/end';
        $this->postJson($end, ['ends_on' => '2026-11-20', 'version' => 9])->assertConflict();
        $this->postJson($end, ['ends_on' => '2026-11-20', 'version' => 1])->assertOk();
        $this->assertEqualsCanonicalizing([$anna->id => 'lead', $boris->id => 'assistant'], $this->teachersOn($group['id'], '2026-11-17'));
        $this->assertSame([$boris->id => 'assistant'], $this->teachersOn($group['id'], '2026-11-24'));

        // An ended assignment is opened again on the same row, with a new period.
        $this->travelTo('2026-11-25 10:00:00');
        $this->postJson($url, ['instructor_profile_id' => $anna->id, 'starts_on' => '2026-12-01'])->assertCreated();
        $this->assertDatabaseHas('activity_group_instructors', ['group_id' => $group['id'], 'instructor_profile_id' => $anna->id, 'starts_on' => '2026-12-01', 'ends_on' => null, 'version' => 3]);
        $this->assertDatabaseCount('activity_group_instructors', 2);
        // Who teaches now: Anna starts again only in December.
        app(TenantContext::class)->select($organization);
        $this->assertSame([$boris->id], Group::query()->findOrFail($group['id'])->instructors()->pluck('instructor_profiles.id')->all());
        $this->assertSame(2, DB::table('audit_events')->where('action', 'catalog.assignment_added')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'catalog.assignment_ended')->count());
    }

    public function test_archived_foreign_and_unauthorized_assignments_are_refused(): void
    {
        $this->travelTo('2026-11-01 10:00:00');
        [$user, $organization, $activity] = $this->setupCatalog();
        $group = $this->postJson($this->groupsUrl($activity), $this->payload())->assertCreated()->json('group');
        $url = '/api/v1/catalog/groups/'.$group['id'].'/assignments';

        // Each request ends with the organization cleared; the test selects it again before using the models.
        app(TenantContext::class)->select($organization);
        $person = StaffMember::query()->create(['full_name' => 'Стар треньор', 'archived_at' => now()]);
        $archived = InstructorProfile::query()->create(['public_name' => 'Стар треньор']);
        $archived->forceFill(['staff_member_id' => $person->id])->save();
        $this->postJson($url, ['instructor_profile_id' => $archived->id])->assertUnprocessable()->assertJsonPath('errors.instructor_profile_id.0', 'Преподавателят е архивиран.');

        // Another organization's teacher or group.
        $foreign = Organization::query()->withoutGlobalScopes()->create(['name' => 'Other', 'slug' => 'other-'.Str::random(8), 'status' => 'active']);
        app(TenantContext::class)->select($foreign);
        $foreignTeacher = InstructorProfile::query()->create(['public_name' => 'Чужд']);
        $foreignActivity = Activity::query()->create(['title' => 'Other', 'slug' => 'other', 'category' => 'Art', 'summary' => 'Other', 'age_label' => '6–9']);
        $foreignGroup = $foreignActivity->offerings()->create([])->groups()->create(['name' => 'Чужда']);
        app(TenantContext::class)->select($organization);
        $this->postJson($url, ['instructor_profile_id' => $foreignTeacher->id])->assertUnprocessable()->assertJsonValidationErrors('instructor_profile_id');
        app(TenantContext::class)->select($organization);
        $mine = InstructorProfile::query()->create(['public_name' => 'Мой']);
        $this->postJson('/api/v1/catalog/groups/'.$foreignGroup->id.'/assignments', ['instructor_profile_id' => $mine->id])->assertNotFound();

        // A coordinator assigns (owner's decision, docs/product/team.md, т. 13.1); a viewer does not.
        $this->assertSame(1, OrganizationMembership::query()->withoutGlobalScopes()->where('user_id', $user->id)->update(['role' => 'coordinator']));
        $this->postJson($url, ['instructor_profile_id' => $mine->id])->assertCreated();
        $this->assertSame(1, OrganizationMembership::query()->withoutGlobalScopes()->where('user_id', $user->id)->update(['role' => 'viewer']));
        $this->postJson($url.'/'.$mine->id.'/end', ['ends_on' => '2026-11-10', 'version' => 1])->assertForbidden();
        $this->assertDatabaseCount('activity_group_instructors', 1);
    }

    /** @return array<string, string> teacher → role on the group's session that day */
    private function teachersOn(string $groupId, string $day): array
    {
        $session = Session::query()->withoutGlobalScopes()->where('group_id', $groupId)->whereDate('starts_at', $day)->firstOrFail();

        return DB::table('activity_session_instructors')->where('session_id', $session->id)->pluck('role', 'instructor_profile_id')->all();
    }

    /** @return array{User, Organization, Activity} */
    private function setupCatalog(): array
    {
        $user = User::factory()->create();
        $organization = Organization::query()->withoutGlobalScopes()->create(['name' => 'School', 'slug' => 'school-'.Str::random(12), 'status' => 'active']);
        OrganizationMembership::query()->withoutGlobalScopes()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => 'organization_owner', 'status' => 'active']);
        app(TenantContext::class)->select($organization);
        $activity = Activity::query()->create(['title' => 'Chess', 'slug' => 'chess', 'category' => 'Games', 'summary' => 'Chess class', 'age_label' => '6–9']);
        $activity->offerings()->create([]);
        $this->actingAs($user)->withSession(['active_organization_id' => $organization->id])->withHeader('Accept', 'application/json');

        return [$user, $organization, $activity];
    }

    private function groupsUrl(Activity $activity): string
    {
        return '/api/v1/catalog/activities/'.$activity->id.'/groups';
    }

    /** @return array<string, mixed> Tuesdays 3, 10, 17 and 24 November at 17:00 in Sofia. */
    private function payload(): array
    {
        return ['name' => 'Вторник', 'capacity_min' => 1, 'capacity_max' => 12, 'schedule_pattern' => [
            'start_date' => '2026-11-03', 'end_date' => '2026-11-24', 'frequency' => 'weekly',
            'slots' => [['weekday' => 2, 'starts_at' => '17:00', 'duration_minutes' => 60]],
        ]];
    }
}
