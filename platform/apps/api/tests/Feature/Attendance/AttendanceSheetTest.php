<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Services\AttendanceSheet;
use App\Domain\Catalog\Models\Session;
use App\Domain\Enrollment\Models\EnrollmentTransition;
use App\Domain\Enrollment\Services\SessionRoster;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\VisitOfferFixture;
use Tests\TestCase;

class AttendanceSheetTest extends TestCase
{
    use RefreshDatabase, VisitOfferFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->travelTo('2026-11-03T14:00:00Z');
    }

    public function test_roster_combines_marks_skips_pauses_and_visit_outcomes_without_exposing_private_fields(): void
    {
        [$org, , $group, $session, $offer, $child] = $this->visitFixture();
        $owner = $this->staff($org->id, 'organization_owner');
        $period = $this->visitEnrollment($child, $group, null, 'full_period');
        $warned = $this->visitEnrollment($child, $group, null, 'full_period');
        $this->visitEnrollment($child, $group, $session, 'single', $offer);
        $warned->sessionExceptions()->create(['session_id' => $session->id, 'kind' => 'excluded', 'created_by' => $child->owner_user_id]);
        $paused = $this->visitEnrollment($child, $group, null, 'full_period');
        $paused->pauses()->create(['starts_on' => '2026-11-03', 'ends_on' => '2026-11-09', 'created_by' => $owner->id, 'reason_category' => 'other', 'reason' => 'Пътуване']);
        $late = $this->visitEnrollment($child, $group, $session, 'trial', $offer, 'ended');
        EnrollmentTransition::query()->create(['application_id' => $late->application_id, 'enrollment_id' => $late->id, 'actor_user_id' => $owner->id,
            'from_status' => 'active', 'to_status' => 'ended', 'metadata' => ['outcome' => 'late_cancelled']]);
        $future = $this->visitEnrollment($child, $group, null, 'full_period', startsOn: '2026-11-04');
        $cancelled = $this->visitEnrollment($child, $group, $session, 'single', $offer, 'cancelled');
        $this->mark($org->id, $session->id, $period->id, $owner->id, 'present');
        $this->mark($org->id, $session->id, $paused->id, $owner->id, 'present');
        $response = $this->actingAs($owner)->withSession(['active_organization_id' => $org->id])
            ->getJson('/api/v1/attendance/sessions/'.$session->id)->assertOk();
        $response->assertJsonPath('counts', ['expected' => 4, 'present' => 1, 'absent' => 2, 'warned' => 2, 'unmarked' => 1,
            'trial' => 1, 'single' => 1, 'paused' => 1, 'present_paused' => 1]);
        $rows = collect($response->json('children'))->keyBy('enrollment_id');
        $this->assertSame('parent', $rows[$warned->id]['skip']['by']);
        $this->assertTrue($rows[$late->id]['late_cancelled']);
        $this->assertTrue($rows[$paused->id]['came_while_paused']);
        $this->assertSame('2026-11-09', $rows[$paused->id]['paused_until']);
        $this->assertFalse($rows[$paused->id]['expected']);
        $this->assertSame(0, $rows[$warned->id]['version']);
        $this->assertArrayNotHasKey($future->id, $rows->all());
        $this->assertArrayNotHasKey($cancelled->id, $rows->all());
        $this->assertSame($paused->id, $response->json('children.4.enrollment_id'));
        foreach ($rows as $row) {
            foreach (['phone', 'email', 'health', 'birth_date', 'amount_cents', 'parent_user_id'] as $private) {
                $this->assertArrayNotHasKey($private, $row);
            }
        }
        app(TenantContext::class)->select($org);
        $old = app(SessionRoster::class)->children($session);
        $this->assertCount(5, $old);
        $this->assertArrayNotHasKey('skip', $old[0]);
    }

    public function test_cancelled_and_not_held_sessions_hide_marks_and_counts(): void
    {
        [$org, , $group, $session, , $child] = $this->visitFixture();
        $owner = $this->staff($org->id, 'organization_owner');
        $enrollment = $this->visitEnrollment($child, $group, null, 'full_period');
        $this->mark($org->id, $session->id, $enrollment->id, $owner->id, 'present');
        DB::table('attendance_sessions')->insert(['id' => (string) Str::uuid(), 'organization_id' => $org->id, 'session_id' => $session->id,
            'version' => 1, 'not_held_at' => now(), 'not_held_by' => $owner->id, 'not_held_reason' => 'Затворена зала', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['active_organization_id' => $org->id])->getJson('/api/v1/attendance/sessions/'.$session->id)
            ->assertOk()->assertJsonCount(0, 'children')->assertJsonPath('counts.expected', 0)->assertJsonPath('counts.present', 0)
            ->assertJsonPath('abilities.mark', false)->assertJsonPath('session.not_held.reason', 'Затворена зала');
        app(TenantContext::class)->select($org);
        $session->update(['status' => 'cancelled']);
        $this->getJson('/api/v1/attendance/sessions/'.$session->id)->assertOk()->assertJsonCount(0, 'children');
    }

    public function test_query_count_is_constant_for_three_and_thirty_children_and_sessions(): void
    {
        [$org, , $group, $session, , $child] = $this->visitFixture();
        $owner = $this->staff($org->id, 'organization_owner');
        for ($i = 0; $i < 3; $i++) {
            $this->visitEnrollment($child->owner->children()->create(['first_name' => 'Дете '.$i, 'last_name' => 'Пример']), $group, null, 'full_period');
        }
        $measure = function () use ($owner, $org, $session): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $response = $this->actingAs($owner)->withSession(['active_organization_id' => $org->id])->getJson('/api/v1/attendance/sessions/'.$session->id)->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return [$count, $response->json('counts.expected')];
        };
        $measure(); // Warm the session/authentication once before comparing requests.
        [$three, $count] = $measure();
        $this->assertSame(3, $count);
        app(TenantContext::class)->select($org);
        for ($i = 3; $i < 30; $i++) {
            $this->visitEnrollment($child->owner->children()->create(['first_name' => 'Дете '.$i, 'last_name' => 'Пример']), $group, null, 'full_period');
        }
        [$thirty, $count] = $measure();
        $this->assertSame(30, $count);
        $this->assertSame($three, $thirty);
        app(TenantContext::class)->select($org);
        $sessions = collect([$session]);
        $batch = function ($sessions): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $counts = app(AttendanceSheet::class)->countsFor($sessions);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return [$queries, $counts];
        };
        for ($i = 1; $i < 3; $i++) {
            $sessions->push(tap($session->replicate()->fill(['starts_at' => $session->starts_at->addDays($i), 'ends_at' => $session->ends_at->addDays($i)]), fn ($copy) => $copy->save()));
        }
        [$few] = $batch($sessions);
        for ($i = 3; $i < 30; $i++) {
            $sessions->push(tap($session->replicate()->fill(['starts_at' => $session->starts_at->addDays($i), 'ends_at' => $session->ends_at->addDays($i)]), fn ($copy) => $copy->save()));
        }
        [$many] = $batch($sessions);
        $this->assertSame($few, $many);
    }

    public function test_a_present_mark_overrides_warning_and_clearing_it_restores_derived_warning(): void
    {
        [$org, , $group, $session, , $child] = $this->visitFixture();
        $owner = $this->staff($org->id, 'organization_owner');
        $enrollment = $this->visitEnrollment($child, $group, null, 'full_period');
        $enrollment->sessionExceptions()->create(['session_id' => $session->id, 'kind' => 'excluded', 'created_by' => $owner->id]);
        $this->mark($org->id, $session->id, $enrollment->id, $owner->id, 'present');
        $uri = '/api/v1/attendance/sessions/'.$session->id;
        $this->actingAs($owner)->withSession(['active_organization_id' => $org->id])->getJson($uri)->assertOk()
            ->assertJsonPath('children.0.status', 'present')->assertJsonPath('children.0.warned', false)->assertJsonPath('counts.warned', 0);
        DB::table('attendance_marks')->where('enrollment_id', $enrollment->id)->update(['status' => null, 'version' => 2]);
        $this->getJson($uri)->assertOk()->assertJsonPath('children.0.status', 'absent')->assertJsonPath('children.0.warned', true)
            ->assertJsonPath('children.0.version', 2)->assertJsonPath('counts.absent', 1);
        app(TenantContext::class)->select($org);
        $this->assertArrayHasKey($session->id, app(AttendanceSheet::class)->countsFor(collect([$session])));
        $this->assertSame([], app(AttendanceSheet::class)->notHeld(collect([$session])));
    }

    public function test_old_attended_and_no_show_visit_outcomes_are_not_attendance_marks_and_ended_periods_are_omitted(): void
    {
        [$org, , $group, $session, $offer, $child] = $this->visitFixture();
        $owner = $this->staff($org->id, 'organization_owner');
        foreach (['attended', 'no_show'] as $outcome) {
            $visit = $this->visitEnrollment($child, $group, $session, 'trial', $offer, 'ended');
            EnrollmentTransition::query()->create(['application_id' => $visit->application_id, 'enrollment_id' => $visit->id, 'actor_user_id' => $owner->id,
                'from_status' => 'active', 'to_status' => 'ended', 'metadata' => ['outcome' => $outcome]]);
        }
        $ended = $this->visitEnrollment($child, $group, null, 'full_period', status: 'ended', startsOn: '2026-10-01');
        $ended->update(['ends_on' => '2026-11-02']);
        $this->actingAs($owner)->withSession(['active_organization_id' => $org->id])->getJson('/api/v1/attendance/sessions/'.$session->id)->assertOk()
            ->assertJsonPath('counts.expected', 2)->assertJsonPath('counts.unmarked', 2)->assertJsonPath('counts.present', 0)->assertJsonPath('counts.absent', 0);
    }

    private function staff(string $organization, string $role): User
    {
        $user = User::factory()->create();
        OrganizationMembership::query()->create(['organization_id' => $organization, 'user_id' => $user->id, 'role' => $role, 'status' => 'active']);

        return $user;
    }

    private function mark(string $org, string $session, string $enrollment, string $actor, ?string $status): void
    {
        DB::table('attendance_marks')->insert(['id' => (string) Str::uuid(), 'organization_id' => $org, 'session_id' => $session, 'enrollment_id' => $enrollment,
            'status' => $status, 'version' => 1, 'marked_by' => $actor, 'marked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
