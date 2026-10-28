<?php

namespace Tests\Feature\Enrollment;

use App\Domain\Enrollment\Actions\RecordVisitOutcome;
use App\Domain\Enrollment\Actions\SubmitEnrollmentApplication;
use App\Domain\Enrollment\Models\EnrollmentEvent;
use App\Domain\Enrollment\Services\EnrollmentCapacity;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\VisitOfferFixture;
use Tests\TestCase;

class VisitOutcomeTest extends TestCase
{
    use RefreshDatabase, VisitOfferFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->travelTo('2026-10-28T10:00:00Z');
    }

    public function test_timely_cancellation_uses_accepted_deadline_releases_place_and_records_policy_without_refund(): void
    {
        [$org, , , $session, $offer, $child] = $this->visitFixture([
            'visit_limit' => 1, 'cancellation_outcome' => 'refund', 'cancellation_policy' => 'Refund eligible',
        ], ['enrollment_approval_mode' => 'automatic']);
        $application = app(SubmitEnrollmentApplication::class)->handle($child->owner, [
            'child_id' => $child->id, 'visit_offer_id' => $offer->id, 'session_id' => $session->id, 'accepted_terms_version' => 'v1',
        ], 'cancel');
        app(TenantContext::class)->select($org);
        $offer->update(['cancellation_deadline_hours' => 72, 'cancellation_outcome' => 'non_refundable']);
        $enrollment = $application->enrollment;
        $this->travelTo('2026-11-02T14:00:00Z');
        $transition = app(RecordVisitOutcome::class)->handle($child->owner, $enrollment, 'cancelled_in_time', 'Cannot attend');
        $this->assertSame('cancelled', $enrollment->refresh()->status->value);
        $this->assertSame(1, app(EnrollmentCapacity::class)->remainingForOffer($offer, $session));
        $this->assertSame('cancelled_in_time', $transition->metadata['outcome']);
        $this->assertSame('refund', $transition->metadata['cancellation_outcome']);
        $this->assertFalse($transition->metadata['refund_performed']);
        $this->assertSame('Cannot attend', $transition->public_reason);
        $event = EnrollmentEvent::where('type', 'enrollment.visit_outcome_recorded')->firstOrFail();
        $this->assertSame($transition->metadata, $event->payload);
        $repeat = app(RecordVisitOutcome::class)->handle($child->owner, $enrollment, 'cancelled_in_time', 'Retry');
        $this->assertSame($transition->id, $repeat->id);
        $this->assertSame(1, EnrollmentEvent::where('type', 'enrollment.visit_outcome_recorded')->count());
    }

    public function test_cancellation_after_boundary_is_late_and_used_while_parent_cannot_record_attendance(): void
    {
        [, , $group, $session, $offer, $child] = $this->visitFixture();
        $enrollment = $this->visitEnrollment($child, $group, $session, 'trial', $offer);
        $this->travelTo('2026-11-02T14:00:01Z');
        try {
            app(RecordVisitOutcome::class)->handle($child->owner, $enrollment, 'cancelled_in_time', null);
            $this->fail('Cancellation deadline must be enforced.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('outcome', $exception->errors());
        }
        $this->actingAs($child->owner)->postJson('/api/v1/parent/enrollments/'.$enrollment->id.'/visit-outcome', ['outcome' => 'attended'])->assertForbidden();
        $this->actingAs($child->owner)->postJson('/api/v1/parent/enrollments/'.$enrollment->id.'/visit-outcome', ['outcome' => 'late_cancelled'])->assertOk();
        $this->assertSame('ended', $enrollment->refresh()->status->value);
        $this->assertDatabaseHas('enrollment_transitions', ['enrollment_id' => $enrollment->id, 'to_status' => 'ended']);
    }

    public function test_two_no_shows_block_trials_only_in_the_same_organization_and_staff_override_requires_a_reason(): void
    {
        [$org, , $group, $session, $offer, $child] = $this->visitFixture(['trial_limit_per_academic_year' => 10, 'no_show_trial_limit' => 2]);
        $staff = User::factory()->create();
        OrganizationMembership::create(['organization_id' => $org->id, 'user_id' => $staff->id, 'role' => 'coordinator', 'status' => 'active']);
        $this->travelTo('2026-11-03T15:00:00Z');
        for ($i = 0; $i < 2; $i++) {
            $enrollment = $this->visitEnrollment($child, $group, $session, 'trial', $offer);
            app(RecordVisitOutcome::class)->handle($staff, $enrollment, 'no_show', 'Absent');
            $this->assertSame('ended', $enrollment->refresh()->status->value);
        }
        $next = $group->sessions()->create(['starts_at' => '2026-11-10T14:00:00Z', 'ends_at' => '2026-11-10T15:00:00Z', 'status' => 'scheduled']);
        $offer->sessions()->attach($next->id);
        $payload = ['child_id' => $child->id, 'visit_offer_id' => $offer->id, 'session_id' => $next->id, 'accepted_terms_version' => 'v1'];
        foreach ([[$child->owner, 'Parent override'], [$staff, '   '], [null, null]] as [$actor, $reason]) {
            try {
                app(SubmitEnrollmentApplication::class)->handle($child->owner, $payload, 'denied-'.uniqid(), $actor, $reason);
                $this->fail('Two no-shows require an authorized, reasoned override.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('no_show_limit', $exception->errors());
            }
        }
        $allowed = app(SubmitEnrollmentApplication::class)->handle($child->owner, $payload, 'reasoned', $staff, 'Transport issue resolved');
        app(TenantContext::class)->select($org);
        $history = $allowed->transitions()->where('to_status', 'submitted')->firstOrFail();
        $this->assertSame('Transport issue resolved', $history->metadata['eligibility_override_reason']);
        $this->assertSame($staff->id, $history->actor_user_id);

        [, , , $foreignSession, $foreignOffer] = $this->visitFixture(['no_show_trial_limit' => 2], [], ['starts_at' => '2026-11-12T14:00:00Z', 'ends_at' => '2026-11-12T15:00:00Z']);
        $foreign = app(SubmitEnrollmentApplication::class)->handle($child->owner, [...$payload,
            'visit_offer_id' => $foreignOffer->id, 'session_id' => $foreignSession->id,
        ], 'other-organization');
        $this->assertSame('submitted', $foreign->status->value);
    }

    public function test_parent_cannot_cancel_after_start_and_staff_no_show_still_counts(): void
    {
        [$org, , $group, $session, $offer, $child] = $this->visitFixture(['trial_limit_per_academic_year' => 10, 'no_show_trial_limit' => 1]);
        $enrollment = $this->visitEnrollment($child, $group, $session, 'trial', $offer);
        $staff = User::factory()->create();
        OrganizationMembership::create(['organization_id' => $org->id, 'user_id' => $staff->id, 'role' => 'coordinator', 'status' => 'active']);
        foreach (['2026-11-03T14:00:00Z', '2026-11-03T16:00:00Z'] as $time) {
            $this->travelTo($time);
            $this->actingAs($child->owner)->postJson('/api/v1/parent/enrollments/'.$enrollment->id.'/visit-outcome', ['outcome' => 'late_cancelled'])
                ->assertUnprocessable()->assertJsonValidationErrors('outcome');
        }
        app(TenantContext::class)->select($org);
        $this->assertSame('active', $enrollment->refresh()->status->value);
        $this->assertSame(0, $enrollment->transitions()->count());
        app(RecordVisitOutcome::class)->handle($staff, $enrollment, 'no_show', 'Absent');
        $next = $group->sessions()->create(['starts_at' => '2026-11-10T14:00:00Z', 'ends_at' => '2026-11-10T15:00:00Z', 'status' => 'scheduled']);
        $offer->sessions()->attach($next->id);
        $this->actingAs($child->owner)->withHeader('Idempotency-Key', 'after-no-show')->postJson('/api/v1/parent/enrollment-applications', [
            'child_id' => $child->id, 'visit_offer_id' => $offer->id, 'session_id' => $next->id, 'accepted_terms_version' => 'v1',
        ])->assertUnprocessable()->assertJsonValidationErrors('no_show_limit');
    }

    public function test_unrelated_parent_and_foreign_staff_cannot_record_outcomes(): void
    {
        [$org, , $group, $session, $offer, $child] = $this->visitFixture();
        $enrollment = $this->visitEnrollment($child, $group, $session, 'trial', $offer);
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->postJson('/api/v1/parent/enrollments/'.$enrollment->id.'/visit-outcome', ['outcome' => 'cancelled_in_time'])->assertNotFound();
        [$foreignOrg] = $this->visitFixture();
        OrganizationMembership::create(['organization_id' => $foreignOrg->id, 'user_id' => $stranger->id, 'role' => 'coordinator', 'status' => 'active']);
        app(TenantContext::class)->select($org);
        $this->expectException(HttpException::class);
        app(RecordVisitOutcome::class)->handle($stranger, $enrollment, 'no_show', null);
    }

    public function test_visit_outcome_cannot_be_bypassed_with_the_full_period_end_action(): void
    {
        [, , $group, $session, $offer, $child] = $this->visitFixture();
        $enrollment = $this->visitEnrollment($child, $group, $session, 'trial', $offer);
        $this->actingAs($child->owner)->postJson('/api/v1/parent/enrollments/'.$enrollment->id.'/end-from', [
            'effective_date' => '2026-11-03', 'reason' => 'Cancel',
        ])->assertUnprocessable();
        $this->assertSame('active', $enrollment->refresh()->status->value);
    }

    public function test_recorded_transition_metadata_cannot_be_rewritten(): void
    {
        [, , $group, $session, $offer, $child] = $this->visitFixture();
        $enrollment = $this->visitEnrollment($child, $group, $session, 'trial', $offer);
        $transition = app(RecordVisitOutcome::class)->handle($child->owner, $enrollment, 'cancelled_in_time', 'Original reason');
        $this->expectException(QueryException::class);
        DB::table('enrollment_transitions')->where('id', $transition->id)->update(['public_reason' => 'Changed reason']);
    }

    public function test_staff_records_attendance_once_and_foreign_tenant_route_cannot_see_it(): void
    {
        [$org, , $group, $session, $offer, $child] = $this->visitFixture();
        $enrollment = $this->visitEnrollment($child, $group, $session, 'trial', $offer);
        $staff = User::factory()->create();
        OrganizationMembership::create(['organization_id' => $org->id, 'user_id' => $staff->id, 'role' => 'coordinator', 'status' => 'active']);
        $this->actingAs($staff)->withSession(['active_organization_id' => $org->id])
            ->postJson('/api/v1/enrollments/'.$enrollment->id.'/visit-outcome', ['outcome' => 'attended'])->assertUnprocessable();
        $this->travelTo('2026-11-03T15:00:00Z');
        $this->postJson('/api/v1/enrollments/'.$enrollment->id.'/visit-outcome', ['outcome' => 'attended'])->assertOk()->assertJsonPath('outcome', 'attended');
        $this->postJson('/api/v1/enrollments/'.$enrollment->id.'/visit-outcome', ['outcome' => 'no_show'])->assertUnprocessable();
        $this->assertSame('ended', $enrollment->refresh()->status->value);
        [$foreignOrg] = $this->visitFixture();
        OrganizationMembership::create(['organization_id' => $foreignOrg->id, 'user_id' => $staff->id, 'role' => 'coordinator', 'status' => 'active']);
        $this->withSession(['active_organization_id' => $foreignOrg->id])
            ->postJson('/api/v1/enrollments/'.$enrollment->id.'/visit-outcome', ['outcome' => 'attended'])->assertNotFound();
    }

    public function test_outcome_event_payload_cannot_be_rewritten_but_delivery_can_be_recorded(): void
    {
        [, , $group, $session, $offer, $child] = $this->visitFixture();
        $enrollment = $this->visitEnrollment($child, $group, $session, 'trial', $offer);
        app(RecordVisitOutcome::class)->handle($child->owner, $enrollment, 'cancelled_in_time', null);
        $event = EnrollmentEvent::where('type', 'enrollment.visit_outcome_recorded')->firstOrFail();
        $event->update(['delivered_at' => now(), 'attempts' => 1]);
        $this->assertNotNull($event->refresh()->delivered_at);
        $this->expectException(QueryException::class);
        $event->update(['payload' => ['outcome' => 'no_show']]);
    }
}
