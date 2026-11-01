<?php

namespace Tests\Feature\Attendance;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\TeamRightsFixture;
use Tests\TestCase;

class RecordAttendanceSessionTest extends TestCase
{
    use RefreshDatabase, TeamRightsFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->travelTo('2026-11-03T14:45:00Z');
    }

    public function test_topic_is_trimmed_and_can_be_cleared_without_history_audit_or_schedule_version_change(): void
    {
        $f = $this->rightsFixture();
        $uri = '/api/v1/attendance/sessions/'.$f['session']->id.'/changes';
        $this->signInTo($f['owner'], $f['organization'])->postJson($uri, ['action' => 'topic', 'topic' => '  Мат с топ и цар  '])->assertOk()
            ->assertJsonPath('session.topic', 'Мат с топ и цар')->assertJsonPath('applied', []);
        $this->assertSame(1, DB::table('attendance_sessions')->value('version'));
        $this->postJson($uri, ['action' => 'topic', 'topic' => 'Мат с топ и цар'])->assertOk();
        $this->assertSame(1, DB::table('attendance_sessions')->value('version'));
        $this->postJson($uri, ['action' => 'topic', 'topic' => str_repeat('а', 201)])->assertUnprocessable()->assertJsonValidationErrors('topic');
        $this->postJson($uri, ['action' => 'topic', 'topic' => '   '])->assertOk()->assertJsonPath('session.topic', null);
        $this->assertSame(2, DB::table('attendance_sessions')->value('version'));
        $this->assertSame(0, DB::table('attendance_session_changes')->count());
        $this->assertSame(0, DB::table('audit_events')->where('action', 'like', 'attendance.%')->count());
        $this->assertSame(1, DB::table('activity_sessions')->where('id', $f['session']->id)->value('version'));
    }

    public function test_not_held_hides_existing_marks_and_held_restores_them_with_immutable_history_and_safe_audit(): void
    {
        $f = $this->rightsFixture();
        $uri = '/api/v1/attendance/sessions/'.$f['session']->id.'/changes';
        $this->signInTo($f['owner'], $f['organization'])->withHeader('Idempotency-Key', 'initial')->postJson($uri, ['action' => 'all_present'])->assertOk();
        $this->postJson($uri, ['action' => 'not_held', 'reason' => '  Повреда в залата  '])->assertOk()->assertJsonPath('counts.expected', 0)
            ->assertJsonPath('children', [])->assertJsonPath('abilities.mark', false)->assertJsonPath('abilities.held', true)->assertJsonPath('session.not_held.reason', 'Повреда в залата');
        $this->withHeader('Idempotency-Key', 'blocked')->postJson($uri, ['action' => 'all_present'])->assertUnprocessable();
        $this->postJson($uri, ['action' => 'not_held', 'reason' => 'Друга причина'])->assertOk()->assertJsonPath('session.not_held.reason', 'Повреда в залата');
        $this->assertSame(1, DB::table('attendance_session_changes')->count());
        $this->postJson($uri, ['action' => 'held', 'reason' => 'Проведе се в другата зала'])->assertOk()->assertJsonPath('session.not_held', null)
            ->assertJsonPath('counts.present', 2)->assertJsonPath('abilities.mark', true)->assertJsonPath('applied', []);
        $this->assertSame(2, DB::table('attendance_marks')->count());
        $this->assertSame(2, DB::table('attendance_mark_changes')->count());
        $this->assertSame(['not_held', 'held'], DB::table('attendance_session_changes')->orderBy('version')->pluck('action')->all());
        $this->assertSame(['attendance.session_not_held', 'attendance.session_held'], DB::table('audit_events')->where('action', 'like', 'attendance.session_%')->orderBy('created_at')->pluck('action')->all());
        foreach (DB::table('audit_events')->where('action', 'like', 'attendance.session_%')->pluck('metadata') as $metadata) {
            $this->assertStringNotContainsString('зала', $metadata);
            $this->assertStringNotContainsString($f['owner']->name, $metadata);
        }
        $this->assertSame(1, DB::table('activity_sessions')->where('id', $f['session']->id)->value('version'));
    }

    public function test_office_actions_require_reasons_but_topic_after_deadline_needs_no_reason_for_owner(): void
    {
        $f = $this->rightsFixture();
        $uri = '/api/v1/attendance/sessions/'.$f['session']->id.'/changes';
        $this->signInTo($f['owner'], $f['organization']);
        foreach (['not_held', 'held'] as $action) {
            foreach ([null, '', str_repeat('а', 501)] as $reason) {
                $this->postJson($uri, ['action' => $action, 'reason' => $reason])->assertUnprocessable()->assertJsonValidationErrors('reason');
            }
        }
        $this->travelTo('2026-11-04T22:00:00Z');
        $this->postJson($uri, ['action' => 'topic', 'topic' => 'Попълнена по-късно'])->assertOk();
        $this->postJson($uri, ['action' => 'not_held', 'reason' => 'Сверено с учителя'])->assertOk();
        $this->postJson($uri, ['action' => 'held', 'reason' => 'Поправка на грешка'])->assertOk();
        $this->assertSame(2, DB::table('audit_events')->where('action', 'like', 'attendance.%')->count());
    }

    public function test_coordinator_can_repeat_the_same_topic_and_state_after_deadline_but_cannot_change_them(): void
    {
        $f = $this->rightsFixture();
        $uri = '/api/v1/attendance/sessions/'.$f['session']->id.'/changes';
        $coordinator = $this->memberOf($f['organization'], 'coordinator');
        $this->signInTo($coordinator, $f['organization'])->postJson($uri, ['action' => 'topic', 'topic' => 'Тема'])->assertOk();
        $this->postJson($uri, ['action' => 'not_held', 'reason' => 'Затворена зала'])->assertOk();
        $this->travelTo('2026-11-04T22:00:00Z');
        $this->postJson($uri, ['action' => 'topic', 'topic' => 'Тема'])->assertOk();
        $this->postJson($uri, ['action' => 'not_held', 'reason' => 'Повторение'])->assertOk();
        $this->postJson($uri, ['action' => 'held', 'reason' => 'Поправка'])->assertForbidden();
        $this->postJson($uri, ['action' => 'topic', 'topic' => 'Друга тема'])->assertForbidden();
        $this->assertSame(1, DB::table('attendance_session_changes')->count());
    }

    public function test_session_boundaries_and_foreign_access_do_not_write(): void
    {
        $f = $this->rightsFixture();
        $other = $this->rightsFixture();
        $uri = '/api/v1/attendance/sessions/'.$f['session']->id.'/changes';
        $this->signInTo($other['owner'], $other['organization'])->postJson($uri, ['action' => 'not_held', 'reason' => 'Причина'])->assertNotFound();
        $this->signInTo($f['owner'], $f['organization']);
        $this->travelTo('2026-11-03T14:44:59Z');
        foreach (['topic', 'not_held'] as $action) {
            $this->postJson($uri, ['action' => $action, 'topic' => 'Нова тема', 'reason' => 'Причина'])->assertUnprocessable();
        }
        $this->travelTo('2026-11-03T14:45:00Z');
        $this->inTenant($f['organization'], fn () => $f['session']->update(['status' => 'cancelled']));
        $this->postJson($uri, ['action' => 'not_held', 'reason' => 'Причина'])->assertUnprocessable();
        $this->assertSame(0, DB::table('attendance_sessions')->count());
    }
}
