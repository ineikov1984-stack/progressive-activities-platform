<?php

namespace Tests\Feature\Substitute;

use App\Domain\Organizations\Models\StaffGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\TeamRightsFixture;
use Tests\TestCase;

class SubstituteAssignmentApiTest extends TestCase
{
    use RefreshDatabase, TeamRightsFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-01 10:00:00');
    }

    private function assignmentFixture(): array
    {
        $f = $this->rightsFixture();
        $teacher = $this->memberOf($f['organization'], 'instructor');
        $original = $this->profileOf($f['organization'], $this->personFor($f['organization'], $teacher, true));
        $substituteUser = $this->memberOf($f['organization'], 'instructor');
        $substitute = $this->profileOf($f['organization'], $this->personFor($f['organization'], $substituteUser, true));
        $this->inTenant($f['organization'], function () use ($f, $original): void {
            $f['tuesday']->assignments()->attach($original->id, ['organization_id' => $f['organization']->id, 'role' => 'lead']);
            $f['session']->instructors()->attach($original->id, ['organization_id' => $f['organization']->id, 'role' => 'lead']);
        });

        return [$f, $original, $substitute, $teacher];
    }

    public function test_assignment_preview_apply_replay_and_return_keep_the_original_teacher_identity(): void
    {
        [$f, $original, $substitute] = $this->assignmentFixture();
        $this->signInTo($f['owner'], $f['organization'])->withHeader('Idempotency-Key', 'assign-one');
        $body = ['action' => 'substitute', 'sessions' => [['id' => $f['session']->id, 'version' => 1]], 'replaces_profile_id' => $original->id, 'substitute_profile_id' => $substitute->id];
        $this->postJson('/api/v1/schedule/changes/preview', $body)->assertOk()->assertJsonPath('totals.families', 0)->assertJsonPath('warnings', []);
        $this->assertDatabaseCount('schedule_changes', 0);
        $created = $this->postJson('/api/v1/schedule/changes', $body)->assertCreated()->assertJsonPath('letters.families', 0);
        $this->postJson('/api/v1/schedule/changes', $body)->assertOk()->assertExactJson($created->json());
        $this->assertDatabaseCount('schedule_changes', 1);
        $panel = $this->getJson('/api/v1/schedule/sessions/'.$f['session']->id)->assertOk()->assertJsonPath('session.instructors.0.profile_id', $substitute->id)->assertJsonPath('session.instructors.0.replaces.0.profile_id', $original->id);
        $this->assertNotNull($panel->json('last_substitute'));
        $this->assertNull($panel->json('last_change'));
        $this->withHeader('Idempotency-Key', 'return-one');
        $this->postJson('/api/v1/schedule/changes', [...$body, 'sessions' => [['id' => $f['session']->id, 'version' => 2]], 'substitute_profile_id' => $original->id])->assertCreated();
        $this->getJson('/api/v1/schedule/sessions/'.$f['session']->id)->assertOk()->assertJsonPath('session.instructors.0.profile_id', $original->id);
        $this->assertDatabaseHas('activity_sessions', ['id' => $f['session']->id, 'version' => 3, 'instructors_source' => 'assignment']);
    }

    public function test_substitute_requires_both_office_rights_before_body_validation(): void
    {
        [$f] = $this->assignmentFixture();
        foreach (['platform_admin', 'instructor', 'finance', 'viewer'] as $role) {
            $this->signInTo($this->memberOf($f['organization'], $role), $f['organization']);
            foreach (['preview', ''] as $suffix) {
                $this->postJson('/api/v1/schedule/changes'.($suffix === '' ? '' : '/'.$suffix), ['action' => 'substitute'])->assertForbidden();
            }
        }
    }

    public function test_substitute_rejects_parent_fields_and_more_than_fifty_sessions(): void
    {
        [$f, $original, $substitute] = $this->assignmentFixture();
        $this->signInTo($f['owner'], $f['organization']);
        $body = ['action' => 'substitute', 'sessions' => [['id' => $f['session']->id, 'version' => 1]], 'replaces_profile_id' => $original->id, 'substitute_profile_id' => $substitute->id];
        foreach ([['reason' => 'Лична бележка'], ['reason_to_parents' => true], ['notify_parents' => true], ['sessions' => array_fill(0, 51, $body['sessions'][0])]] as $extra) {
            $this->postJson('/api/v1/schedule/changes/preview', [...$body, ...$extra])->assertUnprocessable();
        }
        $this->assertDatabaseCount('schedule_changes', 0);
        $this->assertDatabaseCount('enrollment_events', 0);
    }

    public function test_panel_offers_office_substitute_and_teacher_request_only_before_start(): void
    {
        [$f, , , $teacher] = $this->assignmentFixture();
        $this->signInTo($f['owner'], $f['organization']);
        $panel = $this->getJson('/api/v1/schedule/sessions/'.$f['session']->id)->assertOk();
        $this->assertContains('substitute', $panel->json('actions'));
        $this->assertTrue($panel->json('abilities.substitute'));
        $this->signInTo($teacher, $f['organization']);
        $panel = $this->getJson('/api/v1/schedule/sessions/'.$f['session']->id)->assertOk();
        $this->assertContains('request_substitute', $panel->json('actions'));
        $this->travelTo('2026-11-03 15:00:00');
        $panel = $this->getJson('/api/v1/schedule/sessions/'.$f['session']->id)->assertOk();
        $this->assertNotContains('request_substitute', $panel->json('actions'));
    }

    public function test_a_person_with_added_assignment_right_cannot_place_themselves(): void
    {
        [$f, $original] = $this->assignmentFixture();
        $actor = $this->memberOf($f['organization'], 'viewer');
        $person = $this->personFor($f['organization'], $actor, true);
        $ownProfile = $this->profileOf($f['organization'], $person);
        $this->inTenant($f['organization'], function () use ($f, $person): void {
            $person->update(['access_scope' => 'all']);
            foreach (['schedule.manage', 'team.assign'] as $ability) {
                StaffGrant::create(['staff_member_id' => $person->id, 'ability' => $ability, 'granted_by' => $f['owner']->id, 'granted_at' => now()]);
            }
        });
        $this->signInTo($actor, $f['organization'])->withHeader('Idempotency-Key', 'self');
        $body = ['action' => 'substitute', 'sessions' => [['id' => $f['session']->id, 'version' => 1]], 'replaces_profile_id' => $original->id, 'substitute_profile_id' => $ownProfile->id];
        foreach (['/api/v1/schedule/changes/preview', '/api/v1/schedule/changes'] as $url) {
            $this->postJson($url, $body)->assertForbidden()->assertJsonPath('message', 'Не можете да се назначите сами.');
        }
        $this->assertDatabaseCount('schedule_changes', 0);
    }

    #[Group('tenant-isolation')]
    public function test_foreign_session_is_hidden_and_foreign_substitute_rejected_without_partial_changes(): void
    {
        [$f, $original, $substitute] = $this->assignmentFixture();
        [$foreign, , $foreignSubstitute] = $this->assignmentFixture();
        $this->signInTo($f['owner'], $f['organization'])->withHeader('Idempotency-Key', 'foreign');
        $body = ['action' => 'substitute', 'sessions' => [['id' => $foreign['session']->id, 'version' => 1]], 'replaces_profile_id' => $original->id, 'substitute_profile_id' => $substitute->id];
        $this->postJson('/api/v1/schedule/changes', $body)->assertNotFound();
        $this->postJson('/api/v1/schedule/changes', [...$body, 'sessions' => [['id' => $f['session']->id, 'version' => 1]], 'substitute_profile_id' => $foreignSubstitute->id])->assertUnprocessable();
        $this->assertDatabaseCount('schedule_changes', 0);
        $this->assertDatabaseCount('enrollment_events', 0);
    }

    public function test_own_open_request_hides_the_second_request_action(): void
    {
        [$f, $original, , $teacher] = $this->assignmentFixture();
        $this->signInTo($teacher, $f['organization'])->withHeader('Idempotency-Key', 'request');
        $this->postJson('/api/v1/me/substitute-requests', ['sessions' => [['id' => $f['session']->id, 'version' => 1]], 'reason' => 'personal'])->assertCreated();
        $panel = $this->getJson('/api/v1/schedule/sessions/'.$f['session']->id)->assertOk();
        $this->assertNotContains('request_substitute', $panel->json('actions'));
    }

    public function test_substitute_is_allowed_during_the_fifteen_minutes_before_start(): void
    {
        [$f, $original, $substitute] = $this->assignmentFixture();
        $this->travelTo('2026-11-03 14:50:00');
        $this->signInTo($f['owner'], $f['organization'])->withHeader('Idempotency-Key', 'ten-minutes');
        $panel = $this->getJson('/api/v1/schedule/sessions/'.$f['session']->id)->assertOk();
        $this->assertContains('substitute', $panel->json('actions'));
        $this->assertNotContains('move', $panel->json('actions'));
        $this->postJson('/api/v1/schedule/changes', ['action' => 'substitute', 'sessions' => [['id' => $f['session']->id, 'version' => 1]], 'replaces_profile_id' => $original->id, 'substitute_profile_id' => $substitute->id])->assertCreated();
    }

    public function test_substitution_keeps_move_reason_and_separates_the_private_history(): void
    {
        [$f, $original, $substitute, $teacher] = $this->assignmentFixture();
        $this->signInTo($f['owner'], $f['organization'])->withHeader('Idempotency-Key', 'move-before-substitute');
        $this->postJson('/api/v1/schedule/changes', ['action' => 'move', 'sessions' => [['id' => $f['session']->id, 'version' => 1]], 'date' => '2026-11-03', 'from' => '18:00', 'to' => '19:00', 'reason' => 'Залата е заета.'])->assertCreated();
        $this->withHeader('Idempotency-Key', 'substitute-after-move');
        $this->postJson('/api/v1/schedule/changes', ['action' => 'substitute', 'sessions' => [['id' => $f['session']->id, 'version' => 2]], 'replaces_profile_id' => $original->id, 'substitute_profile_id' => $substitute->id])->assertCreated();
        $panel = $this->getJson('/api/v1/schedule/sessions/'.$f['session']->id)->assertOk()->assertJsonPath('last_change.action', 'move')->assertJsonPath('last_change.reason', 'Залата е заета.')->assertJsonPath('session.change_reason', 'Залата е заета.');
        $this->assertNotNull($panel->json('last_substitute.by'));
        $this->signInTo($teacher, $f['organization']);
        $this->getJson('/api/v1/schedule/sessions/'.$f['session']->id)->assertOk()->assertJsonPath('last_substitute', null)->assertJsonMissingPath('last_change.by')->assertJsonMissingPath('last_change.reason');
    }
}
