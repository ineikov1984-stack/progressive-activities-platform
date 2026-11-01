<?php

namespace Tests\Feature\Substitute;

use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Catalog\Models\SubstituteRequest;
use App\Domain\Catalog\Models\SubstituteRequestSession;
use App\Domain\Enrollment\Delivery\ComposeDelivery;
use App\Domain\Enrollment\Delivery\DeliveryRecipients;
use App\Domain\Enrollment\Models\EnrollmentEvent;
use App\Domain\Enrollment\Models\EnrollmentEventDelivery;
use App\Domain\Organizations\Models\StaffGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\TeamRightsFixture;
use Tests\TestCase;

class OfficeSubstituteApiTest extends TestCase
{
    use RefreshDatabase, TeamRightsFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-01 10:00:00');
    }

    public function test_office_routes_are_available_and_validate_missing_input(): void
    {
        $f = $this->rightsFixture();
        $this->signInTo($f['owner'], $f['organization']);
        $this->getJson('/api/v1/schedule/substitute-requests')->assertOk()->assertJsonPath('requests', []);
        $this->getJson('/api/v1/schedule/substitute-requests/'.Str::uuid())->assertNotFound();
        $this->postJson('/api/v1/schedule/substitute-requests/'.Str::uuid().'/decline', ['version' => 1])->assertNotFound();
        $this->getJson('/api/v1/schedule/substitutes/candidates')->assertUnprocessable();
    }

    public function test_unauthorized_roles_are_rejected_before_validation_and_resource_lookup(): void
    {
        $f = $this->rightsFixture();
        foreach (['platform_admin', 'instructor', 'finance', 'viewer'] as $role) {
            $actor = $this->memberOf($f['organization'], $role);
            $this->signInTo($actor, $f['organization']);
            $this->getJson('/api/v1/schedule/substitute-requests')->assertForbidden();
            $this->getJson('/api/v1/schedule/substitute-requests/'.Str::uuid())->assertForbidden();
            $this->postJson('/api/v1/schedule/substitute-requests/'.Str::uuid().'/decline', [])->assertForbidden();
            $this->getJson('/api/v1/schedule/substitutes/candidates?sessions=invalid&replaces=invalid')->assertForbidden();
        }
    }

    public function test_office_can_read_and_decline_once_with_one_letter_for_the_teacher(): void
    {
        $f = $this->rightsFixture();
        $teacher = $this->memberOf($f['organization'], 'instructor');
        $person = $this->personFor($f['organization'], $teacher, true);
        $profile = $this->profileOf($f['organization'], $person);
        $this->inTenant($f['organization'], fn () => $f['session']->instructors()->attach($profile->id, ['organization_id' => $f['organization']->id, 'role' => 'lead']));
        $this->signInTo($teacher, $f['organization'])->withHeader('Idempotency-Key', 'office-flow');
        $id = $this->postJson('/api/v1/me/substitute-requests', ['sessions' => [['id' => $f['session']->id, 'version' => 1]], 'reason' => 'personal', 'note' => 'Лична бележка'])->assertCreated()->json('id');
        $this->signInTo($f['owner'], $f['organization']);
        $this->getJson('/api/v1/schedule/substitute-requests')->assertOk()->assertJsonPath('requests.0.id', $id)->assertJsonPath('requests.0.requester.profile_id', $profile->id);
        $this->getJson('/api/v1/schedule/substitute-requests/'.$id)->assertOk()->assertJsonPath('note', 'Лична бележка')->assertJsonPath('sessions.0.version', 1);
        $this->postJson('/api/v1/schedule/substitute-requests/'.$id.'/decline', ['version' => 99])->assertConflict();
        $this->postJson('/api/v1/schedule/substitute-requests/'.$id.'/decline', ['version' => 1])->assertOk()->assertJsonPath('version', 2)->assertJsonPath('sessions.0.state', 'declined');
        $this->postJson('/api/v1/schedule/substitute-requests/'.$id.'/decline', ['version' => 2])->assertUnprocessable()->assertJsonPath('request.sessions.0.state', 'declined');
        $this->getJson('/api/v1/schedule/substitute-requests')->assertOk()->assertJsonPath('requests', []);
        $this->assertDatabaseCount('enrollment_events', 2);
        $this->assertDatabaseHas('activity_sessions', ['id' => $f['session']->id, 'version' => 1, 'status' => 'scheduled']);
        $this->inTenant($f['organization'], function () use ($teacher): void {
            $event = EnrollmentEvent::where('type', 'substitute.declined')->firstOrFail();
            $this->assertSame($teacher->id, $event->payload['user_id']);
            $row = app(DeliveryRecipients::class)->for($event)[0];
            $delivery = EnrollmentEventDelivery::create([...$row, 'event_id' => $event->id]);
            $this->assertSame($teacher->email, app(ComposeDelivery::class)->handle($delivery)['to']);
        });
    }

    #[Group('tenant-isolation')]
    public function test_foreign_request_and_session_are_not_disclosed_or_changed(): void
    {
        $first = $this->rightsFixture();
        $second = $this->rightsFixture();
        $profile = $this->inTenant($first['organization'], fn () => InstructorProfile::create(['public_name' => 'Преподавател']));
        $request = $this->inTenant($first['organization'], function () use ($first, $profile) {
            $request = SubstituteRequest::create(['requester_user_id' => $first['owner']->id, 'requester_profile_id' => $profile->id, 'reason' => 'other', 'version' => 1, 'idempotency_key' => 'foreign', 'request_hash' => str_repeat('a', 64)]);
            SubstituteRequestSession::create(['request_id' => $request->id, 'requester_profile_id' => $profile->id, 'session_id' => $first['session']->id, 'status' => 'open']);

            return $request;
        });
        $this->signInTo($second['owner'], $second['organization']);
        $this->getJson('/api/v1/schedule/substitute-requests')->assertOk()->assertJsonPath('requests', []);
        $this->getJson('/api/v1/schedule/substitute-requests/'.$request->id)->assertNotFound();
        $this->postJson('/api/v1/schedule/substitute-requests/'.$request->id.'/decline', ['version' => 1])->assertNotFound();
        $this->getJson('/api/v1/schedule/substitutes/candidates?sessions[]='.$first['session']->id)->assertNotFound();
        $this->assertDatabaseHas('substitute_requests', ['id' => $request->id, 'version' => 1, 'closed_at' => null]);
        $this->assertDatabaseCount('enrollment_events', 0);
    }

    public function test_both_grants_for_the_whole_organization_are_required(): void
    {
        $f = $this->rightsFixture();
        $viewer = $this->memberOf($f['organization'], 'viewer');
        $person = $this->personFor($f['organization'], $viewer);
        $this->inTenant($f['organization'], function () use ($person, $f): void {
            $person->update(['access_scope' => 'all']);
            StaffGrant::create(['staff_member_id' => $person->id, 'ability' => 'schedule.manage', 'granted_by' => $f['owner']->id, 'granted_at' => now()]);
        });
        $this->signInTo($viewer, $f['organization'])->getJson('/api/v1/schedule/substitute-requests')->assertForbidden();
        $grant = $this->inTenant($f['organization'], fn () => StaffGrant::create(['staff_member_id' => $person->id, 'ability' => 'team.assign', 'granted_by' => $f['owner']->id, 'granted_at' => now()]));
        $this->getJson('/api/v1/schedule/substitute-requests')->assertOk();
        $this->getJson('/api/v1/schedule/substitutes/candidates')->assertUnprocessable();
        $this->inTenant($f['organization'], fn () => $grant->update(['expires_at' => now()->subSecond()]));
        $this->getJson('/api/v1/schedule/substitute-requests')->assertForbidden();
        $this->getJson('/api/v1/schedule/substitutes/candidates')->assertForbidden();
    }
}
