<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Models\AttendanceSession;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TeamRightsFixture;
use Tests\TestCase;

class RecordAttendanceTest extends TestCase
{
    use RefreshDatabase, TeamRightsFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->travelTo('2026-11-03T14:45:00Z');
    }

    public function test_mark_clear_and_replay_use_versions_and_preserve_the_original_applied_answer(): void
    {
        $f = $this->rightsFixture();
        $uri = '/api/v1/attendance/sessions/'.$f['session']->id.'/changes';
        $body = ['action' => 'mark', 'marks' => [['enrollment_id' => $f['enrollment']->id, 'status' => 'present', 'version' => 0]]];
        $this->signInTo($f['owner'], $f['organization'])->withHeader('Idempotency-Key', 'first');
        $first = $this->postJson($uri, $body)->assertOk()->assertJsonPath('applied.0.version', 1)->assertJsonPath('applied.0.from', null)->assertJsonPath('applied.0.to', 'present')->json('applied');
        $this->withHeader('Idempotency-Key', 'clear')->postJson($uri, ['action' => 'mark', 'marks' => [['enrollment_id' => $f['enrollment']->id, 'status' => null, 'version' => 1]]])->assertOk()->assertJsonPath('applied.0.version', 2);
        $this->withHeader('Idempotency-Key', 'first')->postJson($uri, $body)->assertOk()->assertJsonPath('applied', $first)->assertJsonPath('children.0.status', null);
        $this->assertSame(2, DB::table('attendance_mark_changes')->count());
        $this->assertSame(0, DB::table('audit_events')->where('action', 'attendance.corrected_late')->count());
        $body['marks'][0]['status'] = 'absent';
        $this->postJson($uri, $body)->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    }

    public function test_conflicts_roll_back_the_entire_batch_and_same_status_with_an_old_version_is_a_noop(): void
    {
        $f = $this->rightsFixture();
        $uri = '/api/v1/attendance/sessions/'.$f['session']->id.'/changes';
        $this->signInTo($f['owner'], $f['organization'])->withHeader('Idempotency-Key', 'initial')->postJson($uri,
            ['action' => 'mark', 'marks' => [['enrollment_id' => $f['enrollment']->id, 'status' => 'present', 'version' => 0]]])->assertOk();
        $this->withHeader('Idempotency-Key', 'old-same')->postJson($uri,
            ['action' => 'mark', 'marks' => [['enrollment_id' => $f['enrollment']->id, 'status' => 'present', 'version' => 0]]])->assertOk()->assertJsonCount(0, 'applied');
        $this->withHeader('Idempotency-Key', 'conflict')->postJson($uri, ['action' => 'mark', 'marks' => [
            ['enrollment_id' => $f['visit']->id, 'status' => 'present', 'version' => 0],
            ['enrollment_id' => $f['enrollment']->id, 'status' => 'absent', 'version' => 0],
        ]])->assertConflict()->assertJsonPath('conflicts', [$f['enrollment']->id])->assertJsonPath('children.0.status', 'present');
        $this->assertSame(1, DB::table('attendance_marks')->count());
        $this->assertSame(1, DB::table('attendance_mark_changes')->count());
    }

    public function test_all_present_skips_warned_paused_and_already_marked_children(): void
    {
        $f = $this->rightsFixture();
        $this->inTenant($f['organization'], fn () => $f['enrollment']->sessionExceptions()->create(['session_id' => $f['session']->id, 'kind' => 'excluded', 'created_by' => $f['owner']->id]));
        $uri = '/api/v1/attendance/sessions/'.$f['session']->id.'/changes';
        $this->signInTo($f['owner'], $f['organization'])->withHeader('Idempotency-Key', 'all')->postJson($uri, ['action' => 'all_present'])
            ->assertOk()->assertJsonCount(1, 'applied')->assertJsonPath('applied.0.enrollment_id', $f['visit']->id)->assertJsonPath('counts.warned', 1);
        $this->withHeader('Idempotency-Key', 'again')->postJson($uri, ['action' => 'all_present'])->assertOk()->assertJsonCount(0, 'applied');
        $this->assertSame('all_present', DB::table('attendance_mark_changes')->value('kind'));
    }

    public function test_late_corrections_require_a_reason_keep_it_only_in_history_and_never_allow_clear(): void
    {
        $f = $this->rightsFixture();
        $uri = '/api/v1/attendance/sessions/'.$f['session']->id.'/changes';
        $body = ['action' => 'mark', 'marks' => [['enrollment_id' => $f['enrollment']->id, 'status' => 'absent', 'version' => 0]]];
        $this->travelTo('2026-11-04T22:00:00Z');
        $this->signInTo($f['owner'], $f['organization'])->withHeader('Idempotency-Key', 'late')->postJson($uri, $body)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $body['reason'] = '  Сверка с учителя  ';
        $this->postJson($uri, $body)->assertOk();
        $this->assertSame('late', DB::table('attendance_mark_changes')->value('kind'));
        $this->assertSame('Сверка с учителя', DB::table('attendance_mark_changes')->value('reason'));
        $audit = DB::table('audit_events')->where('action', 'attendance.corrected_late')->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('Сверка', $audit->metadata);
        $body['marks'][0] = ['enrollment_id' => $f['enrollment']->id, 'status' => null, 'version' => 1];
        $this->withHeader('Idempotency-Key', 'late-clear')->postJson($uri, $body)->assertUnprocessable();
        $coordinator = $this->memberOf($f['organization'], 'coordinator');
        $this->signInTo($coordinator, $f['organization'])->withHeader('Idempotency-Key', 'forbidden')->postJson($uri, ['action' => 'all_present'])->assertForbidden();
    }

    public function test_replay_precedes_deadline_and_normalizes_uuid_case_and_reason_whitespace(): void
    {
        $f = $this->rightsFixture();
        $teacher = $this->memberOf($f['organization'], 'instructor');
        $person = $this->personFor($f['organization'], $teacher, true);
        $profile = $this->profileOf($f['organization'], $person);
        $this->inTenant($f['organization'], fn () => $f['tuesday']->assignments()->attach($profile->id, ['role' => 'lead']));
        $body = ['action' => 'mark', 'reason' => '  пояснение ', 'marks' => [['enrollment_id' => strtoupper($f['enrollment']->id), 'version' => 0, 'status' => 'present']]];
        $uri = '/api/v1/attendance/sessions/'.$f['session']->id.'/changes';
        $first = $this->signInTo($teacher, $f['organization'])->withHeader('Idempotency-Key', 'deadline')->postJson($uri, $body)->assertOk()->json('applied');
        $this->assertNull(DB::table('attendance_mark_changes')->value('reason'));
        $this->travelTo('2026-11-04T22:00:01Z');
        $body['reason'] = 'пояснение';
        $body['marks'][0]['enrollment_id'] = strtolower($body['marks'][0]['enrollment_id']);
        $this->postJson($uri, $body)->assertOk()->assertJsonPath('applied', $first);
        $this->assertSame(1, DB::table('attendance_mark_changes')->count());
    }

    public function test_state_roster_and_input_guards_do_not_write_anything(): void
    {
        $f = $this->rightsFixture();
        $uri = '/api/v1/attendance/sessions/'.$f['session']->id.'/changes';
        $body = ['action' => 'mark', 'marks' => [['enrollment_id' => $f['enrollment']->id, 'status' => 'present', 'version' => 0]]];
        $this->signInTo($f['owner'], $f['organization'])->postJson($uri, $body)->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'guard');
        $this->travelTo('2026-11-03T14:44:59Z');
        $this->postJson($uri, $body)->assertUnprocessable();
        $this->travelTo('2026-11-03T14:45:00Z');
        $this->inTenant($f['organization'], fn () => $f['enrollment']->pauses()->create(['starts_on' => '2026-11-03', 'ends_on' => '2026-11-06', 'created_by' => $f['owner']->id, 'reason_category' => 'other', 'reason' => 'Пътуване']));
        $body['marks'][0]['status'] = 'absent';
        $this->postJson($uri, $body)->assertUnprocessable();
        $body['marks'][0]['enrollment_id'] = (string) Str::uuid();
        $this->postJson($uri, $body)->assertUnprocessable();
        $this->assertSame(0, DB::table('attendance_marks')->count());
    }

    public function test_all_present_does_not_touch_a_pause_or_an_absent_mark_but_present_is_allowed_during_pause(): void
    {
        $f = $this->rightsFixture();
        $this->inTenant($f['organization'], fn () => $f['enrollment']->pauses()->create(['starts_on' => '2026-11-03', 'ends_on' => '2026-11-06', 'created_by' => $f['owner']->id, 'reason_category' => 'other', 'reason' => 'Пътуване']));
        $uri = '/api/v1/attendance/sessions/'.$f['session']->id.'/changes';
        $this->signInTo($f['owner'], $f['organization'])->withHeader('Idempotency-Key', 'absent')->postJson($uri,
            ['action' => 'mark', 'marks' => [['enrollment_id' => $f['visit']->id, 'status' => 'absent', 'version' => 0]]])->assertOk();
        $this->withHeader('Idempotency-Key', 'all')->postJson($uri, ['action' => 'all_present'])->assertOk()->assertJsonCount(0, 'applied');
        $this->withHeader('Idempotency-Key', 'paused-present')->postJson($uri,
            ['action' => 'mark', 'marks' => [['enrollment_id' => $f['enrollment']->id, 'status' => 'present', 'version' => 0]]])->assertOk()
            ->assertJsonPath('counts.present', 0)->assertJsonPath('counts.present_paused', 1);
        $this->withHeader('Idempotency-Key', 'paused-clear')->postJson($uri,
            ['action' => 'mark', 'marks' => [['enrollment_id' => $f['enrollment']->id, 'status' => null, 'version' => 1]]])->assertOk()->assertJsonPath('counts.present_paused', 0);
    }

    public function test_duplicate_ids_case_variants_and_cross_session_keys_are_rejected(): void
    {
        $f = $this->rightsFixture();
        $uri = '/api/v1/attendance/sessions/'.$f['session']->id.'/changes';
        $this->signInTo($f['owner'], $f['organization'])->withHeader('Idempotency-Key', 'duplicates')->postJson($uri,
            ['action' => 'mark', 'marks' => [
                ['enrollment_id' => $f['enrollment']->id, 'status' => 'present', 'version' => 0],
                ['enrollment_id' => strtoupper($f['enrollment']->id), 'status' => 'absent', 'version' => 0],
            ]])->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'bound')->postJson($uri, ['action' => 'all_present'])->assertOk();
        $other = $this->inTenant($f['organization'], fn () => $f['thursday']->sessions()->firstOrFail()->id);
        $this->postJson('/api/v1/attendance/sessions/'.$other.'/changes', ['action' => 'all_present'])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->assertSame(2, DB::table('attendance_marks')->count());
    }

    public function test_cancelled_not_held_and_draft_are_refused_while_archived_group_can_still_be_marked(): void
    {
        $f = $this->rightsFixture();
        $uri = '/api/v1/attendance/sessions/'.$f['session']->id.'/changes';
        $this->inTenant($f['organization'], fn () => $f['session']->update(['status' => 'cancelled']));
        $this->signInTo($f['owner'], $f['organization'])->withHeader('Idempotency-Key', 'state')->postJson($uri, ['action' => 'all_present'])->assertUnprocessable();
        $state = $this->inTenant($f['organization'], function () use ($f) {
            $f['session']->update(['status' => 'scheduled']);

            return AttendanceSession::query()->create(['session_id' => $f['session']->id, 'version' => 1,
                'not_held_at' => now(), 'not_held_by' => $f['owner']->id, 'not_held_reason' => 'Затворена зала']);
        });
        $this->postJson($uri, ['action' => 'all_present'])->assertUnprocessable();
        $this->inTenant($f['organization'], function () use ($f, $state): void {
            $state->update(['version' => 2, 'not_held_at' => null, 'not_held_by' => null, 'not_held_reason' => null]);
            $f['tuesday']->update(['status' => 'draft']);
        });
        $this->postJson($uri, ['action' => 'all_present'])->assertNotFound();
        $this->assertSame(0, DB::table('attendance_marks')->count());
        $this->inTenant($f['organization'], fn () => $f['tuesday']->update(['status' => 'archived']));
        $this->postJson($uri, ['action' => 'all_present'])->assertOk()->assertJsonCount(2, 'applied');
    }
}
