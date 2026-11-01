<?php

namespace Tests\Feature\Enrollment;

use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\Session;
use App\Domain\Enrollment\Services\EnrollmentCapacity;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\VisitOfferFixture;
use Tests\TestCase;

class GroupCapacityBatchTest extends TestCase
{
    use RefreshDatabase, VisitOfferFixture;

    public function test_query_count_is_constant_for_three_and_thirty_sessions_in_both_modes(): void
    {
        [, , $group, $first, , $child] = $this->visitFixture([], ['capacity_max' => 10]);
        $this->visitEnrollment($child, $group, null, 'full_period', startsOn: '2026-11-01');
        $this->visitEnrollment($child, $group, $first, 'single');
        foreach (range(1, 2) as $offset) {
            $this->addSession($group, $first->starts_at->addWeeks($offset));
        }
        $small = [];
        foreach ([false, true] as $allocation) {
            $small[] = $this->queryCount($group, $allocation);
        }
        foreach (range(3, 29) as $offset) {
            $this->addSession($group, $first->starts_at->addWeeks($offset));
        }
        foreach ([false, true] as $index => $allocation) {
            $large = $this->queryCount($group, $allocation);
            $this->assertSame($small[$index], $large, 'Queries must not grow with the number of sessions.');
            $this->assertLessThanOrEqual(10, $large);
        }
    }

    public function test_fullest_session_preserves_pauses_skips_visits_and_held_future_places(): void
    {
        [, , $group, $first, , $child] = $this->visitFixture([], ['capacity_max' => 10], ['capacity' => 4]);
        $second = $this->addSession($group, $first->starts_at->addWeek(), 8);
        $this->visitEnrollment($child, $group, null, 'full_period', startsOn: '2026-11-01');
        $paused = $this->visitEnrollment($child, $group, null, 'full_period', status: 'paused', startsOn: '2026-11-01');
        $paused->pauses()->create(['starts_on' => '2026-11-03', 'ends_on' => '2026-11-03', 'reason_category' => 'other']);
        $skipping = $this->visitEnrollment($child, $group, null, 'full_period', startsOn: '2026-11-01');
        $skipping->sessionExceptions()->create(['session_id' => $first->id, 'kind' => 'excluded']);
        $this->visitEnrollment($child, $group, null, 'full_period', startsOn: '2026-12-01');
        $ended = $this->visitEnrollment($child, $group, null, 'full_period', status: 'ended', startsOn: '2026-11-01');
        $ended->update(['ends_on' => '2026-11-03']);
        $this->visitEnrollment($child, $group, null, 'full_period', status: 'cancelled', startsOn: '2026-11-01');
        $this->visitEnrollment($child, $group, $first, 'single');
        $this->visitEnrollment($child, $group, $second, 'trial');
        $this->addSession($group, CarbonImmutable::parse('2026-11-01T10:00:00Z'), 0);
        $cancelled = $this->addSession($group, $first->starts_at->addDays(2), 0);
        $cancelled->update(['status' => 'cancelled']);

        foreach ([false, true] as $allocation) {
            $this->assertSame(1, app(EnrollmentCapacity::class)->remainingForGroup($group, CarbonImmutable::parse('2026-11-02'), $allocation));
        }
        // Without a finite session limit, all five full-period places are still held, including the future start.
        $first->update(['capacity' => null]);
        $second->update(['capacity' => null]);
        foreach ([false, true] as $allocation) {
            $this->assertSame(5, app(EnrollmentCapacity::class)->remainingForGroup($group, CarbonImmutable::parse('2026-11-02'), $allocation));
        }
    }

    public function test_unlimited_group_uses_session_limit_and_can_be_unlimited_or_overbooked(): void
    {
        [, , $group, $session, , $child] = $this->visitFixture([], ['capacity_max' => null], ['capacity' => 2]);
        $this->visitEnrollment($child, $group, null, 'full_period', startsOn: '2026-11-01');
        foreach ([false, true] as $allocation) {
            $this->assertSame(1, app(EnrollmentCapacity::class)->remainingForGroup($group, CarbonImmutable::parse('2026-11-02'), $allocation));
        }
        $session->update(['capacity' => 0]);
        $this->assertSame(0, app(EnrollmentCapacity::class)->remainingForGroup($group, CarbonImmutable::parse('2026-11-02')));
        $session->update(['capacity' => null]);
        foreach ([false, true] as $allocation) {
            $this->assertNull(app(EnrollmentCapacity::class)->remainingForGroup($group, CarbonImmutable::parse('2026-11-02'), $allocation));
        }
        $session->update(['status' => 'cancelled']);
        $this->assertNull(app(EnrollmentCapacity::class)->remainingForGroup($group, CarbonImmutable::parse('2026-11-02')));
        $group->update(['capacity_max' => 5]);
        $this->assertSame(4, app(EnrollmentCapacity::class)->remainingForGroup($group, CarbonImmutable::parse('2026-11-02')));
    }

    public function test_local_enrollment_day_is_preserved_across_midnight_and_the_clock_change(): void
    {
        foreach (['2026-10-24T21:30:00Z', '2026-10-25T22:30:00Z', '2026-11-02T22:30:00Z'] as $start) {
            $time = CarbonImmutable::parse($start);
            [, , $group, $session, , $child] = $this->visitFixture([], ['capacity_max' => null], [
                'starts_at' => $time, 'ends_at' => $time->addHour(), 'capacity' => 2,
            ]);
            $day = $time->setTimezone('Europe/Sofia')->toDateString();
            $enrollment = $this->visitEnrollment($child, $group, null, 'full_period', status: 'ended', startsOn: $day);
            $enrollment->update(['ends_on' => $day]);
            $this->addSession($group, $session->starts_at->addDay(), 3);
            foreach ([false, true] as $allocation) {
                $this->assertSame(1, app(EnrollmentCapacity::class)->remainingForGroup($group, $time->subHour(), $allocation));
            }
        }
    }

    private function addSession(Group $group, CarbonImmutable $startsAt, ?int $capacity = null): Session
    {
        return $group->sessions()->create([
            'starts_at' => $startsAt, 'ends_at' => $startsAt->addHour(), 'status' => 'scheduled', 'capacity' => $capacity,
        ]);
    }

    private function queryCount(Group $group, bool $allocation): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $this->assertSame(8, app(EnrollmentCapacity::class)->remainingForGroup($group, CarbonImmutable::parse('2026-11-02'), $allocation));

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }
}
