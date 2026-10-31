<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Catalog\Models\Location;
use App\Domain\Catalog\Models\ScheduleChange;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Models\SessionChange;
use App\Domain\Catalog\Services\ScheduleConflictDetector;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Enrollment\Models\EnrollmentEvent;
use App\Domain\Enrollment\Models\EnrollmentPause;
use App\Domain\Enrollment\Models\EnrollmentSessionException;
use App\Domain\Enrollment\Models\EnrollmentTransition;
use App\Domain\Enrollment\Services\VisitBooking;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\StaffGrant;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use RuntimeException;
use Tests\Support\ScheduleChangeFixture;
use Tests\TestCase;

/**
 * "Отмени" and "Възстанови" of one session (docs/product/schedule-changes.md, т. 4, 5, 7; PR 4а): who may, the checks in
 * their order, what a change does to the session, its history and the enrollments, the warnings confirmed by their key,
 * the key for a repeat, the preview and the audit log. The fixture (TeamRightsFixture) has "Шах · Вторник" on 3, 10, 17
 * and 24 November at 17:00 in Sofia, Ани for the period and Вики for one visit on the 3rd, Боби waiting for the group;
 * Monday 2 November, 12:00 in Sofia. Every name is synthetic.
 */
class ScheduleChangeApiTest extends TestCase
{
    use RefreshDatabase;
    use ScheduleChangeFixture;

