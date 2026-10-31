<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Catalog\Models\ScheduleChange;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Models\SessionChange;
use App\Domain\Enrollment\Models\EnrollmentEvent;
use App\Domain\Enrollment\Models\EnrollmentTransition;
use App\Domain\Enrollment\Services\VisitBooking;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\CapturingMailSender;
use Tests\Support\ScheduleChangeFixture;
use Tests\TestCase;

class ScheduleBulkTest extends TestCase
{
    use RefreshDatabase;
    use ScheduleChangeFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->travelTo('2026-11-02T10:00:00Z');
    }

    private function bulkBody(array $sessions, array $extra = []): array
    {
        return ['action' => 'cancel', 'sessions' => array_map(fn ($s) => ['id' => $s->id, 'version' => $s->version], $sessions),
            'reason' => 'Ремонт.', 'reason_to_parents' => false, ...$extra];
    }

    public function test_bulk_cancel_is_atomic_groups_letters_by_family_and_replays_once(): void
    {
        $f = $this->rightsFixture();
        $parent = $this->inTenant($f['organization'], fn () => $f['enrollment']->application->parent);
        $this->enrolledIn($f, $f['studio'], 'Ема', '2026-10-20', $parent);
        $studio = $this->sessionOn($f, $f['studio'], '2026-11-04');
        $body = $this->bulkBody([$f['session'], $studio]);
        $this->previewChange($f['owner'], $f['organization'], $body)->assertOk()->assertJsonPath('totals.sessions', 2)->assertJsonPath('totals.families', 2);
        $result = $this->applyChange($f['owner'], $f['organization'], $body, 'bulk-once')->assertCreated();
        $this->applyChange($f['owner'], $f['organization'], $body, 'bulk-once')->assertOk()->assertExactJson($result->json());
        $events = $this->inTenant($f['organization'], fn () => EnrollmentEvent::query()->where('type', 'schedule.sessions_changed')->get());
        $this->assertCount(2, $events);
        $this->assertCount(2, $events->firstWhere('payload.parent_user_id', $parent->id)->payload['items']);
        $this->assertCount(2, $this->deliverLetters()->to($parent->email)[0]->mail->paragraphs);
    }

    public function test_a_foreign_session_in_a_bulk_request_changes_nothing(): void
    {
        $f = $this->rightsFixture();
        $other = $this->rightsFixture();
        $this->applyChange($f['owner'], $f['organization'], $this->bulkBody([$f['session'], $other['session']]))->assertNotFound();
        $this->assertSame('scheduled', $this->current($f, $f['session'])->status);
    }

    public function test_holiday_discovers_criteria_and_reports_a_changed_list_with_a_new_preview(): void
    {
        $f = $this->rightsFixture();
        $body = ['action' => 'holiday', 'days' => ['2026-11-03', '2026-11-04'], 'reason' => 'Почивен ден.', 'notify_parents' => false];
        $preview = $this->previewChange($f['owner'], $f['organization'], $body)->assertOk()->assertJsonPath('totals.sessions', 2);
        $selected = array_map(fn ($s) => ['id' => $s['id'], 'version' => $s['version']], $preview->json('sessions'));
        $this->inTenant($f['organization'], fn () => $f['thursday']->sessions()->create([
            'starts_at' => '2026-11-03T17:00:00Z', 'ends_at' => '2026-11-03T18:00:00Z', 'status' => 'scheduled',
        ]));
        $this->applyChange($f['owner'], $f['organization'], [...$body, 'sessions' => $selected])
            ->assertStatus(409)->assertJsonPath('preview.totals.sessions', 3);
        $this->assertSame('scheduled', $this->current($f, $f['session'])->status);
        $new = $this->previewChange($f['owner'], $f['organization'], $body)->assertOk();
        $selected = array_map(fn ($s) => ['id' => $s['id'], 'version' => $s['version']], $new->json('sessions'));
        $first = $this->applyChange($f['owner'], $f['organization'], [...$body, 'sessions' => $selected], 'holiday-once')->assertCreated();
        $this->applyChange($f['owner'], $f['organization'], [...$body, 'sessions' => $selected], 'holiday-once')->assertOk()->assertExactJson($first->json());
        $this->inTenant($f['organization'], function (): void {
            $change = ScheduleChange::query()->sole();
            $this->assertSame(['2026-11-03', '2026-11-04'], $change->criteria['days']);
            $this->assertTrue($change->reason_to_parents);
            $this->assertSame(1, EnrollmentEvent::query()->where('type', 'schedule.sessions_changed')->count());
        });
    }

    public function test_holiday_filters_intersect_and_counts_cancelled_started_but_not_drafts_or_archives(): void
    {
        $f = $this->rightsFixture();
        $this->inTenant($f['organization'], fn () => $f['session']->forceFill(['location_id' => $f['location']->id])->save());
        $body = ['action' => 'holiday', 'days' => ['2026-11-03', '2026-11-04'], 'reason' => 'Празник.',
            'locations' => [$f['location']->id], 'activities' => [$f['activity']->id]];
        $this->previewChange($f['owner'], $f['organization'], $body)->assertOk()->assertJsonPath('totals.sessions', 1);
        $this->previewChange($f['owner'], $f['organization'], [...$body, 'activities' => [$f['painting']->id]])->assertUnprocessable();
        $this->changeAsOwner($f, 'cancel', $f['session'])->assertCreated();
        $this->travelTo('2026-11-04T15:00:00Z');
        $all = ['action' => 'holiday', 'days' => ['2026-11-03', '2026-11-04', '2026-11-05'], 'reason' => 'Празник.'];
        // Past days are rejected even if sessions there are cancelled.
        $this->previewChange($f['owner'], $f['organization'], $all)->assertUnprocessable();
        $this->previewChange($f['owner'], $f['organization'], [...$all, 'days' => ['2026-11-04', '2026-11-05']])->assertOk()
            ->assertJsonPath('skipped.started', 1)->assertJsonPath('totals.sessions', 1);
    }

    public function test_change_location_only_affects_the_place_skips_same_place_and_notifies_when_requested(): void
    {
        $f = $this->rightsFixture();
        $second = $this->sessionOn($f, $f['tuesday'], '2026-11-10');
        $this->inTenant($f['organization'], fn () => $second->forceFill(['location_id' => $f['location']->id])->save());
        $body = $this->bulkBody([$f['session'], $second], ['action' => 'change_location', 'location_id' => $f['location']->id]);
        $this->previewChange($f['owner'], $f['organization'], $body)->assertOk()->assertJsonPath('skipped.same_location', 1)->assertJsonPath('totals.sessions', 1);
        $this->applyChange($f['owner'], $f['organization'], $body)->assertCreated();
        $this->assertTrue($this->current($f, $f['session'])->starts_at->equalTo($f['session']->starts_at));
        $this->inTenant($f['organization'], fn () => $this->assertSame(0, EnrollmentTransition::query()->count()));
        $letters = $this->deliverLetters();
        $text = implode(' ', array_map(fn ($s) => CapturingMailSender::text($s['mail']), $letters->sent));
        $this->assertStringContainsString('място', $text);
        $this->assertStringContainsString('Зала 1', $text);
        $this->assertStringNotContainsString('се мести от', $text);
    }

    public function test_location_warnings_include_the_final_places_of_other_selected_sessions(): void
    {
        $f = $this->rightsFixture();
        $other = $this->inTenant($f['organization'], fn () => $f['studio']->sessions()->create([
            'starts_at' => $f['session']->starts_at, 'ends_at' => $f['session']->ends_at, 'status' => 'scheduled', 'version' => 1,
        ]));
        $body = $this->bulkBody([$f['session'], $other], ['action' => 'change_location', 'location_id' => $f['location']->id]);
        $preview = $this->previewChange($f['owner'], $f['organization'], $body)->assertOk();
        $this->assertCount(2, $preview->json('warnings'));
        $this->applyChange($f['owner'], $f['organization'], $body)->assertUnprocessable()->assertJsonValidationErrors('confirmed_warnings');
        $keys = array_map(fn ($w) => array_intersect_key($w, array_flip(['type', 'session_id', 'other_session_id'])), $preview->json('warnings'));
        $this->applyChange($f['owner'], $f['organization'], [...$body, 'confirmed_warnings' => $keys])->assertCreated();
    }

    public function test_add_creates_an_extra_session_recalculates_start_and_sends_an_added_letter(): void
    {
        $f = $this->rightsFixture();
        $future = $this->enrolledIn($f, $f['tuesday'], 'Ема', '2026-11-03');
        $body = ['action' => 'add', 'group_id' => $f['tuesday']->id, 'date' => '2026-11-02', 'from' => '14:00', 'to' => '15:00'];
        $this->previewChange($f['owner'], $f['organization'], $body)->assertOk()->assertJsonPath('totals.starts_changed', 1);
        $result = $this->applyChange($f['owner'], $f['organization'], $body, 'add-once')->assertCreated();
        $this->applyChange($f['owner'], $f['organization'], $body, 'add-once')->assertOk()->assertExactJson($result->json());
        $id = $result->json('change.sessions.0.id');
        $this->inTenant($f['organization'], function () use ($future, $id): void {
            $session = Session::query()->findOrFail($id);
            $this->assertTrue($session->extra);
            $this->assertSame(10, $session->capacity);
            $this->assertFalse(VisitBooking::isOpen($session, CarbonImmutable::now()));
            $this->assertSame('2026-11-02', $future->fresh()->starts_on->toDateString());
            $history = SessionChange::query()->where('session_id', $id)->sole();
            $this->assertNull($history->from_status);
            $this->assertNull($history->from_starts_at);
        });
        $sent = $this->deliverLetters();
        $text = implode(' ', array_map(fn ($s) => CapturingMailSender::text($s['mail']), $sent->sent));
        $this->assertStringContainsString('Допълнителното занимание', $text);
    }

    public function test_300_sessions_use_the_same_number_of_queries_as_three_for_preview_and_apply(): void
    {
        $measure = function (int $size): array {
            $f = $this->rightsFixture();
            $sessions = $this->inTenant($f['organization'], function () use ($f, $size): array {
                $rows = [];
                for ($i = 0; $i < $size; $i++) {
                    $start = CarbonImmutable::parse('2026-11-06T06:00:00Z')->addHours($i * 2);
                    $rows[] = $f['tuesday']->sessions()->create(['starts_at' => $start, 'ends_at' => $start->addHour(), 'status' => 'scheduled', 'version' => 1]);
                }

                return $rows;
            });
            $body = $this->bulkBody($sessions);
            $this->previewChange($f['owner'], $f['organization'], $body)->assertOk();
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->previewChange($f['owner'], $f['organization'], $body)->assertOk()->assertJsonPath('totals.sessions', $size);
            $preview = count(DB::getQueryLog());
            DB::flushQueryLog();
            $this->applyChange($f['owner'], $f['organization'], $body)->assertCreated()->assertJsonCount($size, 'change.sessions');
            $apply = count(DB::getQueryLog());
            DB::disableQueryLog();
            $this->assertSame($size, $this->inTenant($f['organization'], fn () => SessionChange::query()->count()));

            return [$preview, $apply];
        };
        $this->assertSame($measure(3), $measure(300));
    }

    public function test_limits_duplicate_sessions_and_missing_location_are_rejected_before_any_write(): void
    {
        $f = $this->rightsFixture();
        $one = ['id' => $f['session']->id, 'version' => 1];
        foreach ([$this->bulkBody([$f['session']], ['sessions' => array_map(fn ($i) => ['id' => (string) Str::uuid(), 'version' => 1], range(1, 301))]),
            $this->bulkBody([$f['session']], ['sessions' => [$one, $one]]),
            $this->bulkBody([$f['session']], ['action' => 'change_location']),
            ['action' => 'holiday', 'reason' => 'Почивка.', 'days' => array_map(fn ($i) => CarbonImmutable::now()->addDays($i)->toDateString(), range(1, 32))],
            ['action' => 'holiday', 'reason' => 'Почивка.', 'days' => ['2026-11-03', '2026-11-03']],
        ] as $body) {
            $this->previewChange($f['owner'], $f['organization'], $body)->assertUnprocessable();
        }
        $this->assertSame(0, $this->inTenant($f['organization'], fn () => ScheduleChange::query()->count()));
    }

    public function test_holiday_conflict_includes_empty_preview_and_rejects_changed_versions(): void
    {
        $f = $this->rightsFixture();
        $body = ['action' => 'holiday', 'days' => ['2026-11-03'], 'reason' => 'Почивка.', 'sessions' => [['id' => $f['session']->id, 'version' => 1]]];
        $this->inTenant($f['organization'], fn () => $f['session']->forceFill(['version' => 2])->save());
        $this->applyChange($f['owner'], $f['organization'], $body)->assertStatus(409)->assertJsonPath('preview.sessions.0.version', 2);
        $this->changeAsOwner($f, 'cancel', $f['session'])->assertCreated();
        $this->applyChange($f['owner'], $f['organization'], $body)->assertStatus(409)->assertJsonPath('preview.totals.sessions', 0)
            ->assertJsonPath('preview.skipped.cancelled', 1);
    }

    public function test_holiday_uses_sofia_day_and_excludes_drafts_and_archived_groups(): void
    {
        $f = $this->rightsFixture();
        $this->inTenant($f['organization'], function () use ($f): void {
            foreach (['draft', 'archived'] as $status) {
                $group = $f['chess']->groups()->create(['name' => 'Друга група', 'status' => $status]);
                $group->sessions()->create(['starts_at' => '2026-11-02T22:30:00Z', 'ends_at' => '2026-11-02T23:30:00Z', 'status' => 'scheduled']);
            }
            $f['tuesday']->sessions()->create(['starts_at' => '2026-11-02T22:30:00Z', 'ends_at' => '2026-11-02T23:30:00Z', 'status' => 'scheduled']);
        });
        $body = ['action' => 'holiday', 'days' => ['2026-11-03'], 'reason' => 'Празник.'];
        $this->previewChange($f['owner'], $f['organization'], $body)->assertOk()->assertJsonPath('totals.sessions', 2);
        $this->previewChange($f['owner'], $f['organization'], [...$body, 'days' => ['2026-11-02']])->assertUnprocessable();
    }

    public function test_teacher_letters_go_even_when_location_or_added_family_notifications_are_off(): void
    {
        $f = $this->rightsFixture();
        $teacher = $this->memberOf($f['organization'], 'instructor');
        $this->inTenant($f['organization'], function () use ($f, $teacher): void {
            $lead = InstructorProfile::query()->create(['public_name' => 'Мария', 'user_id' => $teacher->id]);
            $f['tuesday']->assignments()->attach($lead->id, ['role' => 'lead', 'version' => 1]);
            $f['session']->instructors()->attach($lead->id, ['role' => 'lead']);
        });
        $this->applyChange($f['owner'], $f['organization'], $this->bulkBody([$f['session']], [
            'action' => 'change_location', 'location_id' => $f['location']->id, 'notify_parents' => false,
        ]))->assertCreated()->assertJsonPath('letters.families', 0)->assertJsonPath('letters.teachers', 1);
        $letters = $this->deliverLetters();
        $this->assertCount(1, $letters->sent);
        $this->assertStringContainsString('ново място', CapturingMailSender::text($letters->to($teacher->email)[0]));
        $body = ['action' => 'add', 'group_id' => $f['tuesday']->id, 'date' => '2026-11-07', 'from' => '10:00', 'to' => '11:00', 'notify_parents' => false];
        $this->applyChange($f['owner'], $f['organization'], $body)->assertCreated()->assertJsonPath('letters.families', 0)->assertJsonPath('letters.teachers', 1);
        $letters = $this->deliverLetters();
        $this->assertCount(1, $letters->sent);
        $text = CapturingMailSender::text($letters->to($teacher->email)[0]);
        $this->assertStringContainsString('Допълнителното занимание', $text);
        $this->assertStringNotContainsString('Ани', $text);
    }

    public function test_add_warning_keys_are_stable_when_the_confirmation_is_sent(): void
    {
        $f = $this->rightsFixture();
        $body = ['action' => 'add', 'group_id' => $f['tuesday']->id, 'date' => '2026-11-04', 'from' => '17:00', 'to' => '18:00', 'location_id' => $f['location']->id];
        $this->inTenant($f['organization'], fn () => Session::query()->where('group_id', $f['studio']->id)->update(['location_id' => $f['location']->id]));
        $preview = $this->previewChange($f['owner'], $f['organization'], $body)->assertOk();
        $keys = array_values(array_map(fn ($w) => array_intersect_key($w, array_flip(['type', 'session_id', 'other_session_id'])),
            array_filter($preview->json('warnings'), fn ($w) => $w['needs_confirmation'])));
        $this->assertNotEmpty($keys);
        $this->applyChange($f['owner'], $f['organization'], $body)->assertUnprocessable();
        $this->applyChange($f['owner'], $f['organization'], [...$body, 'confirmed_warnings' => $keys])->assertCreated()
            ->assertJsonPath('change.sessions.0.id', $preview->json('sessions.0.id'));
    }

    #[Group('tenant-isolation')]
    public function test_add_and_change_location_reject_foreign_groups_places_and_unauthorized_actors(): void
    {
        $f = $this->rightsFixture();
        $foreign = $this->rightsFixture();
        $body = ['action' => 'add', 'group_id' => $foreign['tuesday']->id, 'date' => '2026-11-06', 'from' => '17:00', 'to' => '18:00'];
        $this->applyChange($f['owner'], $f['organization'], $body)->assertNotFound();
        $this->applyChange($f['owner'], $f['organization'], [...$body, 'group_id' => $f['tuesday']->id, 'location_id' => $foreign['location']->id])
            ->assertUnprocessable()->assertJsonValidationErrors('location_id');
        $this->applyChange($f['owner'], $f['organization'], $this->bulkBody([$f['session']], ['action' => 'change_location', 'location_id' => $foreign['location']->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('location_id');
        foreach (['viewer', 'instructor', 'finance', 'platform_admin'] as $role) {
            $user = $this->memberOf($f['organization'], $role);
            foreach ([$body, ['action' => 'holiday', 'days' => ['2026-11-03'], 'reason' => 'Празник.']] as $request) {
                $this->previewChange($user, $f['organization'], $request)->assertForbidden();
                $this->applyChange($user, $f['organization'], $request)->assertForbidden();
            }
        }
    }

    public function test_holiday_letters_group_families_across_groups_and_truncate_after_twenty_rows(): void
    {
        $f = $this->rightsFixture();
        $parent = $this->inTenant($f['organization'], fn () => $f['enrollment']->application->parent);
        $this->enrolledIn($f, $f['studio'], 'Ема', '2026-10-20', $parent);
        $this->inTenant($f['organization'], function () use ($f): void {
            for ($i = 0; $i < 23; $i++) {
                $start = CarbonImmutable::parse('2026-11-06T06:00:00Z')->addHours($i * 2);
                $f['tuesday']->sessions()->create(['starts_at' => $start, 'ends_at' => $start->addHour(), 'status' => 'scheduled']);
            }
        });
        $body = ['action' => 'holiday', 'days' => ['2026-11-03', '2026-11-04', '2026-11-06', '2026-11-07', '2026-11-08'], 'reason' => 'Почивка.'];
        $preview = $this->previewChange($f['owner'], $f['organization'], $body)->assertOk();
        $selection = array_map(fn ($s) => ['id' => $s['id'], 'version' => $s['version']], $preview->json('sessions'));
        $this->applyChange($f['owner'], $f['organization'], [...$body, 'sessions' => $selection])->assertCreated();
        $letters = $this->deliverLetters()->to($parent->email);
        $this->assertCount(1, $letters);
        $text = CapturingMailSender::text($letters[0]);
        $this->assertStringContainsString('Ани', $text);
        $this->assertStringContainsString('Ема', $text);
        $this->assertStringContainsString('И още 5 занимания.', $text);
        $this->assertStringContainsString('Почивка.', $text);
    }

    public function test_a_preview_with_all_places_already_equal_reports_every_skipped_session(): void
    {
        $f = $this->rightsFixture();
        $body = $this->bulkBody([$f['session']], ['action' => 'change_location', 'location_id' => null]);
        $this->previewChange($f['owner'], $f['organization'], $body)->assertOk()->assertJsonPath('skipped.same_location', 1)->assertJsonPath('totals.sessions', 0);
        $this->applyChange($f['owner'], $f['organization'], $body)->assertUnprocessable();
    }

    public function test_bulk_version_conflict_and_failure_leave_no_partial_change(): void
    {
        $f = $this->rightsFixture();
        $second = $this->sessionOn($f, $f['tuesday'], '2026-11-10');
        $this->inTenant($f['organization'], fn () => $second->forceFill(['version' => 2])->save());
        $this->applyChange($f['owner'], $f['organization'], $this->bulkBody([$f['session'], $second], [
            'sessions' => [['id' => $f['session']->id, 'version' => 1], ['id' => $second->id, 'version' => 1]],
        ]))->assertStatus(409);
        $this->assertSame('scheduled', $this->current($f, $f['session'])->status);
        $this->assertSame(0, $this->inTenant($f['organization'], fn () => ScheduleChange::query()->count()));
        $this->previewChange($f['owner'], $f['organization'], ['action' => []])->assertUnprocessable();
    }
}
