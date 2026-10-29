<?php

namespace Tests\Feature\Enrollment;

use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\PriceOption;
use App\Domain\Catalog\Models\Session;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Enrollment\Models\EnrollmentPause;
use App\Domain\Enrollment\Services\EnrollmentCapacity;
use App\Domain\Enrollment\Services\SessionApplicability;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Tests\TestCase;

/** Б2: changes to an enrollment made by the organization's team (docs/product/staff-enrollment.md, т. 9). */
class StaffEnrollmentChangesTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = 'Семейството помоли за промяна.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->travelTo('2026-10-28 10:00:00');
    }

    public function test_transfer_ends_the_old_enrollment_the_day_before_and_links_the_history(): void
    {
        $f = $this->fixture();
        $coordinator = $this->member($f['organization'], 'coordinator');

        $response = $this->actAs($coordinator, $f['organization'])
            ->postJson("/api/v1/enrollment/enrollments/{$f['enrollment']->id}/transfer", [
                'target_group_id' => $f['thursday']->id,
                'price_option_id' => $f['thursdayPrice']->id,
                'effective_date' => '2026-11-12',
                'reason' => self::REASON,
                'version' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('enrollment.group_id', $f['thursday']->id)
            ->assertJsonPath('enrollment.starts_on', '2026-11-12')
            ->assertJsonPath('enrollment.status', 'active');

        $source = $this->withTenant($f['organization'], fn () => Enrollment::query()->findOrFail($f['enrollment']->id));
        $this->assertSame('ended', $source->status->value);
        $this->assertSame('2026-11-11', $source->ends_on?->format('Y-m-d'));
        $this->assertSame(2, $source->version);

        $target = $this->withTenant($f['organization'], fn () => Enrollment::query()->with('application')->findOrFail($response->json('enrollment.id')));
        $this->assertSame($source->id, $target->transferred_from_id);
        $this->assertSame('staff', $target->application->source->value);
        $this->assertSame('approved', $target->application->status->value);
        $this->assertSame(5000, $target->application->amount_cents_snapshot);
        $this->assertSame($f['thursdayPrice']->id, $target->price_option_id);

        // No session is counted in both groups.
        $this->assertSame(['2026-10-27', '2026-11-03', '2026-11-10'], $this->sessionDates($f['organization'], $source));
        $this->assertSame(['2026-11-12', '2026-11-19', '2026-11-26'], $this->sessionDates($f['organization'], $target));

        $this->assertDatabaseHas('enrollment_transitions', ['enrollment_id' => $target->id, 'actor_user_id' => $coordinator->id, 'to_status' => 'active']);
        $this->assertDatabaseHas('enrollment_events', ['enrollment_id' => $target->id, 'type' => 'enrollment.transferred']);
        $this->assertAuditWithoutPersonalData('enrollment.transferred', $source->id);
    }

    public function test_transfer_before_the_start_cancels_the_old_enrollment(): void
    {
        $f = $this->fixture(startsOn: '2026-11-02');
        $owner = $this->member($f['organization'], 'organization_owner');

        $this->actAs($owner, $f['organization'])
            ->postJson("/api/v1/enrollment/enrollments/{$f['enrollment']->id}/transfer", $this->transferPayload($f, '2026-11-02'))
            ->assertOk();

        $source = $this->withTenant($f['organization'], fn () => Enrollment::query()->findOrFail($f['enrollment']->id));
        $this->assertSame('cancelled', $source->status->value);
        $this->assertSame([], $this->sessionDates($f['organization'], $source));
    }

    public function test_transfer_is_refused_when_it_would_break_a_rule(): void
    {
        $f = $this->fixture(thursdayCapacity: 1);
        $owner = $this->member($f['organization'], 'organization_owner');
        $url = "/api/v1/enrollment/enrollments/{$f['enrollment']->id}/transfer";
        $this->actAs($owner, $f['organization']);

        $this->postJson($url, [...$this->transferPayload($f), 'version' => 7])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->postJson($url, $this->transferPayload($f, '2026-10-27'))->assertUnprocessable()->assertJsonValidationErrors('effective_date');
        $this->postJson($url, [...$this->transferPayload($f), 'reason' => ' '])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson($url, [...$this->transferPayload($f), 'target_group_id' => $f['tuesday']->id])->assertUnprocessable()->assertJsonValidationErrors('target_group_id');
        $this->postJson($url, [...$this->transferPayload($f), 'target_group_id' => $f['otherActivityGroup']->id])->assertUnprocessable()->assertJsonValidationErrors('target_group_id');
        $this->postJson($url, [...$this->transferPayload($f), 'price_option_id' => $f['tuesdayPrice']->id])->assertUnprocessable()->assertJsonValidationErrors('price_option_id');

        $this->enrollAnotherChild($f, $f['thursday'], $f['thursdayPrice']);
        $this->postJson($url, $this->transferPayload($f))->assertUnprocessable()->assertJsonValidationErrors('capacity');

        $source = $this->withTenant($f['organization'], fn () => Enrollment::query()->findOrFail($f['enrollment']->id));
        $this->assertSame('active', $source->status->value);
        $this->assertSame(1, $source->version);
        $this->assertDatabaseCount('enrollment_pauses', 0);
    }

    public function test_an_enrollment_is_transferred_only_once(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['organization'], 'organization_owner');
        $url = "/api/v1/enrollment/enrollments/{$f['enrollment']->id}/transfer";
        $this->actAs($owner, $f['organization']);

        $this->postJson($url, $this->transferPayload($f))->assertOk();
        $this->postJson($url, [...$this->transferPayload($f), 'version' => 2])->assertUnprocessable()->assertJsonValidationErrors('enrollment');
        $this->assertSame(2, $this->withTenant($f['organization'], fn () => Enrollment::query()->count()));
    }

    public function test_transfer_options_list_the_other_groups_of_the_activity_with_places_and_prices(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['organization'], 'organization_owner');

        $this->actAs($owner, $f['organization'])
            ->getJson("/api/v1/enrollment/enrollments/{$f['enrollment']->id}/transfer-options")
            ->assertOk()
            ->assertJsonCount(1, 'groups')
            ->assertJsonPath('groups.0.id', $f['thursday']->id)
            ->assertJsonPath('groups.0.remaining', 3)
            ->assertJsonPath('groups.0.prices.0.id', $f['thursdayPrice']->id)
            ->assertJsonPath('groups.0.prices.0.amount_cents', 5000);
    }

    public function test_transfer_options_show_from_which_day_a_move_finds_a_place(): void
    {
        $f = $this->fixture(thursdayCapacity: 2);
        // One child stays, one leaves after 12.11, and a one-off visit fills 19.11.
        $this->enrollAnotherChild($f, $f['thursday'], $f['thursdayPrice']);
        $this->enrollAnotherChild($f, $f['thursday'], $f['thursdayPrice'], endsOn: '2026-11-12');
        $nov19 = $this->withTenant($f['organization'], fn (): Session => Session::query()->where('group_id', $f['thursday']->id)->whereDate('starts_at', '2026-11-19')->firstOrFail());
        $this->enrollAnotherChild($f, $f['thursday'], $f['thursdayPrice'], '2026-11-19', $nov19);

        $starts = $this->actAs($this->member($f['organization'], 'coordinator'), $f['organization'])
            ->getJson("/api/v1/enrollment/enrollments/{$f['enrollment']->id}/transfer-options")
            ->assertOk()
            ->assertJsonPath('groups.0.id', $f['thursday']->id)
            ->assertJsonPath('groups.0.schedule', null)
            ->assertJsonPath('groups.0.waitlist_count', 0)
            ->json('groups.0.starts');

        $this->assertSame(['2026-11-05', '2026-11-12', '2026-11-19', '2026-11-26'], array_column($starts, 'date'));
        // Full while the leaving child holds the place, then full on the day of the visit, free from 26.11.
        $this->assertSame([0, 0, 0, 1], array_column($starts, 'remaining'));
        $this->assertSame('2026-11-26T14:00:00+00:00', $starts[3]['starts_at']);
        // The same answer as the check the move itself makes for that day.
        $this->withTenant($f['organization'], function () use ($f, $starts): void {
            $capacity = app(EnrollmentCapacity::class);
            foreach ($starts as $start) {
                $day = CarbonImmutable::parse($start['date'], 'Europe/Sofia');
                $this->assertSame($capacity->remainingForGroup($f['thursday']->refresh(), $day), $start['remaining'], $start['date']);
            }
        });
        // A move from 26.11 goes through; from 19.11 it is refused.
        $url = "/api/v1/enrollment/enrollments/{$f['enrollment']->id}/transfer";
        $this->postJson($url, $this->transferPayload($f, '2026-11-19'))->assertUnprocessable()->assertJsonValidationErrors('capacity');
        $this->postJson($url, $this->transferPayload($f, '2026-11-26'))->assertOk();
    }

    public function test_pause_skips_sessions_keeps_the_place_and_can_end_early(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['organization'], 'organization_owner');
        $this->actAs($owner, $f['organization']);
        $base = "/api/v1/enrollment/enrollments/{$f['enrollment']->id}/pauses";

        $pauseId = $this->postJson($base, ['starts_on' => '2026-11-09', 'ends_on' => '2026-11-20', 'reason_category' => 'health', 'note' => 'Болест', 'version' => 1])
            ->assertCreated()
            ->assertJsonPath('pause.starts_on', '2026-11-09')
            ->assertJsonPath('pause.ends_on', '2026-11-20')
            ->json('pause.id');

        $enrollment = $this->withTenant($f['organization'], fn () => Enrollment::query()->findOrFail($f['enrollment']->id));
        $this->assertSame('active', $enrollment->status->value);
        $this->assertSame(2, $enrollment->version);
        $this->assertSame(['2026-10-27', '2026-11-03', '2026-11-24'], $this->sessionDates($f['organization'], $enrollment));
        $this->withTenant($f['organization'], function () use ($f): void {
            // The child keeps the place in the group; the paused session is free for a one-off visit.
            $this->assertSame(9, app(EnrollmentCapacity::class)->remainingForGroup($f['tuesday']->refresh(), CarbonImmutable::now()));
            $session = Session::query()->where('group_id', $f['tuesday']->id)->whereDate('starts_at', '2026-11-10')->firstOrFail();
            $this->assertSame(10, app(EnrollmentCapacity::class)->remainingForSession($session));
        });

        $this->postJson($base, ['starts_on' => '2026-11-15', 'ends_on' => '2026-11-30', 'reason_category' => 'holiday', 'version' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('starts_on');
        $this->postJson($base, ['starts_on' => '2026-10-27', 'ends_on' => '2026-10-30', 'reason_category' => 'health', 'version' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('starts_on');
        $this->postJson($base, ['starts_on' => '2026-11-25', 'ends_on' => '2027-06-30', 'reason_category' => 'other', 'version' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('ends_on');

        $this->postJson("{$base}/{$pauseId}/end", ['resume_on' => '2026-11-16', 'version' => 2])
            ->assertOk()->assertJsonPath('pause.ends_on', '2026-11-15');
        $this->assertSame(['2026-10-27', '2026-11-03', '2026-11-17', '2026-11-24'], $this->sessionDates($f['organization'], $enrollment));

        $laterId = $this->postJson($base, ['starts_on' => '2026-11-23', 'ends_on' => '2026-11-25', 'reason_category' => 'holiday', 'version' => 3])
            ->assertCreated()->json('pause.id');
        $this->postJson("{$base}/{$laterId}/end", ['resume_on' => '2026-11-23', 'version' => 4])
            ->assertOk()->assertJsonPath('pause.cancelled', true);
        $this->assertSame(['2026-10-27', '2026-11-03', '2026-11-17', '2026-11-24'], $this->sessionDates($f['organization'], $enrollment));

        $this->assertDatabaseHas('enrollment_events', ['enrollment_id' => $enrollment->id, 'type' => 'enrollment.paused']);
        $this->assertDatabaseHas('enrollment_events', ['enrollment_id' => $enrollment->id, 'type' => 'enrollment.pause_ended']);
        $this->assertAuditWithoutPersonalData('enrollment.paused', $enrollment->id, ['Болест']);
        $this->assertDatabaseMissing('enrollment_transitions', ['public_reason' => 'Болест']);
        foreach (DB::table('enrollment_transitions')->where('enrollment_id', $enrollment->id)->pluck('metadata') as $metadata) {
            $this->assertStringNotContainsString('Болест', $this->readable((string) $metadata));
        }
    }

    public function test_end_sets_the_last_day_or_cancels_an_enrollment_that_has_not_started(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['organization'], 'organization_owner');
        $this->actAs($owner, $f['organization']);
        $url = "/api/v1/enrollment/enrollments/{$f['enrollment']->id}/end";

        $this->postJson($url, ['last_day' => '2026-10-27', 'reason' => self::REASON, 'version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('last_day');
        $this->postJson($url, ['last_day' => '2026-11-15', 'reason' => self::REASON, 'version' => 1])
            ->assertOk()->assertJsonPath('enrollment.status', 'ended')->assertJsonPath('enrollment.ends_on', '2026-11-15');
        $this->postJson($url, ['last_day' => '2026-11-20', 'reason' => self::REASON, 'version' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('enrollment');

        $later = $this->fixture(startsOn: '2026-11-16');
        $this->actAs($this->member($later['organization'], 'organization_owner'), $later['organization'])
            ->postJson("/api/v1/enrollment/enrollments/{$later['enrollment']->id}/end", ['last_day' => '2026-11-01', 'reason' => self::REASON, 'version' => 1])
            ->assertOk()->assertJsonPath('enrollment.status', 'cancelled');

        $this->assertAuditWithoutPersonalData('enrollment.ended', $f['enrollment']->id);
    }

    public function test_a_future_session_can_be_skipped_and_restored(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['organization'], 'organization_owner');
        $this->actAs($owner, $f['organization']);
        $base = "/api/v1/enrollment/enrollments/{$f['enrollment']->id}/skipped-sessions";
        [$past, $nov10, $thursdaySession] = $this->withTenant($f['organization'], fn () => [
            Session::query()->where('group_id', $f['tuesday']->id)->whereDate('starts_at', '2026-10-27')->firstOrFail(),
            Session::query()->where('group_id', $f['tuesday']->id)->whereDate('starts_at', '2026-11-10')->firstOrFail(),
            Session::query()->where('group_id', $f['thursday']->id)->firstOrFail(),
        ]);

        $this->postJson($base, ['session_id' => $nov10->id, 'reason' => 'Състезание', 'version' => 1])->assertOk();
        $this->postJson($base, ['session_id' => $nov10->id, 'reason' => 'Състезание', 'version' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('session_id');
        $this->postJson($base, ['session_id' => $past->id, 'reason' => 'Късно', 'version' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('session_id');
        $this->postJson($base, ['session_id' => $thursdaySession->id, 'reason' => 'Друга група', 'version' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('session_id');

        $enrollment = $this->withTenant($f['organization'], fn () => Enrollment::query()->findOrFail($f['enrollment']->id));
        $this->assertSame(['2026-10-27', '2026-11-03', '2026-11-17', '2026-11-24'], $this->sessionDates($f['organization'], $enrollment));

        $this->deleteJson("{$base}/{$nov10->id}?version=2")->assertOk();
        $this->assertSame(['2026-10-27', '2026-11-03', '2026-11-10', '2026-11-17', '2026-11-24'], $this->sessionDates($f['organization'], $enrollment));
        $this->deleteJson("{$base}/{$nov10->id}?version=3")->assertUnprocessable()->assertJsonValidationErrors('session_id');

        $this->assertDatabaseHas('enrollment_events', ['enrollment_id' => $enrollment->id, 'type' => 'enrollment.session_restored']);
        $this->assertAuditWithoutPersonalData('enrollment.session_excluded', $enrollment->id, ['Състезание']);
    }

    public function test_roster_and_group_list_show_what_the_team_needs(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['organization'], 'organization_owner');
        $this->actAs($owner, $f['organization']);
        $enrollmentUrl = "/api/v1/enrollment/enrollments/{$f['enrollment']->id}";
        $nov24 = $this->withTenant($f['organization'], fn () => Session::query()->where('group_id', $f['tuesday']->id)->whereDate('starts_at', '2026-11-24')->firstOrFail());

        $this->postJson("{$enrollmentUrl}/pauses", ['starts_on' => '2026-11-09', 'ends_on' => '2026-11-11', 'reason_category' => 'health', 'note' => 'Болест', 'version' => 1])->assertCreated();
        $this->postJson("{$enrollmentUrl}/skipped-sessions", ['session_id' => $nov24->id, 'reason' => 'Състезание', 'version' => 2])->assertOk();
        $this->postJson("{$enrollmentUrl}/end", ['last_day' => '2026-11-30', 'reason' => self::REASON, 'version' => 3])->assertOk();

        $roster = $this->getJson("/api/v1/enrollment/groups/{$f['tuesday']->id}/roster")
            ->assertOk()
            ->assertJsonCount(1, 'enrollments')
            ->assertJsonPath('enrollments.0.status', 'ended')
            ->assertJsonPath('enrollments.0.ends_on', '2026-11-30')
            ->assertJsonPath('enrollments.0.paused_today', false)
            ->assertJsonPath('enrollments.0.pauses.0.starts_on', '2026-11-09')
            ->assertJsonPath('enrollments.0.pauses.0.reason_category', 'health')
            ->assertJsonPath('enrollments.0.skipped_sessions.0.session_id', $nov24->id)
            ->assertJsonPath('enrollments.0.version', 4)
            ->assertJsonPath('group.enrolled_for_period', 1)
            ->json();
        $this->assertCount(4, $roster['upcoming_sessions']);
        $this->assertStringNotContainsString('Болест', json_encode($roster, JSON_UNESCAPED_UNICODE) ?: '');
        $this->assertStringNotContainsString(self::REASON, json_encode($roster, JSON_UNESCAPED_UNICODE) ?: '');

        $this->getJson('/api/v1/enrollment/groups')
            ->assertOk()
            ->assertJsonCount(3, 'groups')
            ->assertJsonFragment(['id' => $f['tuesday']->id, 'activity_title' => 'Шах', 'enrolled_for_period' => 1, 'waitlist_count' => 0]);
    }

    public function test_a_change_never_touches_a_session_that_has_started(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['organization'], 'organization_owner');
        $this->travelTo('2026-11-03 14:30:00');
        $this->actAs($owner, $f['organization']);
        $base = "/api/v1/enrollment/enrollments/{$f['enrollment']->id}";

        $this->postJson("{$base}/transfer", $this->transferPayload($f, '2026-11-03'))
            ->assertUnprocessable()->assertJsonValidationErrors('effective_date');
        $this->postJson("{$base}/pauses", ['starts_on' => '2026-11-03', 'ends_on' => '2026-11-05', 'reason_category' => 'health', 'version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('starts_on');
        $this->postJson("{$base}/transfer", $this->transferPayload($f, '2026-11-04'))->assertOk();
        $this->assertSame(['2026-10-27', '2026-11-03'], $this->sessionDates($f['organization'], $f['enrollment']->id));
    }

    public function test_places_are_held_by_scheduled_ends_and_by_future_transfers(): void
    {
        $f = $this->fixture(thursdayCapacity: 1);
        $owner = $this->member($f['organization'], 'organization_owner');
        $this->actAs($owner, $f['organization']);
        $this->postJson("/api/v1/enrollment/enrollments/{$f['enrollment']->id}/transfer", $this->transferPayload($f, '2026-11-19'))->assertOk();

        $this->withTenant($f['organization'], function () use ($f): void {
            $capacity = app(EnrollmentCapacity::class);
            // The transfer from 19.11 already holds the only place in the Thursday group.
            $this->assertSame(1, $capacity->placesTaken($f['thursday']->refresh(), '2026-10-28'));
            $this->assertSame(0, $capacity->remainingForGroup($f['thursday']));
            // Until the last day (18.11) the child still holds the place in the Tuesday group.
            $this->assertSame(1, $capacity->placesTaken($f['tuesday']->refresh(), '2026-11-18'));
            $this->assertSame(0, $capacity->placesTaken($f['tuesday'], '2026-11-19'));
        });
    }

    public function test_sessions_that_come_back_need_a_free_place(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['organization'], 'organization_owner');
        $this->actAs($owner, $f['organization']);
        $base = "/api/v1/enrollment/enrollments/{$f['enrollment']->id}";
        $nov10 = $this->withTenant($f['organization'], function () use ($f): Session {
            $session = Session::query()->where('group_id', $f['tuesday']->id)->whereDate('starts_at', '2026-11-10')->firstOrFail();
            $session->update(['capacity' => 1]);

            return $session;
        });

        $pauseId = $this->postJson("{$base}/pauses", ['starts_on' => '2026-11-09', 'ends_on' => '2026-11-11', 'reason_category' => 'holiday', 'version' => 1])
            ->assertCreated()->json('pause.id');
        // The freed place goes to a one-off visit.
        $this->enrollAnotherChild($f, $f['tuesday'], $f['tuesdayPrice'], '2026-11-10', $nov10);

        $this->postJson("{$base}/pauses/{$pauseId}/end", ['resume_on' => '2026-11-09', 'version' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('capacity');
        $this->assertSame(['2026-10-27', '2026-11-03', '2026-11-17', '2026-11-24'], $this->sessionDates($f['organization'], $f['enrollment']->id));
    }

    public function test_a_scheduled_end_can_be_undone_while_the_place_is_free(): void
    {
        $f = $this->fixture(tuesdayCapacity: 1);
        $owner = $this->member($f['organization'], 'organization_owner');
        $this->actAs($owner, $f['organization']);
        $base = "/api/v1/enrollment/enrollments/{$f['enrollment']->id}";

        $this->postJson("{$base}/end", ['last_day' => '2026-11-15', 'reason' => self::REASON, 'version' => 1])->assertOk();
        $this->postJson("{$base}/reopen", ['version' => 2])
            ->assertOk()->assertJsonPath('enrollment.status', 'active')->assertJsonPath('enrollment.ends_on', null);

        $this->postJson("{$base}/end", ['last_day' => '2026-11-15', 'reason' => self::REASON, 'version' => 3])->assertOk();
        $this->enrollAnotherChild($f, $f['tuesday'], $f['tuesdayPrice'], '2026-11-16');
        $this->postJson("{$base}/reopen", ['version' => 4])->assertUnprocessable()->assertJsonValidationErrors('capacity');
        $this->assertAuditWithoutPersonalData('enrollment.reopened', $f['enrollment']->id);
    }

    public function test_the_parent_goes_through_the_same_rules(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['organization'], 'organization_owner');
        $this->actAs($owner, $f['organization'])
            ->postJson("/api/v1/enrollment/enrollments/{$f['enrollment']->id}/transfer", $this->transferPayload($f))->assertOk();

        // The old enrollment ended on 11.11; the parent cannot stretch it into the new group's dates.
        $this->actingAs($f['parent'])
            ->postJson("/api/v1/parent/enrollments/{$f['enrollment']->id}/end-from", ['effective_date' => '2026-12-20', 'reason' => 'Удължаване'])
            ->assertUnprocessable();
        $source = $this->withTenant($f['organization'], fn () => Enrollment::query()->findOrFail($f['enrollment']->id));
        $this->assertSame('ended', $source->status->value);
        $this->assertSame('2026-11-11', $source->ends_on?->format('Y-m-d'));
    }

    public function test_every_change_refuses_a_stale_version(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['organization'], 'organization_owner');
        $this->actAs($owner, $f['organization']);
        $base = "/api/v1/enrollment/enrollments/{$f['enrollment']->id}";
        $nov10 = $this->withTenant($f['organization'], fn () => Session::query()->where('group_id', $f['tuesday']->id)->whereDate('starts_at', '2026-11-10')->firstOrFail());
        $pauseId = $this->postJson("{$base}/pauses", ['starts_on' => '2026-11-16', 'ends_on' => '2026-11-18', 'reason_category' => 'other', 'version' => 1])->assertCreated()->json('pause.id');
        $this->postJson("{$base}/skipped-sessions", ['session_id' => $nov10->id, 'reason' => 'Състезание', 'version' => 2])->assertOk();

        foreach ([
            ['post', "{$base}/pauses", ['starts_on' => '2026-11-23', 'ends_on' => '2026-11-24', 'reason_category' => 'other', 'version' => 1]],
            ['post', "{$base}/pauses/{$pauseId}/end", ['resume_on' => '2026-11-16', 'version' => 1]],
            ['post', "{$base}/end", ['last_day' => '2026-11-30', 'reason' => self::REASON, 'version' => 1]],
            ['post', "{$base}/reopen", ['version' => 1]],
            ['post', "{$base}/skipped-sessions", ['session_id' => $nov10->id, 'reason' => 'Състезание', 'version' => 1]],
            ['delete', "{$base}/skipped-sessions/{$nov10->id}?version=1", []],
            ['post', "{$base}/transfer", $this->transferPayload($f)],
        ] as [$method, $url, $body]) {
            $this->json(strtoupper($method), $url, $body)->assertUnprocessable()->assertJsonValidationErrors('version');
        }
        $this->assertSame(3, $this->withTenant($f['organization'], fn () => Enrollment::query()->findOrFail($f['enrollment']->id)->version));
    }

    public function test_a_transfer_is_undone_by_moving_back_after_the_last_day(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['organization'], 'organization_owner');
        $this->actAs($owner, $f['organization']);
        $movedId = $this->postJson("/api/v1/enrollment/enrollments/{$f['enrollment']->id}/transfer", $this->transferPayload($f))->assertOk()->json('enrollment.id');
        $back = "/api/v1/enrollment/enrollments/{$movedId}/transfer";
        $payload = ['target_group_id' => $f['tuesday']->id, 'price_option_id' => $f['tuesdayPrice']->id, 'reason' => self::REASON, 'version' => 1];

        // Before 12.11 the child is still in the Tuesday group.
        $this->postJson($back, [...$payload, 'effective_date' => '2026-11-05'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.target_group_id.0', 'Детето е в тази група до 11.11.2026. Изберете дата след това.');
        $returned = $this->postJson($back, [...$payload, 'effective_date' => '2026-11-12'])->assertOk()->json('enrollment');

        $moved = $this->withTenant($f['organization'], fn () => Enrollment::query()->findOrFail($movedId));
        $this->assertSame('cancelled', $moved->status->value);
        $this->assertSame($f['tuesday']->id, $returned['group_id']);
        $this->assertSame([], $this->sessionDates($f['organization'], $movedId));
        // Tuesday sessions continue without a gap: the old enrollment until 11.11, the new one from 12.11.
        $this->assertSame(['2026-11-17', '2026-11-24'], $this->sessionDates($f['organization'], $returned['id']));
        $this->assertSame(['2026-10-27', '2026-11-03', '2026-11-10'], $this->sessionDates($f['organization'], $f['enrollment']->id));

        // The first enrollment cannot be reopened as well: the child already continues in the group.
        $this->postJson("/api/v1/enrollment/enrollments/{$f['enrollment']->id}/reopen", ['version' => 2])
            ->assertUnprocessable()->assertJsonPath('errors.enrollment.0', 'Детето вече продължава в групата с ново записване.');
    }

    public function test_reopen_follows_a_chain_of_moves(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['organization'], 'organization_owner');
        $this->actAs($owner, $f['organization']);
        $third = $this->withTenant($f['organization'], fn () => $f['thursday']->offering->groups()->create([
            'name' => 'Събота', 'capacity_max' => 10, 'status' => 'published', 'waitlist_enabled' => true, 'enrollment_approval_mode' => 'manual',
        ]));
        $thirdPrice = $this->withTenant($f['organization'], fn () => $f['thursday']->offering->priceOptions()->create([
            'group_id' => $third->id, 'enrollment_mode' => 'monthly', 'unit' => 'month', 'amount_cents' => 4000, 'currency' => 'EUR', 'is_available' => true,
        ]));
        // A (Tuesday) → B (Thursday, from 12.11) → C (Saturday, from 12.11): B is cancelled before it starts.
        $movedId = $this->postJson("/api/v1/enrollment/enrollments/{$f['enrollment']->id}/transfer", $this->transferPayload($f))->assertOk()->json('enrollment.id');
        $this->postJson("/api/v1/enrollment/enrollments/{$movedId}/transfer", [
            'target_group_id' => $third->id, 'price_option_id' => $thirdPrice->id, 'effective_date' => '2026-11-12', 'reason' => self::REASON, 'version' => 1,
        ])->assertOk();

        $this->postJson("/api/v1/enrollment/enrollments/{$f['enrollment']->id}/reopen", ['version' => 2])
            ->assertUnprocessable()->assertJsonPath('errors.enrollment.0', 'Детето е преместено в друга група. За да се върне, преместете го обратно.');
    }

    public function test_a_cancelled_move_lets_the_old_enrollment_continue(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['organization'], 'organization_owner');
        $this->actAs($owner, $f['organization']);
        $source = "/api/v1/enrollment/enrollments/{$f['enrollment']->id}";
        $movedId = $this->postJson("{$source}/transfer", $this->transferPayload($f))->assertOk()->json('enrollment.id');
        $this->postJson("/api/v1/enrollment/enrollments/{$movedId}/end", ['last_day' => '2026-11-01', 'reason' => self::REASON, 'version' => 1])
            ->assertOk()->assertJsonPath('enrollment.status', 'cancelled');

        $this->postJson("{$source}/reopen", ['version' => 2])->assertOk()->assertJsonPath('enrollment.status', 'active');
        $this->getJson("/api/v1/enrollment/groups/{$f['tuesday']->id}/roster")->assertOk()->assertJsonPath('enrollments.0.moves_to', null);
        $this->postJson("{$source}/transfer", [...$this->transferPayload($f, '2026-11-19'), 'version' => 3])->assertOk();
    }

    public function test_reopening_after_a_cancelled_move_restores_the_original_end(): void
    {
        $f = $this->fixture();
        $this->withTenant($f['organization'], fn () => Enrollment::query()->whereKey($f['enrollment']->id)->update(['ends_on' => '2026-12-31']));
        $owner = $this->member($f['organization'], 'organization_owner');
        $this->actAs($owner, $f['organization']);
        $source = "/api/v1/enrollment/enrollments/{$f['enrollment']->id}";
        $movedId = $this->postJson("{$source}/transfer", $this->transferPayload($f))->assertOk()->json('enrollment.id');
        $this->postJson("/api/v1/enrollment/enrollments/{$movedId}/end", ['last_day' => '2026-11-01', 'reason' => self::REASON, 'version' => 1])->assertOk();
        // A pause after the end adds a later "ended → ended" step to the history.
        $this->travel(1)->minutes();
        $this->postJson("{$source}/pauses", ['starts_on' => '2026-11-02', 'ends_on' => '2026-11-03', 'reason_category' => 'family', 'version' => 2])->assertCreated();

        $this->postJson("{$source}/reopen", ['version' => 3])
            ->assertOk()->assertJsonPath('enrollment.status', 'active')->assertJsonPath('enrollment.ends_on', '2026-12-31');
    }

    public function test_pauses_after_the_new_last_day_are_shortened_or_cancelled(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['organization'], 'organization_owner');
        $this->actAs($owner, $f['organization']);
        $base = "/api/v1/enrollment/enrollments/{$f['enrollment']->id}";
        $overlapping = $this->postJson("{$base}/pauses", ['starts_on' => '2026-11-09', 'ends_on' => '2026-11-13', 'reason_category' => 'family', 'version' => 1])->assertCreated()->json('pause.id');
        $later = $this->postJson("{$base}/pauses", ['starts_on' => '2026-11-16', 'ends_on' => '2026-11-20', 'reason_category' => 'holiday', 'version' => 2])->assertCreated()->json('pause.id');

        $this->postJson("{$base}/transfer", [...$this->transferPayload($f), 'version' => 3])->assertOk();

        $this->withTenant($f['organization'], function () use ($overlapping, $later): void {
            $this->assertSame('2026-11-11', EnrollmentPause::query()->findOrFail($overlapping)->ends_on->format('Y-m-d'));
            $this->assertNotNull(EnrollmentPause::query()->findOrFail($later)->cancelled_at);
        });
    }

    public function test_a_restored_session_needs_a_free_place_and_the_same_child_is_not_enrolled_twice(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['organization'], 'organization_owner');
        $this->actAs($owner, $f['organization']);
        $base = "/api/v1/enrollment/enrollments/{$f['enrollment']->id}";
        $nov10 = $this->withTenant($f['organization'], function () use ($f): Session {
            $session = Session::query()->where('group_id', $f['tuesday']->id)->whereDate('starts_at', '2026-11-10')->firstOrFail();
            $session->update(['capacity' => 1]);

            return $session;
        });
        $this->postJson("{$base}/skipped-sessions", ['session_id' => $nov10->id, 'reason' => 'Състезание', 'version' => 1])->assertOk();
        $this->enrollAnotherChild($f, $f['tuesday'], $f['tuesdayPrice'], '2026-11-10', $nov10);
        $this->deleteJson("{$base}/skipped-sessions/{$nov10->id}?version=2")->assertUnprocessable()->assertJsonValidationErrors('capacity');

        // The same child already has a place in the Thursday group (even without generated sessions).
        $this->withTenant($f['organization'], function () use ($f): void {
            Session::query()->where('group_id', $f['thursday']->id)->delete();
            $application = EnrollmentApplication::query()->findOrFail($f['enrollment']->application_id);
            $copy = $application->replicate(['id']);
            $copy->fill(['group_id' => $f['thursday']->id, 'price_option_id' => $f['thursdayPrice']->id, 'idempotency_key' => fake()->uuid()])->save();
            Enrollment::query()->create([
                'application_id' => $copy->id, 'child_id' => $f['enrollment']->child_id, 'group_id' => $f['thursday']->id,
                'price_option_id' => $f['thursdayPrice']->id, 'scope' => 'full_period', 'status' => 'active', 'starts_on' => '2026-10-20',
            ]);
        });
        $this->postJson("{$base}/transfer", [...$this->transferPayload($f), 'version' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('target_group_id');
    }

    public function test_an_end_date_is_never_extended(): void
    {
        $f = $this->fixture();
        $this->withTenant($f['organization'], fn () => Enrollment::query()->whereKey($f['enrollment']->id)->update(['ends_on' => '2026-12-31']));
        $owner = $this->member($f['organization'], 'organization_owner');

        $this->actAs($owner, $f['organization'])
            ->postJson("/api/v1/enrollment/enrollments/{$f['enrollment']->id}/end", ['last_day' => '2027-01-15', 'reason' => self::REASON, 'version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('last_day');
    }

    public function test_finance_sees_pause_dates_but_not_the_reason(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['organization'], 'organization_owner');
        $finance = $this->member($f['organization'], 'finance');
        $this->actAs($owner, $f['organization'])
            ->postJson("/api/v1/enrollment/enrollments/{$f['enrollment']->id}/pauses", ['starts_on' => '2026-11-09', 'ends_on' => '2026-11-11', 'reason_category' => 'health', 'version' => 1])
            ->assertCreated();

        $this->actAs($finance, $f['organization'])
            ->getJson("/api/v1/enrollment/groups/{$f['tuesday']->id}/roster")
            ->assertOk()
            ->assertJsonPath('enrollments.0.pauses.0.starts_on', '2026-11-09')
            ->assertJsonPath('enrollments.0.pauses.0.reason_category', null)
            ->assertJsonPath('upcoming_sessions', []);
    }

    /** @return iterable<string, array{string, int}> */
    public static function rolesThatMayNotChange(): iterable
    {
        yield 'finance' => ['finance', 200];
        yield 'viewer' => ['viewer', 200];
        yield 'instructor' => ['instructor', 403];
    }

    #[DataProvider('rolesThatMayNotChange')]
    public function test_only_owner_admin_and_coordinator_may_change_enrollments(string $role, int $listStatus): void
    {
        $f = $this->fixture();
        $member = $this->member($f['organization'], $role);
        $base = "/api/v1/enrollment/enrollments/{$f['enrollment']->id}";
        $session = $this->withTenant($f['organization'], fn () => Session::query()->where('group_id', $f['tuesday']->id)->whereDate('starts_at', '2026-11-10')->firstOrFail());
        $this->actAs($member, $f['organization']);

        $this->getJson('/api/v1/enrollment/groups')->assertStatus($listStatus);
        $this->getJson("{$base}/transfer-options")->assertForbidden();
        $this->postJson("{$base}/transfer", $this->transferPayload($f))->assertForbidden();
        $this->postJson("{$base}/pauses", ['starts_on' => '2026-11-09', 'ends_on' => '2026-11-11', 'reason_category' => 'health', 'note' => 'Болест', 'version' => 1])->assertForbidden();
        $this->postJson("{$base}/end", ['last_day' => '2026-11-30', 'reason' => self::REASON, 'version' => 1])->assertForbidden();
        $this->postJson("{$base}/skipped-sessions", ['session_id' => $session->id, 'reason' => 'Състезание', 'version' => 1])->assertForbidden();
        $this->deleteJson("{$base}/skipped-sessions/{$session->id}?version=1")->assertForbidden();
        $this->postJson("{$base}/pauses/".Str::uuid().'/end', ['resume_on' => '2026-11-10', 'version' => 1])->assertForbidden();
        $this->postJson("{$base}/reopen", ['version' => 1])->assertForbidden();

        $this->assertSame(1, $this->withTenant($f['organization'], fn () => Enrollment::query()->findOrFail($f['enrollment']->id)->version));
    }

    #[TestGroup('tenant-isolation')]
    public function test_another_organizations_enrollments_groups_and_sessions_are_not_reachable(): void
    {
        $f = $this->fixture();
        $foreign = $this->fixture();
        $owner = $this->member($f['organization'], 'organization_owner');
        $this->actAs($owner, $f['organization']);
        $foreignBase = "/api/v1/enrollment/enrollments/{$foreign['enrollment']->id}";
        $foreignSession = $this->withTenant($foreign['organization'], fn () => Session::query()->where('group_id', $foreign['tuesday']->id)->whereDate('starts_at', '2026-11-10')->firstOrFail());

        $this->getJson("{$foreignBase}/transfer-options")->assertNotFound();
        $this->postJson("{$foreignBase}/transfer", $this->transferPayload($foreign))->assertNotFound();
        $this->postJson("{$foreignBase}/pauses", ['starts_on' => '2026-11-09', 'ends_on' => '2026-11-11', 'reason_category' => 'health', 'note' => 'Болест', 'version' => 1])->assertNotFound();
        $this->postJson("{$foreignBase}/end", ['last_day' => '2026-11-30', 'reason' => self::REASON, 'version' => 1])->assertNotFound();
        $this->postJson("{$foreignBase}/skipped-sessions", ['session_id' => $foreignSession->id, 'reason' => 'Състезание', 'version' => 1])->assertNotFound();
        $this->deleteJson("{$foreignBase}/skipped-sessions/{$foreignSession->id}?version=1")->assertNotFound();
        $this->postJson("{$foreignBase}/pauses/".Str::uuid().'/end', ['resume_on' => '2026-11-10', 'version' => 1])->assertNotFound();
        $this->postJson("{$foreignBase}/reopen", ['version' => 1])->assertNotFound();
        $this->getJson('/api/v1/enrollment/enrollments/not-a-uuid/transfer-options')->assertNotFound();

        // Own enrollment with a foreign group, price or session in the body is refused without revealing anything.
        $own = "/api/v1/enrollment/enrollments/{$f['enrollment']->id}";
        $this->postJson("{$own}/transfer", [...$this->transferPayload($f), 'target_group_id' => $foreign['thursday']->id, 'price_option_id' => $foreign['thursdayPrice']->id])
            ->assertUnprocessable()->assertJsonValidationErrors('target_group_id');
        $this->postJson("{$own}/skipped-sessions", ['session_id' => $foreignSession->id, 'reason' => 'Състезание', 'version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('session_id');
        $this->getJson('/api/v1/enrollment/groups')->assertOk()->assertJsonMissing(['id' => $foreign['tuesday']->id]);

        $untouched = $this->withTenant($foreign['organization'], fn () => Enrollment::query()->findOrFail($foreign['enrollment']->id));
        $this->assertSame('active', $untouched->status->value);
        $this->assertSame(1, $untouched->version);
    }

    #[TestGroup('tenant-isolation')]
    public function test_application_role_cannot_read_or_write_foreign_pauses(): void
    {
        if (! $this->postgresIsAvailable()) {
            $this->markTestSkipped('PostgreSQL is required for the database-layer pause isolation check.');
        }

        Artisan::call('migrate', ['--database' => 'pgsql_admin', '--force' => true]);
        $pdo = DB::connection('pgsql_admin')->getPdo();
        $pdo->beginTransaction();

        try {
            $selected = $this->insertPauseFixture($pdo, 'selected');
            $foreign = $this->insertPauseFixture($pdo, 'foreign');

            // The pause's organization must be the enrollment's organization (composite foreign key).
            $pdo->exec('SAVEPOINT mixed_tenant');
            try {
                $pdo->prepare(
                    'INSERT INTO enrollment_pauses (id, organization_id, enrollment_id, starts_on, ends_on, reason_category, created_at, updated_at) '
                    ."VALUES (gen_random_uuid(), :organization, :enrollment, '2026-11-09', '2026-11-10', 'other', NOW(), NOW())"
                )->execute(['organization' => $selected['organization'], 'enrollment' => $foreign['enrollment']]);
                $this->fail('A pause must not point at another organization\'s enrollment.');
            } catch (\PDOException $exception) {
                $this->assertSame('23503', $exception->getCode(), 'Expected the tenant foreign key to refuse the row.');
                $pdo->exec('ROLLBACK TO SAVEPOINT mixed_tenant');
            }

            $pdo->exec('SET LOCAL ROLE progressive_app');
            $pdo->prepare("SELECT set_config('app.current_organization_id', :id, true)")->execute(['id' => $selected['organization']]);

            $this->assertSame([$selected['pause']], $pdo->query('SELECT id FROM enrollment_pauses')->fetchAll(\PDO::FETCH_COLUMN));
            $update = $pdo->prepare("UPDATE enrollment_pauses SET note = 'tampered' WHERE id = :id");
            $update->execute(['id' => $foreign['pause']]);
            $this->assertSame(0, $update->rowCount());

            $pdo->exec('SAVEPOINT foreign_insert');
            try {
                $pdo->prepare(
                    'INSERT INTO enrollment_pauses (id, organization_id, enrollment_id, starts_on, ends_on, reason_category, created_at, updated_at) '
                    ."VALUES (gen_random_uuid(), :organization, :enrollment, '2026-11-09', '2026-11-10', 'other', NOW(), NOW())"
                )->execute(['organization' => $foreign['organization'], 'enrollment' => $foreign['enrollment']]);
                $this->fail('A pause for another organization must not be insertable.');
            } catch (\PDOException) {
                $pdo->exec('ROLLBACK TO SAVEPOINT foreign_insert');
            }

            $pdo->prepare("SELECT set_config('app.current_organization_id', '', true)")->execute();
            $this->assertSame([], $pdo->query('SELECT id FROM enrollment_pauses')->fetchAll(\PDO::FETCH_COLUMN));
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    /** @return array<string, mixed> */
    private function transferPayload(array $f, string $effectiveDate = '2026-11-12'): array
    {
        return [
            'target_group_id' => $f['thursday']->id,
            'price_option_id' => $f['thursdayPrice']->id,
            'effective_date' => $effectiveDate,
            'reason' => self::REASON,
            'version' => 1,
        ];
    }

    private function actAs(User $user, Organization $organization): static
    {
        return $this->actingAs($user)->withSession(['active_organization_id' => $organization->id]);
    }

    private function member(Organization $organization, string $role): User
    {
        $user = User::factory()->create();
        OrganizationMembership::query()->withoutGlobalScopes()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active',
        ]);

        return $user;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withTenant(Organization $organization, callable $callback): mixed
    {
        app(TenantContext::class)->select($organization);

        try {
            return $callback();
        } finally {
            app(TenantContext::class)->clear();
        }
    }

    /** @return list<string> */
    private function sessionDates(Organization $organization, Enrollment|string $enrollment): array
    {
        $id = $enrollment instanceof Enrollment ? $enrollment->id : $enrollment;

        return $this->withTenant($organization, fn (): array => app(SessionApplicability::class)
            ->sessionsFor(Enrollment::query()->findOrFail($id))
            ->map(fn (Session $session): string => $session->starts_at->format('Y-m-d'))
            ->values()->all());
    }

    /** @param list<string> $secrets */
    private function assertAuditWithoutPersonalData(string $action, string $subjectId, array $secrets = []): void
    {
        $event = DB::table('audit_events')->where('action', $action)->where('subject_id', $subjectId)->first();
        $this->assertNotNull($event, "Missing audit event {$action}.");
        // Stored JSON escapes Cyrillic (\u0410…); compare against readable text.
        $metadata = $this->readable((string) $event->metadata);
        foreach (['Ани', 'Иванова', self::REASON, ...$secrets] as $secret) {
            $this->assertStringNotContainsString($secret, $metadata);
        }
    }

    private function readable(string $json): string
    {
        return (string) json_encode(json_decode($json, true), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Tuesday group (current), Thursday group (same activity) and a group of another activity.
     * Sessions: Tuesdays 27.10–24.11, Thursdays 05.11–26.11 (14:00 UTC).
     *
     * @return array{organization: Organization, tuesday: Group, thursday: Group, otherActivityGroup: Group, tuesdayPrice: PriceOption, thursdayPrice: PriceOption, enrollment: Enrollment, parent: User}
     */
    private function fixture(string $startsOn = '2026-10-20', int $thursdayCapacity = 3, int $tuesdayCapacity = 10): array
    {
        $organization = Organization::query()->withoutGlobalScopes()->create([
            'name' => 'Организация', 'slug' => fake()->unique()->slug(), 'status' => 'active',
        ]);

        return $this->withTenant($organization, function () use ($organization, $startsOn, $thursdayCapacity, $tuesdayCapacity): array {
            $parent = User::factory()->create();
            $child = $parent->children()->create(['first_name' => 'Ани', 'last_name' => 'Иванова', 'class_label' => '3 клас']);
            $activity = Activity::query()->create([
                'slug' => fake()->unique()->slug(), 'title' => 'Шах', 'category' => 'Наука',
                'summary' => 'Описание', 'age_label' => '2.–4. клас', 'status' => 'published',
            ]);
            $offering = $activity->offerings()->create([]);
            $tuesday = $offering->groups()->create(['name' => 'Вторник', 'capacity_max' => $tuesdayCapacity, 'status' => 'published', 'waitlist_enabled' => true, 'enrollment_approval_mode' => 'manual']);
            $thursday = $offering->groups()->create(['name' => 'Четвъртък', 'capacity_max' => $thursdayCapacity, 'status' => 'published', 'waitlist_enabled' => true, 'enrollment_approval_mode' => 'manual']);
            foreach (['2026-10-27', '2026-11-03', '2026-11-10', '2026-11-17', '2026-11-24'] as $date) {
                $tuesday->sessions()->create(['starts_at' => "{$date}T14:00:00Z", 'ends_at' => "{$date}T15:00:00Z", 'status' => 'scheduled']);
            }
            foreach (['2026-11-05', '2026-11-12', '2026-11-19', '2026-11-26'] as $date) {
                $thursday->sessions()->create(['starts_at' => "{$date}T14:00:00Z", 'ends_at' => "{$date}T15:00:00Z", 'status' => 'scheduled']);
            }
            $tuesdayPrice = $offering->priceOptions()->create(['group_id' => $tuesday->id, 'enrollment_mode' => 'monthly', 'unit' => 'month', 'amount_cents' => 4500, 'currency' => 'EUR', 'is_available' => true]);
            $thursdayPrice = $offering->priceOptions()->create(['group_id' => $thursday->id, 'enrollment_mode' => 'monthly', 'unit' => 'month', 'amount_cents' => 5000, 'currency' => 'EUR', 'is_available' => true]);

            $otherActivity = Activity::query()->create([
                'slug' => fake()->unique()->slug(), 'title' => 'Рисуване', 'category' => 'Изкуство',
                'summary' => 'Описание', 'age_label' => '1.–4. клас', 'status' => 'published',
            ]);
            $otherOffering = $otherActivity->offerings()->create([]);
            $otherActivityGroup = $otherOffering->groups()->create(['name' => 'Рисуване', 'capacity_max' => 10, 'status' => 'published']);

            $application = EnrollmentApplication::query()->create([
                'parent_user_id' => $parent->id, 'child_id' => $child->id, 'group_id' => $tuesday->id,
                'price_option_id' => $tuesdayPrice->id, 'scope' => 'full_period', 'source' => 'organization_catalog',
                'status' => 'approved', 'idempotency_key' => fake()->uuid(), 'accepted_terms_version' => 'v1',
                'accepted_terms_at' => now(), 'amount_cents_snapshot' => 4500, 'currency_snapshot' => 'EUR', 'unit_snapshot' => 'month',
            ]);
            $enrollment = Enrollment::query()->create([
                'application_id' => $application->id, 'child_id' => $child->id, 'group_id' => $tuesday->id,
                'price_option_id' => $tuesdayPrice->id, 'scope' => 'full_period', 'status' => 'active', 'starts_on' => $startsOn,
            ]);

            return compact('organization', 'tuesday', 'thursday', 'otherActivityGroup', 'tuesdayPrice', 'thursdayPrice', 'enrollment', 'parent');
        });
    }

    private function enrollAnotherChild(array $f, Group $group, PriceOption $price, string $startsOn = '2026-10-20', ?Session $visit = null, ?string $endsOn = null): void
    {
        $this->withTenant($f['organization'], function () use ($group, $price, $startsOn, $visit, $endsOn): void {
            $parent = User::factory()->create();
            $child = $parent->children()->create(['first_name' => 'Боби', 'last_name' => 'Петров']);
            $scope = $visit === null ? 'full_period' : 'single';
            $application = EnrollmentApplication::query()->create([
                'parent_user_id' => $parent->id, 'child_id' => $child->id, 'group_id' => $group->id,
                'price_option_id' => $price->id, 'session_id' => $visit?->id, 'scope' => $scope, 'source' => 'organization_catalog',
                'status' => 'approved', 'idempotency_key' => fake()->uuid(), 'accepted_terms_version' => 'v1',
                'accepted_terms_at' => now(), 'amount_cents_snapshot' => $price->amount_cents, 'currency_snapshot' => 'EUR', 'unit_snapshot' => 'month',
            ]);
            Enrollment::query()->create([
                'application_id' => $application->id, 'child_id' => $child->id, 'group_id' => $group->id,
                'price_option_id' => $price->id, 'session_id' => $visit?->id, 'scope' => $scope, 'status' => 'active', 'starts_on' => $startsOn,
                'ends_on' => $endsOn,
            ]);
        });
    }

    private function postgresIsAvailable(): bool
    {
        try {
            DB::connection('pgsql_admin')->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{organization: string, enrollment: string, pause: string} */
    private function insertPauseFixture(\PDO $pdo, string $label): array
    {
        $ids = [];
        foreach (['organization', 'user', 'child', 'activity', 'offering', 'group', 'price', 'application', 'enrollment', 'pause'] as $key) {
            $ids[$key] = (string) Str::uuid();
        }
        $statements = [
            'INSERT INTO organizations (id, name, slug, status, created_at, updated_at) VALUES (:organization, :label, :slug, \'active\', NOW(), NOW())',
            'INSERT INTO users (id, name, email, password, created_at, updated_at) VALUES (:user, :label, :email, \'x\', NOW(), NOW())',
            'INSERT INTO children (id, owner_user_id, first_name, last_name, created_at, updated_at) VALUES (:child, :user, \'Тест\', :label, NOW(), NOW())',
            'INSERT INTO activities (id, organization_id, title, slug, category, summary, age_label, status, version, created_at, updated_at) VALUES (:activity, :organization, :label, :slug, \'arts\', \'x\', \'7+\', \'published\', 1, NOW(), NOW())',
            'INSERT INTO offerings (id, organization_id, activity_id, created_at, updated_at) VALUES (:offering, :organization, :activity, NOW(), NOW())',
            'INSERT INTO activity_groups (id, organization_id, offering_id, name, status, created_at, updated_at) VALUES (:group, :organization, :offering, :label, \'published\', NOW(), NOW())',
            'INSERT INTO price_options (id, organization_id, offering_id, group_id, enrollment_mode, unit, amount_cents, currency, created_at, updated_at) VALUES (:price, :organization, :offering, :group, \'monthly\', \'month\', 4500, \'EUR\', NOW(), NOW())',
            'INSERT INTO enrollment_applications (id, organization_id, parent_user_id, child_id, group_id, price_option_id, scope, source, status, idempotency_key, accepted_terms_version, accepted_terms_at, amount_cents_snapshot, unit_snapshot, created_at, updated_at) VALUES (:application, :organization, :user, :child, :group, :price, \'full_period\', \'organization_catalog\', \'approved\', :label, \'v1\', NOW(), 4500, \'month\', NOW(), NOW())',
            'INSERT INTO enrollments (id, organization_id, application_id, child_id, group_id, price_option_id, scope, status, starts_on, created_at, updated_at) VALUES (:enrollment, :organization, :application, :child, :group, :price, \'full_period\', \'active\', \'2026-10-20\', NOW(), NOW())',
            'INSERT INTO enrollment_pauses (id, organization_id, enrollment_id, starts_on, ends_on, reason_category, created_at, updated_at) VALUES (:pause, :organization, :enrollment, \'2026-11-09\', \'2026-11-11\', \'other\', NOW(), NOW())',
        ];
        foreach ($statements as $sql) {
            $statement = $pdo->prepare($sql);
            $parameters = [];
            foreach ([...$ids, 'label' => $label.'-'.$ids['organization'], 'slug' => $label.'-'.$ids['organization'], 'email' => $label.'-'.$ids['user'].'@example.test'] as $name => $value) {
                if (str_contains($sql, ':'.$name)) {
                    $parameters[$name] = $value;
                }
            }
            $statement->execute($parameters);
        }

        return ['organization' => $ids['organization'], 'enrollment' => $ids['enrollment'], 'pause' => $ids['pause']];
    }
}
