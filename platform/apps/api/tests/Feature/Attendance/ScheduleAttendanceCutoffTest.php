<?php

namespace Tests\Feature\Attendance;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ScheduleChangeFixture;
use Tests\TestCase;

class ScheduleAttendanceCutoffTest extends TestCase
{
    use RefreshDatabase, ScheduleChangeFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->travelTo('2026-11-03T14:00:00Z');
    }

    public static function changes(): array
    {
        return [['cancel', 16, 200], ['cancel', 15, 422], ['cancel', 14, 422],
            ['restore', 16, 200], ['restore', 14, 422], ['move', 16, 200], ['move', 14, 422]];
    }

    #[DataProvider('changes')]
    public function test_preview_and_apply_share_the_attendance_boundary(string $action, int $minutes, int $status): void
    {
        $f = $this->rightsFixture();
        if ($action === 'restore') {
            $this->inTenant($f['organization'], fn () => $f['session']->update(['status' => 'cancelled']));
        }
        $this->travelTo($f['session']->starts_at->subMinutes($minutes));
        $extra = $action === 'move' ? ['date' => '2026-11-04', 'from' => '18:00', 'to' => '19:00', 'reason' => 'Промяна на часа'] : [];
        $body = $this->changeBody($action, $f['session'], 1, $extra);
        $this->previewChange($f['owner'], $f['organization'], $body)->assertStatus($status);
        $this->applyChange($f['owner'], $f['organization'], $body)->assertStatus($status === 200 ? 201 : $status);
    }

    public function test_a_move_cannot_put_a_session_inside_the_new_attendance_window(): void
    {
        $f = $this->rightsFixture();
        $body = $this->changeBody('move', $f['session'], 1, ['date' => '2026-11-03', 'from' => '16:14', 'to' => '17:14', 'reason' => 'Промяна на часа']);
        $this->previewChange($f['owner'], $f['organization'], $body)->assertUnprocessable();
        $this->applyChange($f['owner'], $f['organization'], $body)->assertUnprocessable();
    }

    public function test_holiday_skips_the_open_attendance_window_and_the_panel_has_no_change_actions(): void
    {
        $f = $this->rightsFixture();
        $this->travelTo($f['session']->starts_at->subMinutes(14));
        $this->inTenant($f['organization'], fn () => $f['tuesday']->sessions()->create([
            'starts_at' => '2026-11-03T17:00:00Z', 'ends_at' => '2026-11-03T18:00:00Z', 'status' => 'scheduled',
        ]));
        $body = ['action' => 'holiday', 'days' => ['2026-11-03'], 'reason' => 'Почивен ден'];
        $this->previewChange($f['owner'], $f['organization'], $body)->assertOk()
            ->assertJsonPath('skipped.started', 1)->assertJsonPath('totals.sessions', 1);
        $this->signInTo($f['owner'], $f['organization'])->getJson('/api/v1/schedule/sessions/'.$f['session']->id)
            ->assertOk()->assertJsonPath('actions', []);
    }

    public function test_location_changes_keep_their_existing_boundary(): void
    {
        $f = $this->rightsFixture();
        $this->travelTo($f['session']->starts_at->subMinutes(14));
        $body = $this->changeBody('change_location', $f['session'], 1, ['location_id' => $f['location']->id, 'reason' => 'Друга зала']);
        $this->previewChange($f['owner'], $f['organization'], $body)->assertOk();
        $this->applyChange($f['owner'], $f['organization'], $body)->assertCreated();
    }
}
