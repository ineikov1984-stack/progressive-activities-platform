<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Models\AttendanceSession;
use App\Domain\Organizations\Models\OrganizationMembership;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\TeamRightsFixture;
use Tests\TestCase;

#[Group('tenant-isolation')]
class AttendanceVisibilityTest extends TestCase
{
    use RefreshDatabase, TeamRightsFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->travelTo('2026-11-03T15:00:00Z');
    }

    public function test_history_and_skip_names_are_filtered_for_owner_teacher_and_substitute(): void
    {
        $f = $this->rightsFixture();
        $teacher = $this->memberOf($f['organization'], 'instructor');
        $person = $this->personFor($f['organization'], $teacher, true);
        $profile = $this->profileOf($f['organization'], $person);
        $substitute = $this->memberOf($f['organization'], 'instructor');
        $subPerson = $this->personFor($f['organization'], $substitute, true);
        $subProfile = $this->profileOf($f['organization'], $subPerson);
        $this->inTenant($f['organization'], function () use ($f, $profile, $subProfile, $person): void {
            $f['tuesday']->assignments()->attach($profile->id, ['role' => 'lead']);
            $f['session']->instructors()->attach($subProfile->id, ['role' => 'lead']);
            $f['enrollment']->sessionExceptions()->create(['session_id' => $f['session']->id, 'kind' => 'excluded', 'created_by' => $f['owner']->id]);
            $mark = (string) Str::uuid();
            DB::table('attendance_marks')->insert(['id' => $mark, 'organization_id' => $f['organization']->id, 'session_id' => $f['session']->id,
                'enrollment_id' => $f['enrollment']->id, 'status' => 'absent', 'version' => 1, 'marked_by' => $person->user_id, 'marked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('attendance_mark_changes')->insert(['id' => (string) Str::uuid(), 'organization_id' => $f['organization']->id, 'mark_id' => $mark,
                'from_status' => null, 'to_status' => 'absent', 'version' => 1, 'kind' => 'late', 'reason' => 'Поправка след срока',
                'actor_user_id' => $person->user_id, 'request_key' => 'synthetic', 'request_hash' => str_repeat('a', 64), 'created_at' => now()]);
        });
        $uri = '/api/v1/attendance/sessions/'.$f['session']->id;
        $ownerRows = $this->signInTo($f['owner'], $f['organization'])->getJson($uri)->assertOk()->json();
        $this->assertSame('Поправка след срока', $ownerRows['history'][0]['reason']);
        $this->assertSame($person->full_name, $ownerRows['history'][0]['by']);
        $this->assertSame($f['enrollment']->child_id, $ownerRows['children'][0]['child_id']);
        $this->assertArrayHasKey('by_name', $ownerRows['children'][0]['skip']);
        $teacherRows = $this->signInTo($teacher, $f['organization'])->getJson($uri)->assertOk()->json();
        $this->assertArrayNotHasKey('reason', $teacherRows['history'][0]);
        $this->assertArrayNotHasKey('by_name', $teacherRows['children'][0]['skip']);
        $this->assertSame('team', $teacherRows['children'][0]['skip']['by']);
        $subRows = $this->signInTo($substitute, $f['organization'])->getJson($uri)->assertOk()->json();
        foreach ($subRows['children'] as $row) {
            $this->assertArrayNotHasKey('child_id', $row);
        }
        $this->inTenant($f['organization'], fn () => $person->update(['archived_at' => now()]));
        $this->assertSame($teacher->name, $this->signInTo($f['owner'], $f['organization'])->getJson($uri)->assertOk()->json('history.0.by'));
    }

    public function test_foreign_draft_inactive_and_platform_access_returns_not_found(): void
    {
        $f = $this->rightsFixture();
        $other = $this->rightsFixture();
        $uri = '/api/v1/attendance/sessions/'.$f['session']->id;
        $this->signInTo($other['owner'], $other['organization'])->getJson($uri)->assertNotFound();
        $platform = $this->memberOf($f['organization'], 'platform_admin');
        $this->signInTo($platform, $f['organization'])->getJson($uri)->assertNotFound();
        $this->inTenant($f['organization'], fn () => $f['tuesday']->update(['status' => 'draft']));
        $this->signInTo($f['owner'], $f['organization'])->getJson($uri)->assertNotFound();
        OrganizationMembership::query()->where('organization_id', $f['organization']->id)->where('user_id', $f['owner']->id)->update(['status' => 'inactive']);
        $this->getJson($uri)->assertNotFound();
    }

    public function test_viewer_sees_not_held_state_but_not_the_office_reason_or_actor(): void
    {
        $f = $this->rightsFixture();
        $viewer = $this->memberOf($f['organization'], 'viewer');
        $this->inTenant($f['organization'], fn () => AttendanceSession::query()->create(['session_id' => $f['session']->id, 'version' => 1,
            'not_held_at' => now(), 'not_held_by' => $f['owner']->id, 'not_held_reason' => 'Вътрешна причина']));
        $row = $this->signInTo($viewer, $f['organization'])->getJson('/api/v1/attendance/sessions/'.$f['session']->id)->assertOk()->json('session.not_held');
        $this->assertArrayHasKey('at', $row);
        $this->assertArrayNotHasKey('by', $row);
        $this->assertArrayNotHasKey('reason', $row);
    }

    public function test_history_is_limited_to_the_latest_two_hundred_changes(): void
    {
        $f = $this->rightsFixture();
        $this->inTenant($f['organization'], function () use ($f): void {
            $mark = (string) Str::uuid();
            DB::table('attendance_marks')->insert(['id' => $mark, 'organization_id' => $f['organization']->id, 'session_id' => $f['session']->id,
                'enrollment_id' => $f['enrollment']->id, 'status' => 'present', 'version' => 205, 'marked_by' => $f['owner']->id, 'marked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $rows = [];
            for ($i = 1; $i <= 205; $i++) {
                $rows[] = ['id' => (string) Str::uuid(), 'organization_id' => $f['organization']->id, 'mark_id' => $mark,
                    'from_status' => $i % 2 === 1 ? 'absent' : 'present', 'to_status' => $i % 2 === 1 ? 'present' : 'absent', 'version' => $i,
                    'kind' => 'mark', 'reason' => null, 'actor_user_id' => $f['owner']->id, 'request_key' => 'key-'.$i,
                    'request_hash' => str_repeat('a', 64), 'created_at' => now()->subMinutes(205 - $i)];
            }
            DB::table('attendance_mark_changes')->insert($rows);
        });
        $history = $this->signInTo($f['owner'], $f['organization'])->getJson('/api/v1/attendance/sessions/'.$f['session']->id)->assertOk()->assertJsonCount(200, 'history')->json('history');
        $this->assertSame('present', $history[0]['to']);
        $this->assertSame('2026-11-03T15:00:00+00:00', $history[0]['at']);
        $this->assertSame('2026-11-03T11:41:00+00:00', $history[199]['at']);
    }
}
