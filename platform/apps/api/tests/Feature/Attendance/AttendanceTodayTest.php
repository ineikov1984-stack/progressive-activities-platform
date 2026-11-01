<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Actions\RecordAttendanceSession;
use App\Domain\Catalog\Models\Session;
use App\Domain\Organizations\Models\StaffGrant;
use App\Domain\Organizations\Models\StaffScopeGroup;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\TeamRightsFixture;
use Tests\TestCase;

class AttendanceTodayTest extends TestCase
{
    use RefreshDatabase, TeamRightsFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->travelTo('2026-11-03T15:10:00Z');
    }

    public function test_today_uses_taught_sessions_roles_states_and_only_yesterdays_pending(): void
    {
        $f = $this->rightsFixture();
        $teacher = $this->memberOf($f['organization'], 'instructor');
        $person = $this->personFor($f['organization'], $teacher, true);
        $profile = $this->profileOf($f['organization'], $person);
        $this->inTenant($f['organization'], function () use ($f, $profile): void {
            $f['tuesday']->instructors()->attach($profile->id, ['role' => 'assistant', 'starts_on' => '2026-10-01']);
            $f['session']->instructors()->attach($profile->id, ['role' => 'lead']);
        });
        $yesterday = $this->copySession($f, '2026-11-02T21:30:00Z');
        $this->copySession($f, '2026-11-01T15:00:00Z');
        $cancelled = $this->copySession($f, '2026-11-03T18:00:00Z', 'cancelled');
        $notHeld = $this->copySession($f, '2026-11-03T14:00:00Z');
        $this->inTenant($f['organization'], fn () => app(RecordAttendanceSession::class)->handle($f['owner'], $notHeld, ['action' => 'not_held', 'reason' => 'Отсъствие']));
        $response = $this->signInTo($teacher, $f['organization'])->getJson('/api/v1/attendance/today')->assertOk();
        $response->assertJsonCount(3, 'sessions')->assertJsonCount(1, 'pending')->assertJsonPath('pending.0.id', $yesterday->id);
        $rows = collect($response->json('sessions'))->keyBy('id');
        $this->assertSame('now', $rows[$f['session']->id]['state']);
        $this->assertSame('assistant', $rows[$f['session']->id]['my_role']);
        $this->assertSame(2, $rows[$f['session']->id]['counts']['unmarked']);
        $this->assertTrue($rows[$f['session']->id]['can_mark']);
        $this->assertSame('cancelled', $rows[$cancelled->id]['state']);
        $this->assertSame('not_held', $rows[$notHeld->id]['state']);
        $this->assertFalse($rows[$notHeld->id]['can_mark']);
        $this->assertStringNotContainsString('Отсъствие', $response->getContent());
        $this->signInTo($f['owner'], $f['organization'])->getJson('/api/v1/attendance/today')->assertOk()->assertJsonPath('sessions', [])->assertJsonPath('pending', []);
    }

    public function test_pending_respects_deadline_scope_status_order_limit_and_total(): void
    {
        $f = $this->rightsFixture();
        for ($i = 0; $i < 22; $i++) {
            $this->copySession($f, '2026-11-02T10:'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).':00Z');
        }
        $old = $this->copySession($f, '2026-10-21T12:00:00Z');
        $this->copySession($f, '2026-10-20T12:00:00Z');
        $this->copySession($f, '2026-11-03T12:00:00Z', 'cancelled');
        $notHeld = $this->copySession($f, '2026-11-03T13:00:00Z');
        $this->inTenant($f['organization'], fn () => app(RecordAttendanceSession::class)->handle($f['owner'], $notHeld, ['action' => 'not_held', 'reason' => 'Почивка']));
        $this->signInTo($f['owner'], $f['organization'])->getJson('/api/v1/attendance/pending')->assertOk()
            ->assertJsonPath('total', 24)->assertJsonCount(20, 'sessions')->assertJsonPath('sessions.0.id', $old->id)->assertJsonPath('sessions.0.late', true);
        $coordinator = $this->memberOf($f['organization'], 'coordinator');
        $this->signInTo($coordinator, $f['organization'])->getJson('/api/v1/attendance/pending')->assertOk()->assertJsonPath('total', 23)->assertJsonPath('sessions.0.late', false);
        $viewer = $this->memberOf($f['organization'], 'viewer');
        $this->signInTo($viewer, $f['organization'])->getJson('/api/v1/attendance/pending')->assertForbidden();
        $person = $this->personFor($f['organization'], $viewer);
        $this->inTenant($f['organization'], function () use ($f, $person): void {
            $person->forceFill(['access_scope' => 'groups'])->save();
            StaffScopeGroup::query()->create(['staff_member_id' => $person->id, 'group_id' => $f['thursday']->id, 'added_by' => $f['owner']->id, 'added_at' => now()]);
            StaffGrant::query()->create(['staff_member_id' => $person->id, 'ability' => 'attendance.mark', 'granted_by' => $f['owner']->id, 'granted_at' => now()->subDay()]);
        });
        $this->signInTo($viewer, $f['organization'])->getJson('/api/v1/attendance/pending')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_schedule_attendance_is_hidden_until_started_and_panel_until_open(): void
    {
        $f = $this->rightsFixture();
        $url = '/api/v1/schedule/sessions/'.$f['session']->id;
        $this->travelTo('2026-11-03T14:44:00Z');
        $this->signInTo($f['owner'], $f['organization'])->getJson($url)->assertOk()->assertJsonPath('session.not_held', false)->assertJsonPath('session.attendance', null)->assertJsonPath('abilities.attendance', null);
        $this->travelTo('2026-11-03T14:45:00Z');
        $this->getJson($url)->assertOk()->assertJsonPath('abilities.attendance', 'mark')->assertJsonPath('session.attendance', null);
        $this->travelTo('2026-11-03T15:00:00Z');
        $this->getJson($url)->assertOk()->assertJsonPath('session.attendance.unmarked', 2);
        $this->getJson('/api/v1/schedule?from=2026-11-03&to=2026-11-03')->assertOk()->assertJsonPath('sessions.0.attendance.unmarked', 2);
        $viewer = $this->memberOf($f['organization'], 'viewer');
        $this->signInTo($viewer, $f['organization'])->getJson($url)->assertOk()->assertJsonPath('abilities.attendance', 'view');
        $this->inTenant($f['organization'], fn () => app(RecordAttendanceSession::class)->handle($f['owner'], $f['session'], ['action' => 'not_held', 'reason' => 'Причина за офиса']));
        $this->getJson($url)->assertOk()->assertJsonPath('session.not_held', true)->assertJsonPath('session.attendance', null)->assertJsonMissingPath('session.not_held_detail');
        $this->signInTo($f['owner'], $f['organization'])->getJson($url)->assertOk()->assertJsonPath('session.not_held_detail.reason', 'Причина за офиса');
        $platform = $this->memberOf($f['organization'], 'platform_admin');
        $this->signInTo($platform, $f['organization'])->getJson($url)->assertOk()->assertJsonPath('session.attendance', null)->assertJsonPath('abilities.attendance', null);
    }

    public function test_today_queries_do_not_grow_with_sessions(): void
    {
        $f = $this->rightsFixture();
        $teacher = $this->memberOf($f['organization'], 'instructor');
        $person = $this->personFor($f['organization'], $teacher, true);
        $profile = $this->profileOf($f['organization'], $person);
        $this->inTenant($f['organization'], fn () => $f['tuesday']->instructors()->attach($profile->id, ['role' => 'lead']));
        $this->copySession($f, '2026-11-03T13:00:00Z');
        $this->copySession($f, '2026-11-03T14:00:00Z');
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $count = function (int $size) use (&$queries, $teacher, $f): int {
            $this->signInTo($teacher, $f['organization'])->getJson('/api/v1/attendance/today')->assertOk();
            $queries = 0;
            $this->getJson('/api/v1/attendance/today')->assertOk()->assertJsonCount($size, 'sessions');

            return $queries;
        };
        $small = $count(3);
        for ($i = 0; $i < 27; $i++) {
            $this->copySession($f, '2026-11-03T14:00:00Z');
        }
        $this->assertSame($small, $count(30));
    }

    public function test_substitute_sees_only_their_sessions_with_replaced_lead_and_sofia_days(): void
    {
        $f = $this->rightsFixture();
        $teacher = $this->memberOf($f['organization'], 'instructor');
        $person = $this->personFor($f['organization'], $teacher, true);
        $profile = $this->profileOf($f['organization'], $person);
        $lead = $this->personFor($f['organization'], $this->memberOf($f['organization'], 'instructor'), true);
        $leadProfile = $this->profileOf($f['organization'], $lead);
        $early = $this->copySession($f, '2026-11-02T22:30:00Z'); // Tuesday 00:30 in Sofia.
        $notMine = $this->copySession($f, '2026-11-03T12:00:00Z');
        $this->copySession($f, '2026-11-03T22:30:00Z'); // Wednesday locally.
        $this->inTenant($f['organization'], function () use ($f, $profile, $leadProfile, $early): void {
            $f['tuesday']->assignments()->attach($leadProfile->id, ['role' => 'lead']);
            $f['session']->instructors()->attach($profile->id, ['role' => 'lead']);
            $early->instructors()->attach($profile->id, ['role' => 'lead']);
        });
        $response = $this->signInTo($teacher, $f['organization'])->getJson('/api/v1/attendance/today')->assertOk()->assertJsonCount(2, 'sessions');
        $response->assertJsonPath('sessions.0.id', $early->id)->assertJsonPath('sessions.1.my_role', 'substitute')->assertJsonPath('sessions.1.replaces.0', $lead->full_name);
        $this->assertNotContains($notMine->id, array_column($response->json('sessions'), 'id'));
    }

    public function test_assignment_role_survives_manual_replacement_and_cancelled_only_substitute_is_shown(): void
    {
        $f = $this->rightsFixture();
        $teacher = $this->memberOf($f['organization'], 'instructor');
        $person = $this->personFor($f['organization'], $teacher, true);
        $profile = $this->profileOf($f['organization'], $person);
        $this->inTenant($f['organization'], fn () => $f['tuesday']->assignments()->attach($profile->id, ['role' => 'assistant']));
        $this->signInTo($teacher, $f['organization'])->getJson('/api/v1/attendance/today')->assertOk()->assertJsonPath('sessions.0.my_role', 'assistant');
        $this->inTenant($f['organization'], function () use ($f, $profile): void {
            $f['tuesday']->assignments()->detach($profile->id);
            $f['session']->update(['status' => 'cancelled']);
            $f['session']->instructors()->attach($profile->id, ['role' => 'lead']);
        });
        $this->getJson('/api/v1/attendance/today')->assertOk()->assertJsonCount(1, 'sessions')->assertJsonPath('sessions.0.state', 'cancelled');
    }

    #[Group('tenant-isolation')]
    public function test_lists_and_schedule_never_include_a_foreign_organizations_sessions(): void
    {
        $f = $this->rightsFixture();
        $foreign = $this->rightsFixture();
        $this->signInTo($f['owner'], $f['organization'])->getJson('/api/v1/attendance/pending')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('sessions.0.id', $f['session']->id);
        $this->getJson('/api/v1/schedule/sessions/'.$foreign['session']->id)->assertNotFound();
        $this->getJson('/api/v1/attendance/today')->assertOk()->assertJsonPath('sessions', []);
    }

    public function test_monday_does_not_return_friday_and_pending_excludes_fully_marked_sessions(): void
    {
        $f = $this->rightsFixture();
        $teacher = $this->memberOf($f['organization'], 'instructor');
        $person = $this->personFor($f['organization'], $teacher, true);
        $profile = $this->profileOf($f['organization'], $person);
        $this->inTenant($f['organization'], fn () => $f['tuesday']->assignments()->attach($profile->id, ['role' => 'lead']));
        $this->copySession($f, '2026-11-06T15:00:00Z');
        $this->travelTo('2026-11-09T10:00:00Z');
        $this->signInTo($teacher, $f['organization'])->getJson('/api/v1/attendance/today')->assertOk()->assertJsonPath('pending', []);
        $this->travelTo('2026-11-03T15:10:00Z');
        $this->signInTo($f['owner'], $f['organization'])->withHeader('Idempotency-Key', 'mark-all-for-pending')->postJson('/api/v1/attendance/sessions/'.$f['session']->id.'/changes', ['action' => 'all_present'])->assertOk();
        $this->getJson('/api/v1/attendance/pending')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_yesterdays_ended_assignment_remains_pending_and_start_is_inclusive(): void
    {
        $f = $this->rightsFixture();
        $teacher = $this->memberOf($f['organization'], 'instructor');
        $person = $this->personFor($f['organization'], $teacher, true);
        $profile = $this->profileOf($f['organization'], $person);
        $yesterday = $this->copySession($f, '2026-11-02T14:00:00Z');
        $this->inTenant($f['organization'], fn () => $f['tuesday']->assignments()->attach($profile->id, ['role' => 'assistant', 'starts_on' => '2026-10-01', 'ends_on' => '2026-11-02']));
        $this->signInTo($teacher, $f['organization'])->getJson('/api/v1/attendance/today')->assertOk()->assertJsonPath('sessions', [])->assertJsonPath('pending.0.id', $yesterday->id);
        $this->travelTo('2026-11-03T15:00:00Z');
        $this->signInTo($f['owner'], $f['organization'])->getJson('/api/v1/attendance/pending')->assertOk()->assertJsonPath('total', 2);
    }

    private function copySession(array $f, string $start, string $status = 'scheduled'): Session
    {
        return $this->inTenant($f['organization'], function () use ($f, $start, $status): Session {
            $session = $f['session']->replicate();
            $session->starts_at = $start;
            $session->ends_at = CarbonImmutable::parse($start)->addHour();
            $session->status = $status;
            $session->save();

            return $session;
        });
    }
}
