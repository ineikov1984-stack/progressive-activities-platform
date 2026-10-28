<?php

namespace Tests\Unit\Catalog;

use App\Domain\Catalog\Services\VisitOfferAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\VisitOfferFixture;
use Tests\TestCase;

class VisitOfferAvailabilityTest extends TestCase
{
    use RefreshDatabase, VisitOfferFixture;

    public function test_available_limited_last_place_and_full_follow_the_most_urgent_capacity(): void
    {
        [, , $group, $session, $offer, $child] = $this->visitFixture(
            ['visit_limit' => 8, 'booking_deadline_hours' => 0], ['capacity_max' => 8], ['capacity' => 8],
        );
        $service = app(VisitOfferAvailabilityService::class);
        $at = CarbonImmutable::parse('2026-11-02T12:00:00Z');
        $this->assertSame('available', $service->for($offer, $session, $at)->status);

        for ($i = 0; $i < 5; $i++) {
            $visitor = $child->owner->children()->create(['first_name' => 'Visitor'.$i, 'last_name' => 'Ivanov']);
            $this->visitEnrollment($visitor, $group, $session, 'trial', $offer);
        }
        $this->assertSame('limited', $service->for($offer, $session, $at)->status);
        for ($i = 5; $i < 7; $i++) {
            $visitor = $child->owner->children()->create(['first_name' => 'Visitor'.$i, 'last_name' => 'Ivanov']);
            $this->visitEnrollment($visitor, $group, $session, 'trial', $offer);
        }
        $last = $service->for($offer, $session, $at);
        $this->assertSame('last_place', $last->status);
        $this->assertSame(1, $last->remainingTotalPlaces);
        $this->assertSame(1, $last->remainingOfferPlaces);
        $this->assertFalse($last->waitlistAvailable);

        $this->visitEnrollment($child, $group, $session, 'trial', $offer);
        $full = $service->for($offer, $session, $at);
        $this->assertSame('full', $full->status);
        $this->assertTrue($full->waitlistAvailable);
    }

    public function test_percentage_threshold_and_offer_limit_both_make_availability_limited(): void
    {
        [, , $group, $session, $offer, $child] = $this->visitFixture(
            ['type' => 'single', 'visit_limit' => 20, 'booking_deadline_hours' => 0], ['capacity_max' => 20], ['capacity' => 20],
        );
        for ($i = 0; $i < 15; $i++) {
            $visitor = $child->owner->children()->create(['first_name' => 'Visitor'.$i, 'last_name' => 'Ivanov']);
            $this->visitEnrollment($visitor, $group, $session, 'single', $offer);
        }
        $availability = app(VisitOfferAvailabilityService::class)->for(
            $offer, $session, CarbonImmutable::parse('2026-11-02T12:00:00Z'),
        );
        $this->assertSame('limited', $availability->status);
        $this->assertSame(5, $availability->remainingTotalPlaces);

        $offer->update(['visit_limit' => 16]);
        $this->assertSame('last_place', app(VisitOfferAvailabilityService::class)->for(
            $offer->refresh(), $session, CarbonImmutable::parse('2026-11-02T12:00:00Z'),
        )->status);
    }

    public function test_deadline_is_closed_at_exact_instant_and_cancelled_session_is_closed(): void
    {
        [, , , $session, $offer] = $this->visitFixture(['booking_deadline_hours' => 12, 'visit_limit' => 8]);
        $service = app(VisitOfferAvailabilityService::class);

        $this->assertSame('available', $service->for(
            $offer, $session, CarbonImmutable::parse('2026-11-03T01:59:59Z'),
        )->status);
        $closed = $service->for($offer, $session, CarbonImmutable::parse('2026-11-03T02:00:00Z'));
        $this->assertSame('closed', $closed->status);
        $this->assertFalse($closed->waitlistAvailable);

        $session->update(['status' => 'cancelled']);
        $this->assertSame('closed', $service->for(
            $offer, $session->refresh(), CarbonImmutable::parse('2026-11-02T12:00:00Z'),
        )->status);
    }
}
