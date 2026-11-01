<?php

namespace Tests\Feature\Enrollment;

use App\Domain\Catalog\Models\PriceOption;
use App\Domain\Catalog\Models\Session;
use App\Domain\Enrollment\Actions\RecordVisitOutcome;
use App\Domain\Enrollment\Actions\SubmitEnrollmentApplication;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Services\EnrollmentCapacity;
use App\Domain\Enrollment\Services\VisitBooking;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\AcceptsPaymentTerms;
use Tests\Support\VisitOfferFixture;
use Tests\TestCase;

/**
 * A visit on a session the organization moved, and a session added from the schedule (docs/product/schedule-changes.md,
 * т. 4.7, 5.5; decisions 5 and 9): whether the visit has happened is told by the session's current start, the booked
 * start counts only for the deadline of a timely cancellation, and until the new start a cancellation is free. An added
 * session is not open for a trial or a single visit from a price. The session of the fixture is on 3 November at
 * 16:00 in Sofia; 28 October. Every name is synthetic.
 */
class ScheduleChangeVisitsTest extends TestCase
{
    use AcceptsPaymentTerms;
    use RefreshDatabase;
    use VisitOfferFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->travelTo('2026-10-28T10:00:00Z');
    }

    public function test_a_visit_moved_later_is_cancelled_in_time_until_its_new_start(): void
    {
        [$enrollment, $session, $parent] = $this->bookedVisit('later');
        Session::query()->whereKey($session->id)->update(['starts_at' => '2026-11-05T14:00:00Z', 'ends_at' => '2026-11-05T15:00:00Z']);

        // After the start the parent booked for, before the new one: in time, without a fee — never late (decision 5).
        $this->travelTo('2026-11-04T10:00:00Z');
        $this->refused(fn () => app(RecordVisitOutcome::class)->handle($parent, $enrollment, 'late_cancelled', null));
        $transition = app(RecordVisitOutcome::class)->handle($parent, $enrollment, 'cancelled_in_time', null);

        $this->assertSame('cancelled_in_time', $transition->metadata['outcome'] ?? null);
        $this->assertSame('cancelled', Enrollment::query()->findOrFail($enrollment->id)->status->value);
    }

    public function test_a_visit_moved_earlier_has_happened_at_its_new_start(): void
    {
        [$enrollment, $session, $parent, $organization] = $this->bookedVisit('earlier');
        Session::query()->whereKey($session->id)->update(['starts_at' => '2026-11-02T14:00:00Z', 'ends_at' => '2026-11-02T15:00:00Z']);
        $staff = User::factory()->create();
        OrganizationMembership::query()->create(['organization_id' => $organization, 'user_id' => $staff->id, 'role' => 'coordinator', 'status' => 'active']);

        // Attendance is recorded only through attendance, before or after the new start.
        $this->travelTo('2026-11-02T13:00:00Z');
        $this->refused(fn () => app(RecordVisitOutcome::class)->handle($staff, $enrollment, 'attended', null));
        // Started: the parent no longer cancels, though the booked start is a day later.
        $this->travelTo('2026-11-02T15:00:00Z');
        $this->refused(fn () => app(RecordVisitOutcome::class)->handle($parent, $enrollment, 'cancelled_in_time', null));
        $this->refused(fn () => app(RecordVisitOutcome::class)->handle($staff, $enrollment, 'attended', null));

        $this->assertSame('active', Enrollment::query()->findOrFail($enrollment->id)->status->value);
    }

    public function test_a_visit_not_moved_keeps_the_deadline_of_the_start_it_was_booked_for(): void
    {
        [$enrollment, , $parent] = $this->bookedVisit('same');

        // The standard terms for a visit: free until 24 hours before.
        $this->travelTo('2026-11-03T10:00:00Z');
        $this->refused(fn () => app(RecordVisitOutcome::class)->handle($parent, $enrollment, 'cancelled_in_time', null));
        $transition = app(RecordVisitOutcome::class)->handle($parent, $enrollment, 'late_cancelled', null);

        $this->assertSame('late_cancelled', $transition->metadata['outcome'] ?? null);
    }

    public function test_a_session_added_from_the_schedule_is_not_open_for_visits_from_a_price_but_an_offer_takes_it(): void
    {
        [$organization, $activity, $group, $session, $offer, $child] = $this->visitFixture();
        $added = $group->sessions()->create(['starts_at' => '2026-11-04T14:00:00Z', 'ends_at' => '2026-11-04T15:00:00Z', 'status' => 'scheduled', 'extra' => true]);
        $now = now()->toImmutable();

        $this->assertFalse(VisitBooking::isOpen($added, $now));
        $this->assertTrue(VisitBooking::isOpen(Session::query()->findOrFail($session->id), $now));
        $days = app(EnrollmentCapacity::class)->visitDays($group, Session::query()->where('group_id', $group->id)->orderBy('starts_at')->get(), $now);
        $this->assertSame([$session->id], array_map(fn (array $day): string => (string) $day['session']->id, $days));
        $trial = PriceOption::query()->whereNull('group_id')->where('enrollment_mode', 'trial')->firstOrFail();
        app(TenantContext::class)->clear();

        $groups = collect($this->getJson('/api/v1/public/organizations/'.$organization->slug.'/activities/'.$activity->slug)
            ->assertOk()->json('activity.groups'))->keyBy('id');
        $this->assertNotContains($added->id, collect($groups[$group->id]['visit_dates'])->pluck('session_id'));
        $this->actingAs($child->owner)->withHeader('Idempotency-Key', 'added-session')->postJson('/api/v1/parent/enrollment-applications', [
            'child_id' => $child->id, 'group_id' => $group->id, 'price_option_id' => $trial->id, 'session_id' => $added->id,
            'accepted_terms_version' => $this->standardTermsVersion($trial->id),
        ])->assertUnprocessable()->assertJsonPath('errors.session_id.0', 'Записването за тази дата е затворено.');

        // An offer chooses its sessions by hand and is not asked.
        app(TenantContext::class)->select($organization);
        $offer->sessions()->attach($added->id);
        $application = app(SubmitEnrollmentApplication::class)->handle($child->owner, [
            'child_id' => $child->id, 'visit_offer_id' => $offer->id, 'session_id' => $added->id, 'accepted_terms_version' => $this->visitTerms(),
        ], 'offer-on-added');
        $this->assertSame([$added->id, 'submitted'], [$application->session_id, $application->status->value]);
    }

    /**
     * A trial from the fixture's offer, approved at once, with the start the parent booked for kept.
     *
     * @return array{Enrollment, Session, User, string}
     */
    private function bookedVisit(string $key): array
    {
        [$organization, , , $session, $offer, $child] = $this->visitFixture([], ['enrollment_approval_mode' => 'automatic']);
        $application = app(SubmitEnrollmentApplication::class)->handle($child->owner, [
            'child_id' => $child->id, 'visit_offer_id' => $offer->id, 'session_id' => $session->id, 'accepted_terms_version' => $this->visitTerms(),
        ], $key);
        app(TenantContext::class)->select($organization);
        $enrollment = Enrollment::query()->where('application_id', $application->id)->firstOrFail();

        return [$enrollment, $session, $child->owner, (string) $organization->id];
    }

    private function refused(callable $outcome): void
    {
        try {
            $outcome();
            $this->fail('The outcome must not fit the session.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('outcome', $exception->errors());
        }
    }
}
