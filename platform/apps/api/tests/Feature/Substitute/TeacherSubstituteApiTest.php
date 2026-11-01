<?php

namespace Tests\Feature\Substitute;

use App\Domain\Audit\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\TeamRightsFixture;
use Tests\TestCase;

class TeacherSubstituteApiTest extends TestCase
{
    use RefreshDatabase, TeamRightsFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 1)->startOfDay());
    }

    public function test_team_member_without_profile_has_empty_lists_and_validates_writes(): void
    {
        $f = $this->rightsFixture();
        $this->signInTo($f['owner'], $f['organization']);
        $this->getJson('/api/v1/me/substitute-requests')->assertOk()->assertJsonPath('requests', [])->assertJsonPath('standing_in', []);
        $this->getJson('/api/v1/me/substitute-requests/options?from=2026-11-03')->assertOk()->assertJsonPath('sessions', [])->assertJsonPath('colleagues', []);
        $this->postJson('/api/v1/me/substitute-requests', [])->assertUnprocessable();
        $this->postJson('/api/v1/me/substitute-requests/'.Str::uuid().'/withdraw', ['version' => 1])->assertNotFound();
    }

    public function test_operator_is_rejected_before_body_or_date_validation(): void
    {
        $f = $this->rightsFixture();
        $operator = $this->memberOf($f['organization'], 'platform_admin');
        $this->signInTo($operator, $f['organization']);
        $this->getJson('/api/v1/me/substitute-requests')->assertForbidden();
        $this->getJson('/api/v1/me/substitute-requests/options?from=garbage')->assertForbidden();
        $this->postJson('/api/v1/me/substitute-requests', [])->assertForbidden();
        $this->postJson('/api/v1/me/substitute-requests/'.Str::uuid().'/withdraw', [])->assertForbidden();
    }

    public function test_teacher_can_submit_replay_and_withdraw_without_changing_the_session(): void
    {
        $f = $this->rightsFixture();
        $teacher = $this->memberOf($f['organization'], 'instructor');
        $person = $this->personFor($f['organization'], $teacher, true);
        $profile = $this->profileOf($f['organization'], $person);
        $this->inTenant($f['organization'], fn () => $f['session']->instructors()->attach($profile->id, ['organization_id' => $f['organization']->id, 'role' => 'lead']));
        $this->signInTo($teacher, $f['organization']);
        $this->getJson('/api/v1/me/substitute-requests/options?from=2026-11-03')->assertOk()->assertJsonPath('sessions.0.id', $f['session']->id)->assertJsonPath('sessions.0.requested', false);
        $body = ['sessions' => [['id' => strtoupper($f['session']->id), 'version' => 1]], 'reason' => 'personal', 'note' => '  Лична бележка  '];
        $this->withHeader('Idempotency-Key', 'Teacher-request-01');
        $created = $this->postJson('/api/v1/me/substitute-requests', $body)->assertCreated()->assertJsonPath('version', 1)->assertJsonPath('note', 'Лична бележка');
        $id = $created->json('id');
        $this->postJson('/api/v1/me/substitute-requests', $body)->assertOk()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('substitute_requests', 1);
        $this->assertDatabaseCount('enrollment_events', 1);
        $this->assertDatabaseHas('activity_sessions', ['id' => $f['session']->id, 'version' => 1, 'status' => 'scheduled']);
        $this->postJson('/api/v1/me/substitute-requests', [...$body, 'reason' => 'vacation'])->assertUnprocessable();
        $this->getJson('/api/v1/me/substitute-requests')->assertOk()->assertJsonPath('requests.0.id', $id);
        $this->postJson('/api/v1/me/substitute-requests/'.$id.'/withdraw', ['version' => 2])->assertConflict();
        $this->postJson('/api/v1/me/substitute-requests/'.$id.'/withdraw', ['version' => 1])->assertOk()->assertJsonPath('version', 2)->assertJsonPath('sessions.0.state', 'withdrawn');
        $this->getJson('/api/v1/me/substitute-requests')->assertOk()->assertJsonPath('requests', []);
        $this->assertDatabaseCount('enrollment_events', 1);
        $this->postJson('/api/v1/me/substitute-requests', $body)->assertOk()->assertJsonPath('sessions.0.state', 'withdrawn');
    }

    #[Group('tenant-isolation')]
    public function test_other_members_cannot_submit_or_withdraw_someone_elses_sessions_and_requests(): void
    {
        $f = $this->rightsFixture();
        $teacher = $this->memberOf($f['organization'], 'instructor');
        $person = $this->personFor($f['organization'], $teacher, true);
        $profile = $this->profileOf($f['organization'], $person);
        $this->inTenant($f['organization'], fn () => $f['session']->instructors()->attach($profile->id, ['organization_id' => $f['organization']->id, 'role' => 'lead']));
        $body = ['sessions' => [['id' => $f['session']->id, 'version' => 1]], 'reason' => 'other'];
        $this->signInTo($teacher, $f['organization'])->withHeader('Idempotency-Key', 'owned-request');
        $id = $this->postJson('/api/v1/me/substitute-requests', $body)->assertCreated()->json('id');
        $this->signInTo($f['owner'], $f['organization'])->withHeader('Idempotency-Key', 'not-my-request');
        $this->postJson('/api/v1/me/substitute-requests', $body)->assertNotFound();
        $this->postJson('/api/v1/me/substitute-requests/'.$id.'/withdraw', ['version' => 1])->assertNotFound();
        $this->getJson('/api/v1/me/substitute-requests')->assertOk()->assertJsonPath('requests', []);
        $foreign = $this->rightsFixture();
        $this->signInTo($foreign['owner'], $foreign['organization']);
        $this->postJson('/api/v1/me/substitute-requests', $body)->assertNotFound();
        $this->postJson('/api/v1/me/substitute-requests/'.$id.'/withdraw', ['version' => 1])->assertNotFound();
        $this->assertDatabaseCount('substitute_requests', 1);
        $this->assertDatabaseCount('enrollment_events', 1);
    }

    public function test_substitute_audit_is_visible_only_to_people_who_manage_the_team(): void
    {
        $f = $this->rightsFixture();
        $this->inTenant($f['organization'], function () use ($f): void {
            foreach (['schedule.substitute_requested', 'schedule.substitute_request_withdrawn'] as $action) {
                AuditEvent::query()->create(['organization_id' => $f['organization']->id, 'correlation_id' => (string) Str::uuid(), 'actor_id' => $f['owner']->id, 'action' => $action, 'subject_type' => 'substitute_request', 'subject_id' => (string) Str::uuid(), 'metadata' => ['reason' => 'personal']]);
            }
        });
        foreach (['finance', 'viewer', 'coordinator'] as $role) {
            $member = $this->memberOf($f['organization'], $role);
            $this->signInTo($member, $f['organization'])->getJson('/api/v1/audit-events')->assertOk()->assertJsonPath('events', []);
        }
        $this->signInTo($f['owner'], $f['organization'])->getJson('/api/v1/audit-events')->assertOk()->assertJsonCount(2, 'events');
    }

    public function test_options_reject_invalid_and_out_of_range_dates_with_clear_message(): void
    {
        $f = $this->rightsFixture();
        $this->signInTo($f['owner'], $f['organization']);
        foreach (['', '?from=invalid', '?from=2026-02-30', '?from=2026-10-31', '?from=2026-11-01&to=2026-12-04', '?from=2027-11-02', '?from=2026-11-03&to=2026-11-02'] as $query) {
            $this->getJson('/api/v1/me/substitute-requests/options'.$query)->assertUnprocessable()
                ->assertJsonPath('message', 'Изберете ден от днес до една година напред (най-много 31 дни).');
        }
    }
}
