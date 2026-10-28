<?php

namespace Tests\Feature\Enrollment;

use App\Domain\Catalog\Models\VisitOffer;
use App\Domain\Catalog\Services\VisitOfferAvailabilityService;
use App\Domain\Enrollment\Services\EnrollmentCapacity;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\VisitOfferFixture;
use Tests\TestCase;

class VisitOfferCapacityTest extends TestCase
{
    use RefreshDatabase, VisitOfferFixture;

    public function test_session_capacity_is_the_hard_ceiling_even_when_the_group_is_larger(): void
    {
        [, , $group, $session, $offer, $child] = $this->visitFixture(
            ['visit_limit' => 4], ['capacity_max' => 12], ['capacity' => 2],
        );
        $this->visitEnrollment($child, $group, null, 'full_period', startsOn: '2026-11-01');

        $capacity = app(EnrollmentCapacity::class);
        $this->assertSame(1, $capacity->remainingForSession($session));
        $this->assertSame(1, $capacity->remainingForOffer($offer, $session));

        $other = $child->owner->children()->create(['first_name' => 'Ben', 'last_name' => 'Ivanov']);
        $this->visitEnrollment($other, $group, $session, 'single');
        $this->assertSame(0, $capacity->remainingForSession($session));
        $this->assertSame(0, $capacity->remainingForOffer($offer, $session));
    }

    public function test_future_lower_session_capacity_also_limits_full_period_places(): void
    {
        $this->travelTo('2026-10-28T10:00:00Z');
        [, , $group, $session, , $child] = $this->visitFixture(
            [], ['capacity_max' => 12], ['capacity' => 1],
        );
        $this->visitEnrollment($child, $group, null, 'full_period', startsOn: '2026-11-01');

        $this->assertSame(0, app(EnrollmentCapacity::class)->remainingForSession($session));
        $this->assertSame(0, app(EnrollmentCapacity::class)->remainingForGroup($group));
    }

    public function test_offer_limit_does_not_reserve_seats_from_full_period_enrollments(): void
    {
        [, , $group, $session, $offer, $child] = $this->visitFixture(
            ['visit_limit' => 2], ['capacity_max' => 3], ['capacity' => null],
        );
        $this->visitEnrollment($child, $group, null, 'full_period', startsOn: '2026-11-01');
        $other = $child->owner->children()->create(['first_name' => 'Ben', 'last_name' => 'Ivanov']);
        $this->visitEnrollment($other, $group, null, 'full_period', startsOn: '2026-11-01');

        $capacity = app(EnrollmentCapacity::class);
        $this->assertSame(1, $capacity->remainingForSession($session));
        $this->assertSame(1, $capacity->remainingForOffer($offer, $session));

        $third = $child->owner->children()->create(['first_name' => 'Cora', 'last_name' => 'Ivanova']);
        $this->visitEnrollment($third, $group, $session, 'trial', $offer);
        $this->assertSame(0, $capacity->remainingForSession($session));
        $this->assertSame(0, $capacity->remainingForOffer($offer, $session));
    }

    public function test_full_period_enrollment_does_not_use_an_offer_type_limit(): void
    {
        [, , $group, $session, $offer, $child] = $this->visitFixture(
            ['visit_limit' => 2], ['capacity_max' => 3], ['capacity' => 3],
        );
        $this->visitEnrollment($child, $group, $session, 'full_period', $offer, startsOn: '2026-11-01');

        $this->assertSame(2, app(EnrollmentCapacity::class)->remainingForSession($session));
        $this->assertSame(2, app(EnrollmentCapacity::class)->remainingForOffer($offer, $session));
    }

    public function test_offer_limit_counts_only_its_own_visit_enrollments(): void
    {
        [, , $group, $session, $offer, $child] = $this->visitFixture(['visit_limit' => 1]);
        $this->visitEnrollment($child, $group, $session, 'trial', $offer);
        $otherOffer = VisitOffer::query()->create([
            'activity_id' => $offer->activity_id, 'group_id' => $group->id,
            'type' => 'trial', 'status' => 'published', 'title' => 'Another trial', 'visit_limit' => 1,
        ]);
        $otherOffer->sessions()->attach($session->id);

        $this->assertSame(11, app(EnrollmentCapacity::class)->remainingForSession($session));
        $this->assertSame(0, app(EnrollmentCapacity::class)->remainingForOffer($offer, $session));
        $this->assertSame(1, app(EnrollmentCapacity::class)->remainingForOffer($otherOffer, $session));

        $otherChild = $child->owner->children()->create(['first_name' => 'Ben', 'last_name' => 'Ivanov']);
        $this->visitEnrollment($otherChild, $group, $session, 'trial', $otherOffer);
        $this->assertSame(10, app(EnrollmentCapacity::class)->remainingForSession($session));
        $this->assertSame(0, app(EnrollmentCapacity::class)->remainingForOffer($otherOffer, $session));
    }

    public function test_exhausted_offer_limit_is_full_while_other_session_seats_remain(): void
    {
        [, , $group, $session, $offer, $child] = $this->visitFixture(['visit_limit' => 1]);
        $this->visitEnrollment($child, $group, $session, 'trial', $offer);

        $this->assertSame(11, app(EnrollmentCapacity::class)->remainingForSession($session));
        $this->assertSame(0, app(EnrollmentCapacity::class)->remainingForOffer($offer, $session));
        $this->assertSame('full', app(VisitOfferAvailabilityService::class)->for(
            $offer, $session, CarbonImmutable::parse('2026-11-02T12:00:00Z'),
        )->status);
    }

    public function test_last_place_is_rechecked_after_allocation_inside_a_transaction(): void
    {
        [, , $group, $session, $offer, $child] = $this->visitFixture(
            ['visit_limit' => 2], ['capacity_max' => 1], ['capacity' => 1],
        );
        $capacity = app(EnrollmentCapacity::class);

        DB::transaction(function () use ($capacity, $offer, $session, $child, $group): void {
            $this->assertSame(1, $capacity->remainingForOffer($offer, $session));
            $this->visitEnrollment($child, $group, $session, 'trial', $offer);
            $this->assertSame(0, $capacity->remainingForOffer($offer, $session));
        });
    }
}
