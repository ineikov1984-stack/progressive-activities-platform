<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Actions\RecordAttendanceSession;
use App\Domain\Catalog\Models\Session;
use App\Domain\Enrollment\Models\Child;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Organizations\Models\StaffGrant;
use App\Domain\Organizations\Models\StaffScopeGroup;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Tests\Support\TeamRightsFixture;
use Tests\Support\VisitOfferFixture;
use Tests\TestCase;

class AttendanceMonthTest extends TestCase
{
    use RefreshDatabase, TeamRightsFixture, VisitOfferFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->travelTo('2026-11-20T12:00:00Z');
    }

    public function test_month_combines_states_totals_topics_and_separate_visit_rows(): void
    {
        $f = $this->rightsFixture();
        $sessions = $this->inTenant($f['organization'], fn () => Session::query()->where('group_id', $f['tuesday']->id)->orderBy('starts_at')->get());
        $pausedPresent = $this->copySession($f, '2026-11-04T15:00:00Z');
        $paused = $this->copySession($f, '2026-11-05T15:00:00Z');
        $unmarked = $this->copySession($f, '2026-11-06T15:00:00Z');
        $cancelled = $this->copySession($f, '2026-11-07T15:00:00Z', 'cancelled');
        $notHeld = $this->copySession($f, '2026-11-08T15:00:00Z');
        $this->inTenant($f['organization'], function () use ($f, $sessions, $notHeld): void {
            $f['enrollment']->pauses()->create(['starts_on' => '2026-11-04', 'ends_on' => '2026-11-05', 'reason_category' => 'other']);
            $f['enrollment']->sessionExceptions()->create(['session_id' => $sessions[1]->id, 'kind' => 'excluded', 'created_by' => $f['owner']->id]);
            $this->visitEnrollment(Child::query()->findOrFail($f['enrollment']->child_id), $f['tuesday'], $sessions[0], 'trial');
            app(RecordAttendanceSession::class)->handle($f['owner'], $sessions[0], ['action' => 'topic', 'topic' => 'Мат с топ']);
            app(RecordAttendanceSession::class)->handle($f['owner'], $notHeld, ['action' => 'not_held', 'reason' => 'Само за офиса']);
        });
        $this->mark($f, $sessions[0], $f['enrollment'], 'present');
        $this->mark($f, $sessions[2], $f['enrollment'], 'absent');
        // Paused attendance is recorded in its ordinary correction period.
        $this->travelTo('2026-11-04T15:10:00Z');
        $this->mark($f, $pausedPresent, $f['enrollment'], 'present');
        $this->travelTo('2026-11-20T12:00:00Z');
        $response = $this->signInTo($f['owner'], $f['organization'])->getJson($this->monthUrl($f))->assertOk()->assertJsonCount(9, 'sessions')->assertJsonCount(3, 'rows');
        $rows = collect($response->json('rows'));
        $row = $rows->firstWhere('kind', 'period');
        foreach ([$sessions[0]->id => 'present', $sessions[1]->id => 'warned', $sessions[2]->id => 'absent', $sessions[3]->id => 'future',
            $pausedPresent->id => 'paused_present', $paused->id => 'paused', $unmarked->id => 'unmarked', $cancelled->id => 'cancelled', $notHeld->id => 'not_held'] as $id => $state) {
            $this->assertSame($state, $row['cells'][$id]['state']);
            $this->assertSame($f['enrollment']->id, $row['cells'][$id]['enrollment_id']);
        }
        $this->assertSame([1, 2, 33], [$row['present'], $row['absent'], $row['percent']]);
        $this->assertCount(1, $rows->firstWhere('kind', 'trial')['cells']);
        $this->assertNull($rows->firstWhere('kind', 'trial')['percent']);
        $sessionRows = collect($response->json('sessions'))->keyBy('id');
        $this->assertSame('Мат с топ', $sessionRows[$sessions[0]->id]['topic']);
        $this->assertSame([1, 3], [$sessionRows[$sessions[0]->id]['present'], $sessionRows[$sessions[0]->id]['expected']]);
        $this->assertNull($sessionRows[$cancelled->id]['present']);
        $this->assertNull($sessionRows[$notHeld->id]['expected']);
        $this->assertStringNotContainsString('Само за офиса', $response->getContent());
    }

    public function test_returning_enrollment_is_one_row_with_only_its_applicable_cells(): void
    {
        $f = $this->rightsFixture();
        $sessions = $this->inTenant($f['organization'], fn () => $f['tuesday']->sessions()->orderBy('starts_at')->get());
        $returned = $this->inTenant($f['organization'], function () use ($f): Enrollment {
            $f['enrollment']->update(['ends_on' => '2026-11-03', 'status' => 'ended']);

            return $this->visitEnrollment(Child::query()->findOrFail($f['enrollment']->child_id), $f['tuesday'], null, 'full_period', startsOn: '2026-11-17');
        });
        $this->mark($f, $sessions[0], $f['enrollment'], 'present');
        $this->mark($f, $sessions[2], $returned, 'absent');
        $row = collect($this->signInTo($f['owner'], $f['organization'])->getJson($this->monthUrl($f))->assertOk()->json('rows'))->firstWhere('kind', 'period');
        $this->assertCount(3, $row['cells']);
        $this->assertArrayNotHasKey($sessions[1]->id, $row['cells']);
        $this->assertSame($returned->id, $row['cells'][$sessions[2]->id]['enrollment_id']);
        $this->assertSame(50, $row['percent']);
    }

    public function test_dictionary_defaults_archived_requests_and_month_validation_use_sofia(): void
    {
        $f = $this->rightsFixture();
        $this->travelTo('2026-10-31T22:30:00Z'); // November in Sofia.
        $this->signInTo($f['owner'], $f['organization'])->getJson('/api/v1/attendance/month')->assertOk()->assertJsonPath('month', '2026-11')->assertJsonPath('group.label', 'Рисуване · Ателие');
        foreach (['2019-12', '2027-12', '2026-1', 'wrong', '2026-13', ''] as $month) {
            $this->getJson($this->monthUrl($f, $month))->assertUnprocessable()->assertJsonValidationErrors('month');
        }
        $this->getJson($this->monthUrl($f, '2027-11'))->assertOk();
        $this->inTenant($f['organization'], fn () => $f['tuesday']->update(['status' => 'archived']));
        $this->getJson($this->monthUrl($f))->assertOk()->assertJsonFragment(['id' => $f['tuesday']->id, 'label' => 'Шах · Вторник']);
        $this->getJson('/api/v1/attendance/month')->assertOk()->assertJsonMissing(['id' => $f['tuesday']->id, 'label' => 'Шах · Вторник']);
        $this->inTenant($f['organization'], fn () => $f['tuesday']->update(['status' => 'draft']));
        $this->getJson($this->monthUrl($f))->assertNotFound();
    }

    public function test_month_requires_view_month_or_begun_assignment_and_child_links_follow_card_scope(): void
    {
        $f = $this->rightsFixture();
        $teacher = $this->memberOf($f['organization'], 'instructor');
        $person = $this->personFor($f['organization'], $teacher, true);
        $profile = $this->profileOf($f['organization'], $person);
        $this->inTenant($f['organization'], fn () => $f['session']->instructors()->attach($profile->id, ['role' => 'lead']));
        $this->signInTo($teacher, $f['organization'])->getJson($this->monthUrl($f))->assertForbidden();
        $this->inTenant($f['organization'], fn () => $f['tuesday']->assignments()->attach($profile->id, ['role' => 'assistant', 'starts_on' => '2026-11-21']));
        $this->getJson($this->monthUrl($f))->assertForbidden();
        $this->inTenant($f['organization'], fn () => $f['tuesday']->assignments()->updateExistingPivot($profile->id, ['starts_on' => '2026-11-20']));
        $this->getJson($this->monthUrl($f))->assertOk()->assertJsonPath('abilities.correct_late', false)->assertJsonPath('abilities.children', true)->assertJsonPath('rows.0.child_id', $f['enrollment']->child_id);
        $this->getJson('/api/v1/attendance/month?group='.$f['thursday']->id)->assertForbidden();
        $viewer = $this->memberOf($f['organization'], 'instructor');
        $viewerPerson = $this->personFor($f['organization'], $viewer);
        $this->inTenant($f['organization'], function () use ($f, $viewerPerson): void {
            $viewerPerson->forceFill(['access_scope' => 'groups'])->save();
            StaffGrant::query()->create(['staff_member_id' => $viewerPerson->id, 'ability' => 'attendance.view_month', 'granted_by' => $f['owner']->id, 'granted_at' => now()]);
            StaffScopeGroup::query()->create(['staff_member_id' => $viewerPerson->id, 'group_id' => $f['tuesday']->id, 'added_by' => $f['owner']->id, 'added_at' => now()]);
        });
        $this->signInTo($viewer, $f['organization'])->getJson($this->monthUrl($f))->assertOk()->assertJsonPath('abilities.children', false)->assertJsonMissingPath('rows.0.child_id');
        $platform = $this->memberOf($f['organization'], 'platform_admin');
        $this->signInTo($platform, $f['organization'])->getJson($this->monthUrl($f))->assertForbidden();
    }

    public function test_child_card_attendance_is_batched_filtered_and_ignores_future_and_paused_in_percent(): void
    {
        $f = $this->rightsFixture();
        $this->mark($f, $f['session'], $f['enrollment'], 'present');
        $url = '/api/v1/enrollment/children/'.$f['enrollment']->child_id;
        $this->signInTo($f['owner'], $f['organization'])->getJson($url)->assertOk()->assertJsonPath('groups.0.attendance.month', '2026-11')
            ->assertJsonPath('groups.0.attendance.present', 1)->assertJsonPath('groups.0.attendance.percent', 100)->assertJsonCount(3, 'groups.0.attendance.sessions');
        $this->getJson($url.'?month=2026-12')->assertOk()->assertJsonPath('groups.0.attendance.percent', null)->assertJsonPath('groups.0.attendance.sessions', []);
        $this->getJson($url.'?month=bad')->assertUnprocessable()->assertJsonValidationErrors('month');
        $teacher = $this->memberOf($f['organization'], 'instructor');
        $person = $this->personFor($f['organization'], $teacher, true);
        $profile = $this->profileOf($f['organization'], $person);
        $this->inTenant($f['organization'], fn () => $f['tuesday']->assignments()->attach($profile->id, ['role' => 'lead', 'starts_on' => '2026-11-21']));
        $this->signInTo($teacher, $f['organization'])->getJson($url)->assertOk()->assertJsonPath('groups.0.attendance', null);
        $this->inTenant($f['organization'], fn () => $f['tuesday']->assignments()->updateExistingPivot($profile->id, ['starts_on' => '2026-11-20']));
        $this->getJson($url)->assertOk()->assertJsonPath('groups.0.attendance.present', 1);
    }

    #[TestGroup('tenant-isolation')]
    public function test_the_same_child_in_two_organizations_does_not_mix_attendance(): void
    {
        $f = $this->rightsFixture();
        $other = $this->rightsFixture();
        $otherEnrollment = $this->inTenant($other['organization'], fn () => $this->visitEnrollment(Child::query()->findOrFail($f['enrollment']->child_id), $other['tuesday'], null, 'full_period'));
        $this->mark($f, $f['session'], $f['enrollment'], 'present');
        $this->mark($other, $other['session'], $otherEnrollment, 'absent');
        $this->signInTo($f['owner'], $f['organization'])->getJson('/api/v1/enrollment/children/'.$f['enrollment']->child_id)->assertOk()->assertJsonCount(1, 'groups')->assertJsonPath('groups.0.attendance.present', 1)->assertJsonPath('groups.0.attendance.absent', 0);
        $this->getJson('/api/v1/attendance/month?group='.$other['tuesday']->id)->assertNotFound();
        $this->getJson($this->monthUrl($f))->assertOk()->assertJsonMissing(['enrollment_id' => $otherEnrollment->id]);
    }

    public function test_month_queries_do_not_grow_with_children_or_sessions(): void
    {
        $f = $this->rightsFixture();
        $this->inTenant($f['organization'], function () use ($f): void {
            $child = $f['owner']->children()->create(['first_name' => 'Ново', 'last_name' => 'Дете']);
            $this->visitEnrollment($child, $f['tuesday'], null, 'full_period');
        });
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $count = function (int $rows, int $sessions) use ($f, &$queries): int {
            $this->signInTo($f['owner'], $f['organization'])->getJson($this->monthUrl($f))->assertOk();
            $queries = 0;
            $this->getJson($this->monthUrl($f))->assertOk()->assertJsonCount($rows, 'rows')->assertJsonCount($sessions, 'sessions');

            return $queries;
        };
        $small = $count(3, 4);
        $this->inTenant($f['organization'], function () use ($f): void {
            for ($i = 0; $i < 27; $i++) {
                $child = $f['owner']->children()->create(['first_name' => 'Дете', 'last_name' => (string) $i]);
                $this->visitEnrollment($child, $f['tuesday'], null, 'full_period');
            }
        });
        $this->assertSame($small, $count(30, 4));
        for ($i = 0; $i < 8; $i++) {
            $this->copySession($f, '2026-11-04T15:00:00Z');
        }
        $this->assertSame($small, $count(30, 12));
    }

    public function test_moved_extra_and_repeated_clock_sessions_belong_to_their_new_sofia_month(): void
    {
        $f = $this->rightsFixture();
        $early = $this->copySession($f, '2026-10-31T22:30:00Z');
        $late = $this->copySession($f, '2026-11-30T22:30:00Z');
        $clockA = $this->copySession($f, '2026-10-25T00:30:00Z');
        $clockB = $this->copySession($f, '2026-10-25T01:30:00Z');
        $this->inTenant($f['organization'], fn () => $early->update(['extra' => true, 'original_starts_at' => '2026-10-24T15:00:00Z']));
        $this->signInTo($f['owner'], $f['organization'])->getJson($this->monthUrl($f))->assertOk()->assertJsonCount(5, 'sessions')
            ->assertJsonPath('sessions.0.id', $early->id)->assertJsonPath('sessions.0.extra', true)->assertJsonPath('sessions.0.moved', true);
        $this->getJson($this->monthUrl($f, '2026-12'))->assertOk()->assertJsonCount(1, 'sessions')->assertJsonPath('sessions.0.id', $late->id);
        $this->getJson($this->monthUrl($f, '2026-10'))->assertOk()->assertJsonCount(2, 'sessions')->assertJsonPath('sessions.0.id', $clockA->id)->assertJsonPath('sessions.1.id', $clockB->id);
    }

    public function test_percent_rounds_half_up_and_child_card_matches_the_same_denominator(): void
    {
        $f = $this->rightsFixture();
        for ($i = 0; $i < 8; $i++) {
            $session = $this->copySession($f, '2026-11-04T15:00:00Z');
            $this->mark($f, $session, $f['enrollment'], $i === 0 ? 'present' : 'absent');
        }
        $row = collect($this->signInTo($f['owner'], $f['organization'])->getJson($this->monthUrl($f))->assertOk()->json('rows'))->firstWhere('kind', 'period');
        $this->assertSame([1, 7, 13], [$row['present'], $row['absent'], $row['percent']]);
        $this->getJson('/api/v1/enrollment/children/'.$f['enrollment']->child_id)->assertOk()->assertJsonPath('groups.0.attendance.percent', 13);
    }

    public function test_existence_and_access_precede_month_validation(): void
    {
        $f = $this->rightsFixture();
        $foreign = $this->rightsFixture();
        $this->signInTo($f['owner'], $f['organization'])->getJson('/api/v1/attendance/month?group='.$foreign['tuesday']->id.'&month=bad')->assertNotFound();
        $teacher = $this->memberOf($f['organization'], 'instructor');
        $this->signInTo($teacher, $f['organization'])->getJson($this->monthUrl($f, 'bad'))->assertForbidden();
        $this->getJson('/api/v1/attendance/month')->assertForbidden();
    }

    public function test_explicit_mark_grant_can_show_child_attendance_without_opening_the_month_table(): void
    {
        $f = $this->rightsFixture();
        $teacher = $this->memberOf($f['organization'], 'instructor');
        $person = $this->personFor($f['organization'], $teacher, true);
        $profile = $this->profileOf($f['organization'], $person);
        $this->inTenant($f['organization'], function () use ($f, $person, $profile): void {
            $f['tuesday']->assignments()->attach($profile->id, ['role' => 'lead', 'starts_on' => '2026-11-21']);
            $person->forceFill(['access_scope' => 'groups'])->save();
            StaffScopeGroup::query()->create(['staff_member_id' => $person->id, 'group_id' => $f['tuesday']->id, 'added_by' => $f['owner']->id, 'added_at' => now()]);
            StaffGrant::query()->create(['staff_member_id' => $person->id, 'ability' => 'attendance.mark', 'granted_by' => $f['owner']->id, 'granted_at' => now()]);
        });
        $this->signInTo($teacher, $f['organization'])->getJson('/api/v1/enrollment/children/'.$f['enrollment']->child_id)->assertOk()->assertJsonPath('groups.0.attendance.month', '2026-11');
        $this->getJson($this->monthUrl($f))->assertForbidden();
    }

    private function monthUrl(array $f, string $month = '2026-11'): string
    {
        return '/api/v1/attendance/month?group='.$f['tuesday']->id.'&month='.$month;
    }

    private function mark(array $f, Session $session, Enrollment $enrollment, string $status): void
    {
        $this->signInTo($f['owner'], $f['organization'])->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/attendance/sessions/'.$session->id.'/changes', ['action' => 'mark', 'reason' => 'Поправка', 'marks' => [['enrollment_id' => $enrollment->id, 'status' => $status, 'version' => 0]]])->assertOk();
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