    private const NOVEMBER = '/api/v1/schedule?from=2026-11-02&to=2026-11-29';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->travelTo('2026-11-02 10:00:00');
    }

    public function test_only_the_team_with_the_right_for_the_whole_organization_changes_the_schedule(): void
    {
        $f = $this->rightsFixture();
        $body = $this->changeBody('cancel', $f['session'], 1);
        foreach (['instructor', 'finance', 'viewer', 'platform_admin'] as $role) {
            $user = $this->memberOf($f['organization'], $role);
            if ($role !== 'platform_admin') {
                $this->personFor($f['organization'], $user, teaches: $role === 'instructor');
            }
            $this->previewChange($user, $f['organization'], $body)->assertForbidden()->assertJsonPath('message', 'Нямате право да променяте графика.');
            $this->applyChange($user, $f['organization'], $body)->assertForbidden();
        }
        // The platform operator has the right by role and sees why a session changed, but has no buttons (decision 8).
        $operator = $this->memberOf($f['organization'], 'platform_admin');
        $this->signInTo($operator, $f['organization'])->getJson(self::NOVEMBER)->assertOk()
            ->assertJsonPath("abilities.{$f['tuesday']->id}.manage", false);
        $this->getJson("/api/v1/schedule/sessions/{$f['session']->id}")->assertOk()
            ->assertJsonPath('abilities.manage', false)->assertJsonPath('actions', [])->assertJsonPath('session.change_reason', null);
        $this->assertSame('scheduled', $this->current($f, $f['session'])->status);

        // The right added for the whole organization is enough.
        $viewer = $this->memberOf($f['organization'], 'viewer');
        $person = $this->personFor($f['organization'], $viewer);
        $this->inTenant($f['organization'], fn () => StaffGrant::query()->create([
            'staff_member_id' => $person->id, 'ability' => 'schedule.manage', 'granted_by' => $f['owner']->id, 'granted_at' => now()->subDay(),
        ]));
        $this->previewChange($viewer, $f['organization'], $body)->assertOk()->assertJsonPath('totals.sessions', 1);
        $this->applyChange($viewer, $f['organization'], $body)->assertCreated();
        $this->assertSame('cancelled', $this->current($f, $f['session'])->status);
        $this->signInTo($viewer, $f['organization'])->getJson("/api/v1/schedule/sessions/{$f['session']->id}")->assertOk()
            ->assertJsonPath('abilities.manage', true)->assertJsonPath('actions', ['restore']);
    }

    #[TestGroup('tenant-isolation')]
    public function test_another_organizations_session_a_missing_one_and_a_drafts_are_not_found(): void
    {
        $f = $this->rightsFixture();
        $other = $this->rightsFixture();
        $draft = $this->inTenant($f['organization'], function () use ($f): Session {
            $group = $f['chess']->groups()->create(['name' => 'Чернова', 'capacity_max' => 10, 'status' => 'draft']);

            return $group->sessions()->create(['starts_at' => '2026-11-06T15:00:00Z', 'ends_at' => '2026-11-06T16:00:00Z', 'status' => 'scheduled']);
        });
        $missing = ['action' => 'cancel', 'sessions' => [['id' => (string) Str::uuid(), 'version' => 1]], 'reason' => 'Ремонт'];

        foreach ([$this->changeBody('cancel', $other['session'], 1), $missing, $this->changeBody('cancel', $draft, 1)] as $body) {
            $this->previewChange($f['owner'], $f['organization'], $body)->assertNotFound()->assertJsonPath('message', 'Заниманието не е намерено.');
            $this->applyChange($f['owner'], $f['organization'], $body)->assertNotFound()->assertJsonMissingPath('changed');
        }
        $this->assertSame('scheduled', $this->current($other, $other['session'])->status);
        foreach ([$f, $other] as $organization) {
            $this->assertSame(0, $this->inTenant($organization['organization'], fn (): int => ScheduleChange::query()->count()));
        }
        // An identifier in capitals is the same session.
        $this->previewChange($f['owner'], $f['organization'], [
            ...$this->changeBody('cancel', $f['session'], 1), 'sessions' => [['id' => Str::upper($f['session']->id), 'version' => 1]],
        ])->assertOk()->assertJsonPath('sessions.0.id', $f['session']->id);
    }

    public function test_the_form_is_checked_before_the_database(): void
    {
        $f = $this->rightsFixture();
        $preview = fn (array $body) => $this->previewChange($f['owner'], $f['organization'], [...$this->changeBody('cancel', $f['session'], 1), ...$body]);

        $preview(['action' => 'unknown'])->assertUnprocessable()->assertJsonPath('errors.action.0', 'Изберете действие.');
        $preview(['sessions' => []])->assertUnprocessable()->assertJsonPath('errors.sessions.0', 'Изберете от 1 до 300 занимания.');
        $preview(['sessions' => [['id' => $f['session']->id, 'version' => 1], ['id' => (string) Str::uuid(), 'version' => 1]]])
            ->assertUnprocessable()->assertJsonPath('errors.sessions.0', 'Изберете едно занимание.');
        $preview(['sessions' => [['id' => 'nope', 'version' => 1]]])->assertUnprocessable()
            ->assertJsonValidationErrors(['sessions.0.id' => 'Заниманието не е намерено.']);
        $preview(['sessions' => [['id' => $f['session']->id]]])->assertUnprocessable()->assertJsonValidationErrors('sessions.0.version');
        foreach ([null, '   ', str_repeat('а', 2001)] as $reason) {
            $preview(['reason' => $reason])->assertUnprocessable()->assertJsonPath('errors.reason.0', 'Напишете причина (до 2000 знака).');
        }
        $preview(['reason' => str_repeat('а', 2000)])->assertOk();
        // To restore, the reason may be left out: the session is checked next.
        $preview(['action' => 'restore', 'reason' => null])->assertUnprocessable()->assertJsonPath('errors.sessions.0', 'Заниманието не е отменено.');
        $preview(['confirmed_warnings' => [['type' => 'nope', 'session_id' => $f['session']->id, 'other_session_id' => null]]])
            ->assertUnprocessable()->assertJsonValidationErrors('confirmed_warnings.0.type');

        // The change needs its key for a repeat (1–120 characters).
        $body = $this->changeBody('cancel', $f['session'], 1);
        $this->signInTo($f['owner'], $f['organization'])->postJson('/api/v1/schedule/changes', $body)
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->applyChange($f['owner'], $f['organization'], $body, str_repeat('k', 121))->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->assertSame(0, $this->inTenant($f['organization'], fn (): int => ScheduleChange::query()->count()));
        $this->assertSame(1, $this->current($f, $f['session'])->version);
    }

    public function test_a_cancellation_checks_the_version_the_start_the_state_and_the_archive(): void
    {
        $f = $this->rightsFixture();

        $this->applyChange($f['owner'], $f['organization'], $this->changeBody('cancel', $f['session'], 7))->assertStatus(409)
            ->assertJsonPath('message', 'Заниманието е променено междувременно. Заредете графика отново.')
            ->assertJsonPath('changed', [$f['session']->id]);
        // Seen before it started, sent after it did.
        $this->previewChange($f['owner'], $f['organization'], $this->changeBody('cancel', $f['session'], 1))->assertOk();
        $this->travelTo('2026-11-03 15:00:00');
        $this->applyChange($f['owner'], $f['organization'], $this->changeBody('cancel', $f['session'], 1))->assertUnprocessable()
            ->assertJsonPath('errors.sessions.0', 'Заниманието вече е започнало.');
        $this->travelTo('2026-11-02 10:00:00');

        $this->changeAsOwner($f, 'cancel', $f['session'])->assertCreated();
        $this->changeAsOwner($f, 'cancel', $f['session'])->assertUnprocessable()->assertJsonPath('errors.sessions.0', 'Заниманието вече е отменено.');

        $next = $this->sessionOn($f, $f['tuesday'], '2026-11-10');
        $this->inTenant($f['organization'], fn () => Group::query()->whereKey($f['tuesday']->id)->update(['status' => 'archived']));
        $this->changeAsOwner($f, 'cancel', $next)->assertUnprocessable()->assertJsonPath('errors.sessions.0', 'Групата е в архива.');
        $this->assertSame(1, $this->inTenant($f['organization'], fn (): int => ScheduleChange::query()->count()));
        // Nor does the panel offer it: neither "Отмени" nor "Възстанови" in an archived group.
        foreach ([$next, $f['session']] as $session) {
            $this->signInTo($f['owner'], $f['organization'])->getJson("/api/v1/schedule/sessions/{$session->id}")->assertOk()
                ->assertJsonPath('abilities.manage', true)->assertJsonPath('actions', []);
        }
    }

    public function test_a_cancelled_session_tells_whom_it_concerns_and_frees_the_visits(): void
    {
        $f = $this->rightsFixture();
        $trial = $this->visitOn($f, $f['session'], 'Гери');
        $waiting = $this->waitingFor($f, $f['session'], 'Дани', 'waitlisted');
        $skipping = $this->enrolledIn($f, $f['tuesday'], 'Ема', '2026-10-20');
        $paused = $this->enrolledIn($f, $f['tuesday'], 'Жоро', '2026-10-20');
        $this->inTenant($f['organization'], function () use ($f, $skipping, $paused): void {
            EnrollmentSessionException::query()->create(['enrollment_id' => $skipping->id, 'session_id' => $f['session']->id, 'kind' => 'excluded']);
            EnrollmentPause::query()->create(['enrollment_id' => $paused->id, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-07', 'reason_category' => 'holiday']);
        });
        $next = $this->sessionOn($f, $f['tuesday'], '2026-11-10');
        $trialChild = $this->inTenant($f['organization'], fn () => Enrollment::query()->with('child')->findOrFail($trial->id)->child);
        $this->inTenant($f['organization'], function () use ($f, $trialChild, $next): void {
            try {
                app(VisitBooking::class)->assertTrialOnce($trialChild, $f['tuesday'], $next);
                $this->fail('A trial on the 3rd holds the trial of the year.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        });

        $response = $this->changeAsOwner($f, 'cancel', $f['session'], ['reason' => 'Треньорът е болен.'])->assertCreated();

        $change = $this->inTenant($f['organization'], fn (): ScheduleChange => ScheduleChange::query()->sole());
        $response->assertJsonPath('change.id', $change->id)->assertJsonPath('change.action', 'cancel')
            ->assertJsonPath('change.created_at', '2026-11-02T10:00:00+00:00')
            ->assertJsonPath('change.sessions', [[
                'id' => $f['session']->id, 'version' => 2, 'status' => 'cancelled',
                'starts_at' => '2026-11-03T15:00:00+00:00', 'ends_at' => '2026-11-03T16:00:00+00:00', 'location_id' => null,
            ]])
            // Ани's, Вики's, Гери's and Дани's families; not Ема, who skips it, nor Жоро on a pause, nor Боби waiting for the group.
            ->assertJsonPath('letters', ['families' => 4, 'teachers' => 0, 'without_verified_email' => 0]);
        $this->assertEquals($response->json(), $change->result);
        $this->assertSame(['cancel', 'Треньорът е болен.', false, true, 1, $f['owner']->id], [
            $change->action, $change->reason, $change->reason_to_parents, $change->notify_parents, $change->session_count, $change->actor_user_id,
        ]);

        $this->inTenant($f['organization'], function () use ($f, $change, $trial, $waiting, $skipping, $paused): void {
            $session = Session::query()->findOrFail($f['session']->id);
            $this->assertSame(['cancelled', 2, 'Треньорът е болен.'], [$session->status, $session->version, $session->change_reason]);
            $history = SessionChange::query()->sole();
            $this->assertSame([$change->id, 'scheduled', 'cancelled', [], '2026-11-03 15:00:00', '2026-11-03 15:00:00'], [
                $history->schedule_change_id, $history->from_status, $history->to_status, $history->confirmed_overlaps,
                $history->from_starts_at?->utc()->format('Y-m-d H:i:s'), $history->to_starts_at->utc()->format('Y-m-d H:i:s'),
            ]);

            // The visits end, cancelled by the organization: no fee, the trial free again.
            foreach ([$f['visit']->id, $trial->id] as $visitId) {
                $visit = Enrollment::query()->findOrFail($visitId);
                $this->assertSame(['cancelled', 2], [$visit->status->value, $visit->version]);
                $step = EnrollmentTransition::query()->where('enrollment_id', $visitId)->sole();
                $this->assertSame(['active', 'cancelled', 'Заниманието е отменено от организацията.'], [$step->from_status, $step->to_status, $step->public_reason]);
                $this->assertSame(['schedule_change_id' => $change->id, 'session_id' => $f['session']->id, 'effect' => 'visit_cancelled', 'by_organization' => true], $step->metadata);
            }
            // The application waiting for it is refused; the reason stays with the office.
            $refused = EnrollmentApplication::query()->findOrFail($waiting->id);
            $this->assertSame(['rejected', 2, 'Заниманието на 3 ноември е отменено.'], [$refused->status->value, $refused->version, $refused->public_reason]);
            $this->assertSame('waitlisted', EnrollmentApplication::query()->findOrFail($f['waiting']->id)->status->value);
            // The child of the period hears of it; the history says what happened, not why.
            $ani = Enrollment::query()->findOrFail($f['enrollment']->id);
            $this->assertSame(['active', 2], [$ani->status->value, $ani->version]);
            $step = EnrollmentTransition::query()->where('enrollment_id', $ani->id)->sole();
            $this->assertSame(['active', 'active', 'Заниманието е отменено от организацията.', 'cancelled'], [$step->from_status, $step->to_status, $step->public_reason, $step->metadata['effect'] ?? null]);
            // A skip and a pause stay as they are; whoever was not coming is not touched.
            $this->assertTrue(EnrollmentSessionException::query()->where('enrollment_id', $skipping->id)->exists());
            $this->assertSame(1, Enrollment::query()->findOrFail($skipping->id)->version);
            $this->assertSame(1, Enrollment::query()->findOrFail($paused->id)->version);
            $this->assertSame(1, EnrollmentPause::query()->where('enrollment_id', $paused->id)->whereNull('cancelled_at')->count());
            // One event per family, without the reason.
            $events = EnrollmentEvent::query()->where('type', 'schedule.sessions_changed')->get();
            $this->assertCount(4, $events);
            foreach ($events as $event) {
                $this->assertSame($change->id, $event->payload['schedule_change_id']);
                $this->assertStringNotContainsString('болен', (string) json_encode($event->payload, JSON_UNESCAPED_UNICODE));
            }
        });

        // The trial of the year is free again, and the session takes no places.
        $this->inTenant($f['organization'], fn () => app(VisitBooking::class)->assertTrialOnce($trialChild, $f['tuesday'], $next));
        $this->assertSame(0, collect($this->getJson(self::NOVEMBER)->json('sessions'))->firstWhere('id', $f['session']->id)['taken']);
    }

    public function test_the_start_of_an_enrollment_that_has_not_begun_follows_its_first_session(): void
    {
        $f = $this->rightsFixture();
        $first = $this->sessionOn($f, $f['thursday'], '2026-11-05');
        // Approved on 29 October: from the group's first session.
        $lia = $this->enrolledIn($f, $f['thursday'], 'Лия', '2026-11-05');

        $this->changeAsOwner($f, 'cancel', $first)->assertCreated();
        $this->inTenant($f['organization'], function () use ($lia): void {
            $enrollment = Enrollment::query()->findOrFail($lia->id);
            $this->assertSame(['2026-11-12', 2], [$enrollment->starts_on->toDateString(), $enrollment->version]);
            $step = EnrollmentTransition::query()->where('enrollment_id', $lia->id)->sole();
            $this->assertSame(['cancelled', '2026-11-05', '2026-11-12'], [$step->metadata['effect'] ?? null, $step->metadata['previous_starts_on'] ?? null, $step->metadata['starts_on'] ?? null]);
        });

        $this->changeAsOwner($f, 'restore', $first)->assertCreated();
        $this->inTenant($f['organization'], function () use ($f, $lia): void {
            $this->assertSame(['2026-11-05', 3], [Enrollment::query()->findOrFail($lia->id)->starts_on->toDateString(), Enrollment::query()->findOrFail($lia->id)->version]);
            // An enrollment that has begun keeps its start.
            $this->assertSame('2026-10-20', Enrollment::query()->findOrFail($f['enrollment']->id)->starts_on->toDateString());
        });
        // Another group's change does not touch it.
        $this->assertSame(0, $this->inTenant($f['organization'], fn (): int => EnrollmentTransition::query()
            ->where('enrollment_id', $f['enrollment']->id)->count()));
    }

    public function test_a_continuation_after_a_transfer_never_starts_before_its_day(): void
    {
        $f = $this->rightsFixture();
        $price = $this->inTenant($f['organization'], fn () => $f['chess']->priceOptions()->create([
            'group_id' => $f['thursday']->id, 'enrollment_mode' => 'monthly', 'unit' => 'month', 'amount_cents' => 4500, 'currency' => 'EUR', 'is_available' => true,
        ]));
        $movedId = $this->signInTo($f['owner'], $f['organization'])->postJson("/api/v1/enrollment/enrollments/{$f['enrollment']->id}/transfer", [
            'target_group_id' => $f['thursday']->id, 'price_option_id' => $price->id, 'effective_date' => '2026-11-12', 'reason' => 'Смяна на часа', 'version' => 1,
        ])->assertOk()->json('enrollment.id');
        $moved = fn (): Enrollment => $this->inTenant($f['organization'], fn (): Enrollment => Enrollment::query()->findOrFail($movedId));
        $this->assertSame(['2026-11-12', '2026-11-12'], [$moved()->starts_on->toDateString(), $moved()->starts_not_before?->toDateString()]);

        // Its first session cancelled, it starts on the 19th — not on the 5th, which counted in the old group.
        $twelfth = $this->sessionOn($f, $f['thursday'], '2026-11-12');
        $this->changeAsOwner($f, 'cancel', $twelfth)->assertCreated();
        $this->assertSame('2026-11-19', $moved()->starts_on->toDateString());
        $this->changeAsOwner($f, 'restore', $twelfth)->assertCreated();
        $this->assertSame('2026-11-12', $moved()->starts_on->toDateString());
        $fifth = $this->sessionOn($f, $f['thursday'], '2026-11-05');
        $this->changeAsOwner($f, 'cancel', $fifth)->assertCreated();
        $this->changeAsOwner($f, 'restore', $fifth)->assertCreated();
        $this->assertSame('2026-11-12', $moved()->starts_on->toDateString());

        // The bound is the column, never the event of the transfer: a continuation whose event says another day.
        $later = $this->enrolledIn($f, $f['thursday'], 'Нора', '2026-11-19', null, '2026-11-19');
        $this->inTenant($f['organization'], fn () => EnrollmentEvent::query()->create([
            'type' => 'enrollment.transferred', 'application_id' => $later->application_id, 'enrollment_id' => $later->id,
            'payload' => ['effective_date' => '2026-11-05'], 'occurred_at' => now(), 'expanded_at' => now(),
        ]));
        $nineteenth = $this->sessionOn($f, $f['thursday'], '2026-11-19');
        $this->changeAsOwner($f, 'cancel', $nineteenth)->assertCreated();
        $this->assertSame('2026-11-26', $this->inTenant($f['organization'], fn () => Enrollment::query()->findOrFail($later->id)->starts_on->toDateString()));
    }

    public function test_only_a_cancelled_session_that_has_not_started_comes_back(): void
    {
        $f = $this->rightsFixture();
        $this->changeAsOwner($f, 'restore', $f['session'])->assertUnprocessable()->assertJsonPath('errors.sessions.0', 'Заниманието не е отменено.');

        // Cancelled in the draft's editor, without history: it comes back too.
        $edited = $this->sessionOn($f, $f['tuesday'], '2026-11-10');
        $this->inTenant($f['organization'], fn () => Session::query()->whereKey($edited->id)->update(['status' => 'cancelled']));
        $this->changeAsOwner($f, 'restore', $edited)->assertCreated()->assertJsonPath('change.sessions.0.status', 'scheduled')
            ->assertJsonPath('change.sessions.0.version', 2);
        $this->assertNull($this->current($f, $edited)->change_reason);

        // Meanwhile another session of the group was put at that time.
        $third = $this->sessionOn($f, $f['tuesday'], '2026-11-17');
        $this->changeAsOwner($f, 'cancel', $third)->assertCreated();
        $this->inTenant($f['organization'], fn () => $f['tuesday']->sessions()->create(['starts_at' => '2026-11-17T15:30:00Z', 'ends_at' => '2026-11-17T16:30:00Z', 'status' => 'scheduled']));
        $this->changeAsOwner($f, 'restore', $third)->assertUnprocessable()->assertJsonPath('errors.sessions.0', 'Групата вече има занимание по това време.');

        $fourth = $this->sessionOn($f, $f['tuesday'], '2026-11-24');
        $this->changeAsOwner($f, 'cancel', $fourth)->assertCreated();
        $this->travelTo('2026-11-24 15:00:01');
        $this->changeAsOwner($f, 'restore', $fourth)->assertUnprocessable()->assertJsonPath('errors.sessions.0', 'Заниманието вече е започнало.');
        $this->assertSame('cancelled', $this->current($f, $fourth)->status);
    }

    public function test_a_session_back_takes_its_teachers_from_the_assignments_and_keeps_those_changed_by_hand(): void
    {
        $f = $this->rightsFixture();
        [$lead, $assistant, $old] = $this->inTenant($f['organization'], function () use ($f): array {
            $lead = InstructorProfile::query()->create(['public_name' => 'Мария Колева']);
            $assistant = InstructorProfile::query()->create(['public_name' => 'Иван Петров']);
            $old = InstructorProfile::query()->create(['public_name' => 'Петър Стоянов']);
            $f['tuesday']->assignments()->attach($lead->id, ['role' => 'lead', 'version' => 1, 'starts_on' => '2026-11-01']);
            $f['tuesday']->assignments()->attach($assistant->id, ['role' => 'assistant', 'version' => 1, 'starts_on' => '2026-11-01']);

            return [$lead, $assistant, $old];
        });
        $following = $this->sessionOn($f, $f['tuesday'], '2026-11-10');
        $byHand = $this->sessionOn($f, $f['tuesday'], '2026-11-17');
        $this->inTenant($f['organization'], function () use ($following, $byHand, $old): void {
            // While cancelled, the sessions kept who taught them before the assignments changed.
            foreach ([$following, $byHand] as $session) {
                $record = Session::query()->findOrFail($session->id);
                $record->instructors()->sync([$old->id => ['role' => 'lead']]);
                $record->forceFill(['status' => 'cancelled', 'instructors_source' => $session->is($byHand) ? 'manual' : 'assignment'])->save();
            }
        });

        $this->previewChange($f['owner'], $f['organization'], $this->changeBody('restore', $following, 1))->assertOk()
            ->assertJsonPath('warnings', []);
        $this->changeAsOwner($f, 'restore', $following)->assertCreated();
        $this->changeAsOwner($f, 'restore', $byHand)->assertCreated();

        $roles = fn (Session $session): array => $this->inTenant($f['organization'], fn (): array => DB::table('activity_session_instructors')
            ->where('session_id', $session->id)->orderBy('role')->pluck('role', 'instructor_profile_id')->all());
        $this->assertEquals([$assistant->id => 'assistant', $lead->id => 'lead'], $roles($following));
        $this->assertEquals([$old->id => 'lead'], $roles($byHand));
    }

    public function test_a_warning_to_confirm_needs_its_own_key_and_is_kept_with_the_change(): void
    {
        $f = $this->rightsFixture();
        $session = $this->sessionOn($f, $f['tuesday'], '2026-11-10');
        [$other, $teacher] = $this->inTenant($f['organization'], function () use ($f): array {
            $teacher = InstructorProfile::query()->create(['public_name' => 'Мария Колева']);
            $f['tuesday']->assignments()->attach($teacher->id, ['role' => 'lead', 'version' => 1]);
            $other = $f['studio']->sessions()->create(['starts_at' => '2026-11-10T15:00:00Z', 'ends_at' => '2026-11-10T16:00:00Z', 'status' => 'scheduled']);
            $other->instructors()->attach($teacher->id, ['role' => 'lead']);

            return [$other, $teacher];
        });
        $this->changeAsOwner($f, 'cancel', $session)->assertCreated();

        $key = ['type' => 'instructor', 'session_id' => $session->id, 'other_session_id' => $other->id];
        $this->previewChange($f['owner'], $f['organization'], $this->changeBody('restore', $session, 2))->assertOk()
            ->assertJsonPath('warnings', [[...$key, 'needs_confirmation' => true, 'message' => 'Мария Колева води друго занимание по това време.']]);
        $this->changeAsOwner($f, 'restore', $session)->assertUnprocessable()->assertJsonValidationErrors('confirmed_warnings')
            ->assertJsonPath('errors.confirmed_warnings.0', 'Има ново предупреждение. Прегледайте го и потвърдете отново.')
            ->assertJsonPath('warnings.0.other_session_id', $other->id);

        // A new overlap after the preview: the key of the one seen does not cover it.
        $another = $this->inTenant($f['organization'], function () use ($f, $teacher): Session {
            $another = $f['thursday']->sessions()->create(['starts_at' => '2026-11-10T15:30:00Z', 'ends_at' => '2026-11-10T16:30:00Z', 'status' => 'scheduled']);
            $another->instructors()->attach($teacher->id, ['role' => 'lead']);

            return $another;
        });
        $this->changeAsOwner($f, 'restore', $session, ['confirmed_warnings' => [$key]])->assertUnprocessable()
            ->assertJsonValidationErrors('confirmed_warnings')->assertJsonCount(2, 'warnings');

        // Both seen and confirmed; a key whose warning is gone does no harm.
        $second = ['type' => 'instructor', 'session_id' => $session->id, 'other_session_id' => $another->id];
        $gone = ['type' => 'exclusive_location', 'session_id' => $session->id, 'other_session_id' => (string) Str::uuid()];
        $this->changeAsOwner($f, 'restore', $session, ['confirmed_warnings' => [$second, $gone, $key]])->assertCreated();
        $kept = $this->inTenant($f['organization'], fn (): SessionChange => SessionChange::query()->where('session_id', $session->id)->where('to_status', 'scheduled')->sole());
        $this->assertSame([$key, $second], $kept->confirmed_overlaps);
    }

    public function test_a_shared_place_only_warns_and_a_place_for_one_group_and_too_many_children_need_a_confirmation(): void
    {
        $f = $this->rightsFixture();
        [$shared, $sharedSession, $exclusiveSession, $full] = $this->inTenant($f['organization'], function () use ($f): array {
            $shared = Location::query()->create(['name' => 'Зала „Слънце“', 'type' => 'physical', 'space_usage' => 'shared', 'address' => 'ул. Примерна 2']);
            $tenth = Session::query()->where('group_id', $f['tuesday']->id)->where('starts_at', '>=', '2026-11-10 00:00:00')->orderBy('starts_at')->firstOrFail();
            $tenth->forceFill(['location_id' => $shared->id])->save();
            $f['studio']->sessions()->create(['starts_at' => '2026-11-10T15:30:00Z', 'ends_at' => '2026-11-10T16:30:00Z', 'status' => 'scheduled', 'location_id' => $shared->id]);
            $seventeenth = Session::query()->where('group_id', $f['tuesday']->id)->where('starts_at', '>=', '2026-11-17 00:00:00')->orderBy('starts_at')->firstOrFail();
            $seventeenth->forceFill(['location_id' => $f['location']->id])->save();
            $f['studio']->sessions()->create(['starts_at' => '2026-11-17T15:00:00Z', 'ends_at' => '2026-11-17T16:00:00Z', 'status' => 'scheduled', 'location_id' => $f['location']->id]);
            $last = Session::query()->where('group_id', $f['tuesday']->id)->where('starts_at', '>=', '2026-11-24 00:00:00')->firstOrFail();
            $last->forceFill(['capacity' => 1])->save();

            return [$shared, $tenth, $seventeenth, $last];
        });
        $this->enrolledIn($f, $f['tuesday'], 'Ема', '2026-10-20');
        foreach ([$sharedSession, $exclusiveSession, $full] as $session) {
            $this->changeAsOwner($f, 'cancel', $session)->assertCreated();
        }
        $types = fn (Session $session): array => array_column($this->previewChange($f['owner'], $f['organization'],
            $this->changeBody('restore', $session, $this->current($f, $session)->version))->assertOk()->json('warnings'), 'needs_confirmation', 'type');

        // Nobody leads the group by assignment in the fixture: a warning to know.
        $this->assertSame(['shared_location' => false, 'no_lead' => false], $types($sharedSession));
        $warning = collect($this->previewChange($f['owner'], $f['organization'], $this->changeBody('restore', $sharedSession, 2))->json('warnings'))->firstWhere('type', 'shared_location');
        $this->assertSame('Зала „Слънце“ е заета 17:30–18:30 от Рисуване · Ателие.', $warning['message']);
        $this->changeAsOwner($f, 'restore', $sharedSession)->assertCreated();

        $this->assertSame(['exclusive_location' => true, 'no_lead' => false], $types($exclusiveSession));
        $this->changeAsOwner($f, 'restore', $exclusiveSession)->assertUnprocessable()->assertJsonValidationErrors('confirmed_warnings');

        // Decision 7: more children than places — to confirm, not refused.
        $this->assertSame(['no_lead' => false, 'overbooked' => true], $types($full));
        $overbooked = collect($this->previewChange($f['owner'], $f['organization'], $this->changeBody('restore', $full, 2))->json('warnings'))->firstWhere('type', 'overbooked');
        $this->assertSame([null, 'На заниманието ще дойдат 2 деца, а местата са 1. Докато това занимание не мине, новите заявки за групата ще чакат за място.'],
            [$overbooked['other_session_id'], $overbooked['message']]);
        $this->changeAsOwner($f, 'restore', $full)->assertUnprocessable()->assertJsonValidationErrors('confirmed_warnings');
        $this->changeAsOwner($f, 'restore', $full, ['confirmed_warnings' => [['type' => 'overbooked', 'session_id' => $full->id, 'other_session_id' => null]]])->assertCreated();
        $this->assertSame('scheduled', $this->current($f, $full)->status);
        $this->assertNotNull($shared->id);
    }

    public function test_a_confirmed_overlap_no_longer_stops_publishing_the_group(): void
    {
        $f = $this->rightsFixture();
        $session = $this->sessionOn($f, $f['tuesday'], '2026-11-10');
        $other = $this->inTenant($f['organization'], function () use ($f, $session): Session {
            Session::query()->whereKey($session->id)->update(['location_id' => $f['location']->id]);

            return $f['studio']->sessions()->create(['starts_at' => '2026-11-10T15:00:00Z', 'ends_at' => '2026-11-10T16:00:00Z', 'status' => 'scheduled', 'location_id' => $f['location']->id]);
        });
        $blocking = fn (): array => $this->inTenant($f['organization'], fn (): array => array_values(array_filter(
            app(ScheduleConflictDetector::class)->forGroup(Group::query()->findOrFail($f['tuesday']->id)),
            fn (array $conflict): bool => $conflict['blocking'],
        )));
        $this->assertSame(['exclusive_location'], array_column($blocking(), 'type'));

        $this->changeAsOwner($f, 'cancel', $session)->assertCreated();
        $this->changeAsOwner($f, 'restore', $session, ['confirmed_warnings' => [
            ['type' => 'exclusive_location', 'session_id' => $session->id, 'other_session_id' => $other->id],
        ]])->assertCreated();

        // `PublishVisitOffer` and `PublishActivityInternally` stop on a blocking conflict of the group: this one is confirmed.
        $this->assertSame([], $blocking());
        // Another overlap of the same session still stops.
        $this->inTenant($f['organization'], fn () => $f['thursday']->sessions()->create([
            'starts_at' => '2026-11-10T15:30:00Z', 'ends_at' => '2026-11-10T16:30:00Z', 'status' => 'scheduled', 'location_id' => $f['location']->id,
        ]));
        $this->assertCount(1, $blocking());
    }

    public function test_a_repeat_with_the_same_key_answers_as_the_first_and_another_body_is_refused(): void
    {
        $f = $this->rightsFixture();
        $body = $this->changeBody('cancel', $f['session'], 1);
        $first = $this->applyChange($f['owner'], $f['organization'], $body, 'cancel-3-november')->assertCreated();
        $events = $this->inTenant($f['organization'], fn (): int => EnrollmentEvent::query()->count());

        // The versions have moved on with the first: the stored answer, not a 409 — no second change, no second letter.
        $this->applyChange($f['owner'], $f['organization'], $body, 'cancel-3-november')->assertOk()->assertExactJson($first->json());
        // The same body in another form: identifiers in capitals, spaces around the reason.
        $this->applyChange($f['owner'], $f['organization'], [...$body,
            'sessions' => [['id' => Str::upper($f['session']->id), 'version' => 1]], 'reason' => '  Залата е заета за родителска среща.  ',
        ], 'cancel-3-november')->assertOk()->assertJsonPath('change.id', $first->json('change.id'));
        $this->applyChange($f['owner'], $f['organization'], [...$body, 'reason' => 'Друга причина'], 'cancel-3-november')->assertUnprocessable()
            ->assertJsonPath('errors.idempotency_key.0', 'Тази заявка вече е изпратена с други данни. Заредете графика отново.');
        // The key is the person's own: another person with the same key is a new request.
        $admin = $this->memberOf($f['organization'], 'organization_admin');
        $this->personFor($f['organization'], $admin);
        $this->applyChange($admin, $f['organization'], $body, 'cancel-3-november')->assertStatus(409);

        $this->assertSame(1, $this->inTenant($f['organization'], fn (): int => ScheduleChange::query()->count()));
        $this->assertSame($events, $this->inTenant($f['organization'], fn (): int => EnrollmentEvent::query()->count()));
        $this->assertSame(2, $this->current($f, $f['session'])->version);
        $sent = $this->deliverLetters();
        $this->assertCount(1, $sent->to(User::query()->whereKey($this->parentOf($f, $f['enrollment']))->value('email')));
    }

    public function test_the_preview_says_what_the_change_will_do_and_writes_nothing(): void
    {
        $f = $this->rightsFixture();
        $this->visitOn($f, $f['session'], 'Гери');
        $this->waitingFor($f, $f['session'], 'Дани');
        $this->enrolledIn($f, $f['tuesday'], 'Ема', '2026-10-20', User::factory()->unverified()->create());
        $count = fn (): array => [
            DB::table('audit_events')->count(),
            $this->inTenant($f['organization'], fn (): int => ScheduleChange::query()->count()),
            $this->inTenant($f['organization'], fn (): int => EnrollmentEvent::query()->count()),
            $this->inTenant($f['organization'], fn (): int => EnrollmentTransition::query()->count()),
        ];
        $before = $count();

        $preview = $this->previewChange($f['owner'], $f['organization'], $this->changeBody('cancel', $f['session'], 1))->assertOk();

        $preview->assertJsonPath('action', 'cancel')->assertJsonPath('sessions', [[
            'id' => $f['session']->id, 'version' => 1, 'starts_at' => '2026-11-03T15:00:00+00:00', 'ends_at' => '2026-11-03T16:00:00+00:00',
            'group_id' => $f['tuesday']->id, 'activity_id' => $f['activity']->id, 'location_id' => null,
            // Ани, Ема, Вики and Гери come; Дани's family also hears.
            'children' => 4, 'visits' => 2, 'families' => 5,
        ]])->assertJsonPath('skipped', ['started' => 0, 'cancelled' => 0, 'same_location' => 0])->assertJsonPath('totals', [
            'sessions' => 1, 'groups' => 1, 'children' => 4, 'families' => 5, 'letters' => 4, 'teachers' => 0, 'without_verified_email' => 1,
            'visits' => 2, 'visit_applications' => 1, 'skips_dropped' => 0, 'not_attending_after' => 0, 'starts_changed' => 0,
        ])->assertJsonPath('warnings', [])->assertJsonPath('moved_from', null);
        // Never a child's or a parent's name.
        foreach (['Ани', 'Гери', 'Дани', 'Ема', 'Мария', 'Иванова'] as $name) {
            $this->assertStringNotContainsString($name, (string) $preview->getContent());
        }
        $this->assertSame($before, $count());
        $this->assertSame(['scheduled', 1], [$this->current($f, $f['session'])->status, $this->current($f, $f['session'])->version]);

        // The change says the same.
        $this->changeAsOwner($f, 'cancel', $f['session'])->assertCreated()
            ->assertJsonPath('letters', ['families' => 4, 'teachers' => 0, 'without_verified_email' => 1]);
    }

    public function test_the_audit_log_has_numbers_and_identifiers_never_the_reason(): void
    {
        $f = $this->rightsFixture();

        $id = $this->changeAsOwner($f, 'cancel', $f['session'], ['reason' => 'Треньорът е болен.', 'reason_to_parents' => true])
            ->assertCreated()->json('change.id');

        $event = DB::table('audit_events')->where('action', 'schedule.change_applied')->sole();
        $this->assertSame(['schedule_change', $id, $f['organization']->id, $f['owner']->id], [$event->subject_type, $event->subject_id, $event->organization_id, $event->actor_id]);
        $this->assertStringNotContainsString('болен', (string) $event->metadata);
        $this->assertEquals([
            'action' => 'cancel', 'session_count' => 1, 'notify_parents' => true, 'reason_to_parents' => true,
            'session_id' => $f['session']->id, 'from_starts_at' => '2026-11-03T15:00:00+00:00', 'to_starts_at' => '2026-11-03T15:00:00+00:00',
            'from_location_id' => null, 'to_location_id' => null,
            // Вики's visit; Ани's and Вики's families.
            'visits_cancelled' => 1, 'applications_rejected' => 0, 'skips_dropped' => 0, 'starts_changed' => 0, 'families' => 2,
        ], json_decode((string) $event->metadata, true));
        // With the reason for the parents, the history of the enrollment has it as it was written.
        $this->assertSame('Треньорът е болен.', $this->inTenant($f['organization'], fn () => EnrollmentTransition::query()
            ->where('enrollment_id', $f['enrollment']->id)->value('public_reason')));
    }

    public function test_why_a_session_changed_is_not_shown_to_who_only_views_the_catalog(): void
    {
        $f = $this->rightsFixture();
        $this->changeAsOwner($f, 'cancel', $f['session'], ['reason' => 'Треньорът е болен.'])->assertCreated();
        $url = "/api/v1/catalog/activities/{$f['activity']->id}/groups";
        $cancelled = fn (array $groups): array => collect($groups)->flatMap(fn (array $group): array => $group['sessions'])
            ->firstWhere('id', $f['session']->id);

        // "Само преглед" sees the catalog, and that the session is cancelled, but not why.
        $viewer = $this->memberOf($f['organization'], 'viewer');
        $this->personFor($f['organization'], $viewer);
        $seen = $this->signInTo($viewer, $f['organization'])->getJson($url)->assertOk();
        $this->assertSame('cancelled', $cancelled($seen->json('groups'))['status']);
        $this->assertArrayNotHasKey('change_reason', $cancelled($seen->json('groups')));
        $this->assertStringNotContainsString('болен', (string) $seen->getContent());

        // Who changes the schedule sees it, as in the panel.
        $office = $this->signInTo($f['owner'], $f['organization'])->getJson($url)->assertOk();
        $this->assertSame('Треньорът е болен.', $cancelled($office->json('groups'))['change_reason']);
    }

    public function test_a_failure_leaves_neither_the_change_nor_a_letter(): void
    {
        $f = $this->rightsFixture();
        DB::listen(function ($query): void {
            if (str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'audit_events')) {
                throw new RuntimeException('The audit log is down.');
            }
        });

        $this->changeAsOwner($f, 'cancel', $f['session'])->assertServerError();

        $this->assertSame(['scheduled', 1], [$this->current($f, $f['session'])->status, $this->current($f, $f['session'])->version]);
        $this->inTenant($f['organization'], function () use ($f): void {
            $this->assertSame(0, ScheduleChange::query()->count());
            $this->assertSame(0, SessionChange::query()->count());
            $this->assertSame(0, EnrollmentEvent::query()->where('type', 'like', 'schedule.%')->count());
            $this->assertSame(0, EnrollmentTransition::query()->count());
            $this->assertSame('active', Enrollment::query()->findOrFail($f['visit']->id)->status->value);
        });
    }

    public function test_the_schedule_shows_the_version_and_the_panel_the_latest_change_and_what_may_be_done(): void
    {
        $f = $this->rightsFixture();
        $this->signInTo($f['owner'], $f['organization'])->getJson(self::NOVEMBER)->assertOk()
            ->assertJsonPath('sessions.0.version', 1)->assertJsonPath('sessions.0.moved', false)
            ->assertJsonPath('sessions.0.moved_from', null)->assertJsonPath('sessions.0.extra', false);
        $panel = fn (User $user) => $this->signInTo($user, $f['organization'])->getJson("/api/v1/schedule/sessions/{$f['session']->id}")->assertOk();
        $panel($f['owner'])->assertJsonPath('last_change', null)->assertJsonPath('actions', ['cancel']);

        $this->changeAsOwner($f, 'cancel', $f['session'], ['reason' => 'Ремонт на залата'])->assertCreated();

        $panel($f['owner'])->assertJsonPath('session.version', 2)->assertJsonPath('session.status', 'cancelled')
            ->assertJsonPath('last_change', ['action' => 'cancel', 'at' => '2026-11-02T10:00:00+00:00', 'by' => 'Собственик', 'reason' => 'Ремонт на залата'])
            ->assertJsonPath('actions', ['restore']);
        // The teacher sees that it is cancelled and when — not why, nor who.
        $teacher = $this->memberOf($f['organization'], 'instructor');
        $profile = $this->profileOf($f['organization'], $this->personFor($f['organization'], $teacher, teaches: true));
        $this->inTenant($f['organization'], fn () => $f['tuesday']->assignments()->attach($profile->id, ['role' => 'lead', 'version' => 1]));
        $panel($teacher)->assertJsonPath('last_change', ['action' => 'cancel', 'at' => '2026-11-02T10:00:00+00:00'])
            ->assertJsonPath('actions', [])->assertJsonPath('abilities.manage', false);

        // Moved by the group's schedule; added from the schedule; started — nothing to do.
        $this->inTenant($f['organization'], function () use ($f): void {
            Session::query()->where('group_id', $f['tuesday']->id)->where('starts_at', '>=', '2026-11-10 00:00:00')->where('starts_at', '<', '2026-11-11 00:00:00')
                ->update(['original_starts_at' => '2026-11-09 15:00:00', 'extra' => true]);
        });
        $tenth = $this->sessionOn($f, $f['tuesday'], '2026-11-10');
        $row = collect($this->signInTo($f['owner'], $f['organization'])->getJson(self::NOVEMBER)->json('sessions'))->firstWhere('id', $tenth->id);
        $this->assertSame([true, '2026-11-09T15:00:00+00:00', true], [$row['moved'], $row['moved_from'], $row['extra']]);
        $this->travelTo('2026-11-10 15:30:00');
        $this->signInTo($f['owner'], $f['organization'])->getJson("/api/v1/schedule/sessions/{$tenth->id}")->assertOk()->assertJsonPath('actions', []);
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function parentOf(array $f, Enrollment $enrollment): string
    {
        return $this->inTenant($f['organization'], fn (): string => (string) EnrollmentApplication::query()->whereKey($enrollment->application_id)->value('parent_user_id'));
    }
}
