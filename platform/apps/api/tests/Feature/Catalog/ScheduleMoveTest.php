<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Catalog\Models\Location;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Models\SessionChange;
use App\Domain\Catalog\Services\ScheduleConflictDetector;
use App\Domain\Enrollment\Models\EnrollmentEvent;
use App\Domain\Enrollment\Models\EnrollmentPause;
use App\Domain\Enrollment\Models\EnrollmentSessionException;
use App\Domain\Enrollment\Models\EnrollmentTransition;
use App\Domain\Enrollment\Services\VisitBooking;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\CapturingMailSender;
use Tests\Support\ScheduleChangeFixture;
use Tests\TestCase;

class ScheduleMoveTest extends TestCase
{
    use RefreshDatabase;
    use ScheduleChangeFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->travelTo('2026-11-02 10:00:00');
    }

    private function moveBody(Session $session, array $extra = []): array
    {
        return $this->changeBody('move', $session, $session->version, [
            'date' => '2026-11-06', 'from' => '17:30', 'to' => '18:30', 'reason' => 'Залата е заета.', ...$extra,
        ]);
    }

    public function test_move_preview_writes_nothing_apply_records_history_and_replay_includes_destination(): void
    {
        $f = $this->rightsFixture();
        $body = $this->moveBody($f['session']);
        $this->previewChange($f['owner'], $f['organization'], $body)->assertOk()
            ->assertJsonPath('sessions.0.starts_at', '2026-11-06T15:30:00+00:00')->assertJsonPath('totals.families', 2);
        $this->assertSame(1, $this->current($f, $f['session'])->version);
        $result = $this->applyChange($f['owner'], $f['organization'], $body, 'move-once')->assertCreated();
        $this->assertSame('2026-11-06T15:30:00+00:00', $this->current($f, $f['session'])->starts_at->toIso8601String());
        $history = $this->inTenant($f['organization'], fn () => SessionChange::query()->firstOrFail());
        $this->assertTrue($history->from_starts_at->equalTo($f['session']->starts_at));
        $this->assertSame('2026-11-06', $history->to_starts_at->toDateString());
        $this->applyChange($f['owner'], $f['organization'], $body, 'move-once')->assertOk()->assertExactJson($result->json());
        $this->applyChange($f['owner'], $f['organization'], [...$body, 'from' => '18:00'], 'move-once')
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    }

    public function test_original_time_survives_two_moves_clears_on_return_and_location_only_is_not_moved(): void
    {
        $f = $this->rightsFixture();
        foreach ([['2026-11-06', '17:30'], ['2026-11-07', '17:30'], ['2026-11-03', '17:00']] as $index => [$date, $from]) {
            $session = $this->current($f, $f['session']);
            $this->applyChange($f['owner'], $f['organization'], $this->moveBody($session, ['date' => $date, 'from' => $from]))->assertCreated();
            $current = $this->current($f, $session);
            $this->assertSame($index === 2 ? null : $f['session']->starts_at->getTimestamp(), $current->original_starts_at?->getTimestamp());
        }
        $this->applyChange($f['owner'], $f['organization'], $this->moveBody($this->current($f, $f['session']), [
            'date' => '2026-11-03', 'from' => '17:00', 'location_id' => $f['location']->id,
        ]))->assertCreated();
        $this->assertNull($this->current($f, $f['session'])->original_starts_at);
        $this->signInTo($f['owner'], $f['organization'])->getJson('/api/v1/schedule/sessions/'.$f['session']->id)
            ->assertOk()->assertJsonPath('session.moved', false)->assertJsonPath('actions', ['move', 'cancel', 'substitute']);
    }

    public function test_move_rejects_unchanged_cancelled_overlapping_past_and_invalid_local_times(): void
    {
        $f = $this->rightsFixture();
        foreach ([
            ['date' => '2026-11-03', 'from' => '17:00', 'to' => '18:00'],
            ['date' => '2026-11-10', 'from' => '17:00', 'to' => '18:00'],
            ['date' => '2026-11-02', 'from' => '11:00', 'to' => '12:00'],
            ['date' => '2026-11-01'], ['date' => '2027-11-03'], ['date' => '2026-11-31'],
            ['from' => '18:30', 'to' => '17:30'], ['from' => '25:00'],
            ['reason' => '  '],
        ] as $extra) {
            $this->previewChange($f['owner'], $f['organization'], $this->moveBody($f['session'], $extra))->assertUnprocessable();
        }
        $this->changeAsOwner($f, 'cancel', $f['session'])->assertCreated();
        $this->previewChange($f['owner'], $f['organization'], $this->moveBody($this->current($f, $f['session'])))
            ->assertUnprocessable()->assertJsonPath('errors.sessions.0', 'Отмененото занимание първо го върнете.');
    }

    public function test_move_drops_skips_updates_visits_waiting_applications_and_future_starts(): void
    {
        $f = $this->rightsFixture();
        $waiting = $this->waitingFor($f, $f['session'], 'Ива');
        $future = $this->enrolledIn($f, $f['tuesday'], 'Мила', '2026-11-03');
        $this->inTenant($f['organization'], fn () => EnrollmentSessionException::query()->create([
            'enrollment_id' => $f['enrollment']->id, 'session_id' => $f['session']->id, 'kind' => 'excluded',
        ]));
        $preview = $this->previewChange($f['owner'], $f['organization'], $this->moveBody($f['session']))->assertOk()
            ->assertJsonPath('totals.skips_dropped', 1)->assertJsonPath('totals.starts_changed', 1);
        $this->applyChange($f['owner'], $f['organization'], $this->moveBody($f['session']))->assertCreated();
        $this->inTenant($f['organization'], function () use ($f, $future, $waiting): void {
            $this->assertSame(0, EnrollmentSessionException::query()->count());
            $this->assertSame('2026-11-06', $f['visit']->fresh()->starts_on->toDateString());
            $this->assertSame('2026-11-06', $future->fresh()->starts_on->toDateString());
            $this->assertSame('submitted', $waiting->fresh()->status->value);
            $this->assertSame(2, $waiting->fresh()->version);
            $transition = EnrollmentTransition::query()->where('enrollment_id', $f['enrollment']->id)->firstOrFail();
            $this->assertTrue($transition->metadata['skip_dropped']);
            $this->assertSame('moved_skip_dropped', $transition->metadata['effect']);
        });
        $sender = $this->deliverLetters();
        $text = implode(' ', array_map(fn ($sent) => CapturingMailSender::text($sent['mail']), $sender->sent));
        $this->assertStringContainsString('3 ноември', $text);
        $this->assertStringContainsString('6 ноември', $text);
        $this->assertStringContainsString('17:00', $text);
        $this->assertStringContainsString('17:30', $text);
        $this->assertStringContainsString('предупредете', $text);
        $this->assertStringNotContainsString('Залата е заета.', $text);
    }

    public function test_move_into_pause_tells_the_family_that_the_child_will_not_attend(): void
    {
        $f = $this->rightsFixture();
        $this->inTenant($f['organization'], fn () => EnrollmentPause::query()->create([
            'enrollment_id' => $f['enrollment']->id, 'starts_on' => '2026-11-06', 'ends_on' => '2026-11-08', 'reason_category' => 'other', 'created_by' => $f['owner']->id,
        ]));
        $this->previewChange($f['owner'], $f['organization'], $this->moveBody($f['session']))->assertOk()
            ->assertJsonPath('totals.not_attending_after', 1);
        $this->applyChange($f['owner'], $f['organization'], $this->moveBody($f['session']))->assertCreated();
        $events = $this->inTenant($f['organization'], fn () => EnrollmentEvent::query()->where('type', 'schedule.sessions_changed')->get());
        $this->assertContains('moved_not_attending', $events->flatMap(fn ($event) => array_column($event->payload['items'], 'effect'))->all());
        $sender = $this->deliverLetters();
        $text = implode(' ', array_map(fn ($sent) => CapturingMailSender::text($sent['mail']), $sender->sent));
        $this->assertStringContainsString('за Ани заниманието отпада', $text);
    }

    public function test_move_uses_the_new_days_assignment_keeps_manual_teachers_and_tells_both_old_and_new(): void
    {
        $f = $this->rightsFixture();
        $oldUser = $this->memberOf($f['organization'], 'instructor');
        $newUser = $this->memberOf($f['organization'], 'instructor');
        [$old, $new] = $this->inTenant($f['organization'], function () use ($f, $oldUser, $newUser): array {
            $old = InstructorProfile::query()->create(['public_name' => 'Стар водещ', 'user_id' => $oldUser->id]);
            $new = InstructorProfile::query()->create(['public_name' => 'Нов водещ', 'user_id' => $newUser->id]);
            $f['tuesday']->assignments()->attach($old->id, ['role' => 'lead', 'version' => 1, 'ends_on' => '2026-11-04']);
            $f['tuesday']->assignments()->attach($new->id, ['role' => 'lead', 'version' => 1, 'starts_on' => '2026-11-05']);
            $f['session']->instructors()->sync([$old->id => ['role' => 'lead']]);

            return [$old, $new];
        });
        $this->applyChange($f['owner'], $f['organization'], $this->moveBody($f['session']))->assertCreated()->assertJsonPath('letters.teachers', 2);
        $this->assertSame([$new->id], $this->current($f, $f['session'])->instructors->modelKeys());
        $sent = $this->deliverLetters();
        foreach ([$oldUser, $newUser] as $user) {
            $letters = $sent->to($user->email);
            $this->assertCount(1, $letters);
            $text = CapturingMailSender::text($letters[0]);
            foreach (['3 ноември', '6 ноември', '17:00', '17:30', 'се мести'] as $expected) {
                $this->assertStringContainsString($expected, $text);
            }
            $this->assertStringNotContainsString('Ани', $text);
        }
        $next = $this->sessionOn($f, $f['tuesday'], '2026-11-10');
        $this->inTenant($f['organization'], function () use ($next, $old): void {
            $next->instructors()->sync([$old->id => ['role' => 'lead']]);
            $next->forceFill(['instructors_source' => 'manual'])->save();
        });
        $this->applyChange($f['owner'], $f['organization'], $this->moveBody($next, ['date' => '2026-11-07']))->assertCreated();
        $this->assertSame([$old->id], $this->current($f, $next)->instructors->modelKeys());
    }

    public function test_move_requires_each_new_warning_key_and_keeps_confirmed_overlaps(): void
    {
        $f = $this->rightsFixture();
        $makeOther = fn ($group) => $this->inTenant($f['organization'], fn () => $group->sessions()->create([
            'starts_at' => '2026-11-06T15:30:00Z', 'ends_at' => '2026-11-06T16:30:00Z',
            'status' => 'scheduled', 'location_id' => $f['location']->id,
        ]));
        $other = $makeOther($f['studio']);
        $body = $this->moveBody($f['session'], ['location_id' => $f['location']->id]);
        $preview = $this->previewChange($f['owner'], $f['organization'], $body)->assertOk();
        $this->assertContains('no_lead', array_column($preview->json('warnings'), 'type'));
        $key = ['type' => 'exclusive_location', 'session_id' => $f['session']->id, 'other_session_id' => $other->id];
        $this->applyChange($f['owner'], $f['organization'], $body)->assertUnprocessable()->assertJsonValidationErrors('confirmed_warnings');
        $another = $makeOther($f['thursday']);
        $this->applyChange($f['owner'], $f['organization'], [...$body, 'confirmed_warnings' => [$key]])->assertUnprocessable();
        $second = [...$key, 'other_session_id' => $another->id];
        $this->applyChange($f['owner'], $f['organization'], [...$body, 'confirmed_warnings' => [$key, $second]])->assertCreated();
        $kept = $this->inTenant($f['organization'], fn () => SessionChange::query()->sole()->confirmed_overlaps);
        $this->assertEqualsCanonicalizing([$key, $second], $kept);
        $conflicts = $this->inTenant($f['organization'], fn () => app(ScheduleConflictDetector::class)->forGroup($f['tuesday']));
        $this->assertSame([], $conflicts);
    }

    public function test_dropped_skips_can_overbook_the_new_session_and_need_confirmation(): void
    {
        $f = $this->rightsFixture();
        $this->inTenant($f['organization'], function () use ($f): void {
            $f['session']->forceFill(['capacity' => 1])->save();
            EnrollmentSessionException::query()->create(['enrollment_id' => $f['enrollment']->id, 'session_id' => $f['session']->id, 'kind' => 'excluded']);
        });
        $body = $this->moveBody($f['session']);
        $this->applyChange($f['owner'], $f['organization'], $body)->assertUnprocessable()->assertJsonValidationErrors('confirmed_warnings');
        $this->assertSame(1, $this->inTenant($f['organization'], fn () => EnrollmentSessionException::query()->count()));
        $this->applyChange($f['owner'], $f['organization'], [...$body, 'confirmed_warnings' => [[
            'type' => 'overbooked', 'session_id' => $f['session']->id, 'other_session_id' => null,
        ]]])->assertCreated();
    }

    #[Group('tenant-isolation')]
    public function test_move_never_uses_another_organizations_session_or_place_and_checks_rights_first(): void
    {
        $f = $this->rightsFixture();
        $foreign = $this->rightsFixture();
        $this->applyChange($f['owner'], $f['organization'], $this->moveBody($foreign['session']))->assertNotFound();
        $this->applyChange($f['owner'], $f['organization'], $this->moveBody($f['session'], ['location_id' => $foreign['location']->id]))
            ->assertUnprocessable()->assertJsonPath('errors.location_id.0', 'Мястото не е намерено.');
        foreach (['instructor', 'finance', 'viewer', 'platform_admin'] as $role) {
            $user = $this->memberOf($f['organization'], $role);
            $this->previewChange($user, $f['organization'], $this->moveBody($foreign['session']))->assertForbidden();
            $this->applyChange($user, $f['organization'], $this->moveBody($foreign['session']))->assertForbidden();
        }
        $this->assertSame(1, $this->current($foreign, $foreign['session'])->version);
    }

    public function test_local_days_and_daylight_saving_are_used_and_a_missing_spring_hour_is_rejected(): void
    {
        $f = $this->rightsFixture();
        $this->travelTo('2026-10-20T10:00:00Z');
        foreach ([['2026-10-25', '00:30', '01:30', '2026-10-24T21:30:00+00:00'],
            ['2026-10-25', '04:30', '05:30', '2026-10-25T02:30:00+00:00'],
            ['2027-03-28', '04:30', '05:30', '2027-03-28T01:30:00+00:00']] as [$date, $from, $to, $utc]) {
            $this->previewChange($f['owner'], $f['organization'], $this->moveBody($f['session'], compact('date', 'from', 'to')))
                ->assertOk()->assertJsonPath('sessions.0.starts_at', $utc);
        }
        $this->previewChange($f['owner'], $f['organization'], $this->moveBody($f['session'], ['date' => '2027-03-28', 'from' => '03:30', 'to' => '04:30']))
            ->assertUnprocessable()->assertJsonValidationErrors('date');
    }

    public function test_move_recomputes_not_started_enrollments_before_their_old_start_but_respects_transfer_bound(): void
    {
        $f = $this->rightsFixture();
        $next = $this->sessionOn($f, $f['tuesday'], '2026-11-10');
        $future = $this->enrolledIn($f, $f['tuesday'], 'Мила', '2026-11-03');
        $transfer = $this->enrolledIn($f, $f['tuesday'], 'Ема', '2026-11-10', notBefore: '2026-11-08');
        $this->applyChange($f['owner'], $f['organization'], $this->moveBody($next, ['date' => '2026-11-02', 'from' => '14:00', 'to' => '15:00']))->assertCreated();
        $this->inTenant($f['organization'], function () use ($f, $future, $transfer): void {
            $this->assertSame('2026-11-02', $future->fresh()->starts_on->toDateString());
            $this->assertSame('2026-11-17', $transfer->fresh()->starts_on->toDateString());
            $this->assertSame('2026-10-20', $f['enrollment']->fresh()->starts_on->toDateString());
        });
    }

    public function test_old_pause_becomes_attending_new_end_date_becomes_not_attending_and_reason_is_opt_in(): void
    {
        $f = $this->rightsFixture();
        $this->inTenant($f['organization'], fn () => EnrollmentPause::query()->create([
            'enrollment_id' => $f['enrollment']->id, 'starts_on' => '2026-11-03', 'ends_on' => '2026-11-03', 'reason_category' => 'holiday',
        ]));
        $ended = $this->enrolledIn($f, $f['tuesday'], 'Ема', '2026-10-20');
        $this->inTenant($f['organization'], fn () => $ended->forceFill(['ends_on' => '2026-11-04'])->save());
        $this->applyChange($f['owner'], $f['organization'], $this->moveBody($f['session'], ['reason_to_parents' => true]))->assertCreated();
        $sent = $this->deliverLetters();
        $text = implode(' ', array_map(fn ($letter) => CapturingMailSender::text($letter['mail']), $sent->sent));
        $this->assertStringContainsString('Залата е заета.', $text);
        $this->assertStringContainsString('за Ема заниманието отпада', $text);
        $this->assertStringContainsString('Заниманието на Ани', $text);
    }

    public function test_move_letters_are_superseded_and_recheck_the_family_and_current_skip(): void
    {
        $f = $this->rightsFixture();
        $this->applyChange($f['owner'], $f['organization'], $this->moveBody($f['session']))->assertCreated();
        $this->applyChange($f['owner'], $f['organization'], $this->moveBody($this->current($f, $f['session']), ['date' => '2026-11-07']))->assertCreated();
        $this->inTenant($f['organization'], fn () => EnrollmentSessionException::query()->create([
            'enrollment_id' => $f['enrollment']->id, 'session_id' => $f['session']->id, 'kind' => 'excluded',
        ]));
        $letters = $this->deliverLetters();
        $this->assertCount(1, $letters->sent);
        $text = CapturingMailSender::text($letters->sent[0]['mail']);
        $this->assertStringContainsString('6 ноември', $text);
        $this->assertStringContainsString('7 ноември', $text);
        $this->assertStringContainsString('По график', $text);
        $this->assertStringContainsString('3 ноември', $text);
        $this->assertStringNotContainsString('Ани', $text);
    }

    public function test_a_trial_moved_across_september_counts_in_the_new_school_year(): void
    {
        $f = $this->rightsFixture();
        $this->travelTo('2026-08-30T10:00:00Z');
        $august = $this->inTenant($f['organization'], fn () => $f['tuesday']->sessions()->create([
            'version' => 1, 'starts_at' => '2026-08-31T14:00:00Z', 'ends_at' => '2026-08-31T15:00:00Z', 'status' => 'scheduled',
        ]));
        $trial = $this->visitOn($f, $august, 'Лили');
        $this->applyChange($f['owner'], $f['organization'], $this->moveBody($august, ['date' => '2026-09-01']))->assertCreated();
        $this->inTenant($f['organization'], function () use ($f, $trial, $august): void {
            $this->assertSame('2026-09-01', $trial->fresh()->starts_on->toDateString());
            $booking = app(VisitBooking::class);
            $booking->assertTrialOnce($trial->child, $f['tuesday'], $august);
            $this->expectException(ValidationException::class);
            $booking->assertTrialOnce($trial->child, $f['tuesday'], $f['session']);
        });
    }

    public function test_move_warnings_use_the_new_teachers_and_shared_places_only_inform(): void
    {
        $f = $this->rightsFixture();
        [$lead, $shared] = $this->inTenant($f['organization'], function () use ($f): array {
            $lead = InstructorProfile::query()->create(['public_name' => 'Мария Колева']);
            $f['tuesday']->assignments()->attach($lead->id, ['role' => 'lead', 'version' => 1, 'starts_on' => '2026-11-05']);
            $shared = Location::query()->create(['name' => 'Парк', 'type' => 'physical', 'space_usage' => 'shared']);
            $other = $f['studio']->sessions()->create([
                'starts_at' => '2026-11-06T15:30:00Z', 'ends_at' => '2026-11-06T16:30:00Z', 'status' => 'scheduled', 'location_id' => $shared->id,
            ]);
            $other->instructors()->attach($lead->id, ['role' => 'lead']);

            return [$lead, $shared];
        });
        $body = $this->moveBody($f['session'], ['location_id' => $shared->id]);
        $preview = $this->previewChange($f['owner'], $f['organization'], $body)->assertOk();
        $warnings = collect($preview->json('warnings'))->keyBy('type');
        $this->assertTrue($warnings['instructor']['needs_confirmation']);
        $this->assertFalse($warnings['shared_location']['needs_confirmation']);
        $key = array_intersect_key($warnings['instructor'], array_flip(['type', 'session_id', 'other_session_id']));
        $this->applyChange($f['owner'], $f['organization'], $body)->assertUnprocessable();
        $this->applyChange($f['owner'], $f['organization'], [...$body, 'confirmed_warnings' => [$key]])->assertCreated();
        $this->signInTo($f['owner'], $f['organization'])->getJson('/api/v1/schedule?from=2026-11-02&to=2026-11-08')->assertOk()
            ->assertJsonFragment(['id' => $f['session']->id, 'moved' => true, 'moved_from' => '2026-11-03T15:00:00+00:00']);
        $this->applyChange($f['owner'], $f['organization'], $this->moveBody($this->current($f, $f['session']), [
            'date' => '2026-11-07', 'location_id' => null,
        ]))->assertCreated()->assertJsonPath('change.sessions.0.location_id', null);
    }

    public function test_move_query_count_does_not_grow_with_waiting_children(): void
    {
        $f = $this->rightsFixture();
        $body = $this->moveBody($f['session']);
        $count = function (string $day) use ($f, $body): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->applyChange($f['owner'], $f['organization'], [...$body,
                'date' => $day, 'sessions' => [['id' => $f['session']->id, 'version' => $this->current($f, $f['session'])->version]],
            ])->assertCreated();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };
        for ($i = 0; $i < 3; $i++) {
            $this->waitingFor($f, $f['session'], 'Дете '.$i);
        }
        // Warm up the membership's throttled last-seen write before measuring domain queries.
        $this->previewChange($f['owner'], $f['organization'], $body)->assertOk();
        $few = $count('2026-11-06');
        for ($i = 3; $i < 30; $i++) {
            $this->waitingFor($f, $f['session'], 'Дете '.$i);
        }
        $many = $count('2026-11-07');
        $this->assertSame($few, $many);
    }

    public function test_move_to_no_place_does_not_send_the_inherited_address(): void
    {
        $f = $this->rightsFixture();
        $this->inTenant($f['organization'], function () use ($f): void {
            $f['tuesday']->forceFill(['location_id' => $f['location']->id])->save();
            $f['session']->forceFill(['location_id' => $f['location']->id])->save();
        });
        $this->applyChange($f['owner'], $f['organization'], $this->moveBody($f['session'], ['location_id' => null]))->assertCreated();
        $sent = $this->deliverLetters();
        $this->assertNotEmpty($sent->sent);
        foreach ($sent->sent as $letter) {
            $text = CapturingMailSender::text($letter['mail']);
            $this->assertStringNotContainsString('Примерна', $text);
            $this->assertStringContainsString('Без място', $text);
        }
    }

    public function test_a_declined_visit_gets_no_move_transition_or_letter(): void
    {
        $f = $this->rightsFixture();
        $this->inTenant($f['organization'], fn () => $f['visit']->forceFill(['status' => 'ended'])->save());
        $this->previewChange($f['owner'], $f['organization'], $this->moveBody($f['session']))->assertOk()->assertJsonPath('totals.families', 1);
        $this->applyChange($f['owner'], $f['organization'], $this->moveBody($f['session']))->assertCreated();
        $this->assertSame(0, $this->inTenant($f['organization'], fn () => EnrollmentTransition::query()->where('enrollment_id', $f['visit']->id)->count()));
        $sent = $this->deliverLetters();
        $text = implode(' ', array_map(fn ($letter) => CapturingMailSender::text($letter['mail']), $sent->sent));
        $this->assertStringNotContainsString('Вики', $text);
    }

    public function test_a_visit_declined_after_move_drops_out_when_the_letter_is_composed(): void
    {
        $f = $this->rightsFixture();
        $this->applyChange($f['owner'], $f['organization'], $this->moveBody($f['session']))->assertCreated();
        $this->inTenant($f['organization'], fn () => $f['visit']->forceFill(['status' => 'ended'])->save());
        $sent = $this->deliverLetters();
        $text = implode(' ', array_map(fn ($letter) => CapturingMailSender::text($letter['mail']), $sent->sent));
        $this->assertStringNotContainsString('Вики', $text);
    }
}
