<?php

namespace Tests\Unit\Catalog;

use App\Domain\Catalog\Services\SessionScheduleGenerator;
use App\Domain\Catalog\ValueObjects\SchedulePattern;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SessionScheduleGeneratorTest extends TestCase
{
    public function test_weekdays_have_independent_times_and_inclusive_period_boundaries(): void
    {
        $pattern = new SchedulePattern('2026-10-27', '2026-11-05', 'weekly', [
            ['weekday' => 2, 'starts_at' => '17:00', 'duration_minutes' => 60],
            ['weekday' => 4, 'starts_at' => '18:30', 'duration_minutes' => 90],
        ]);
        $sessions = (new SessionScheduleGenerator)->generate($pattern, [], 'Europe/Sofia');
        $this->assertSame(['2026-10-27 17:00', '2026-10-29 18:30', '2026-11-03 17:00', '2026-11-05 18:30'], array_map(fn ($s) => $s['starts_at']->format('Y-m-d H:i'), $sessions));
        $this->assertSame('2026-10-29 20:00', $sessions[1]['ends_at']->format('Y-m-d H:i'));
    }

    public function test_biweekly_uses_iso_week_containing_start_as_anchor(): void
    {
        $pattern = new SchedulePattern('2026-10-28', '2026-11-17', 'biweekly', [
            ['weekday' => 2, 'starts_at' => '17:00', 'duration_minutes' => 60],
            ['weekday' => 4, 'starts_at' => '18:00', 'duration_minutes' => 60],
        ]);
        $sessions = (new SessionScheduleGenerator)->generate($pattern, [], 'Europe/Sofia');
        $this->assertSame(['2026-10-29', '2026-11-10', '2026-11-12'], array_map(fn ($s) => $s['starts_at']->format('Y-m-d'), $sessions));
    }

    public function test_exclusions_win_and_manual_additions_are_sorted_and_deduplicated(): void
    {
        $pattern = new SchedulePattern('2026-10-27', '2026-11-03', 'weekly', [
            ['weekday' => 2, 'starts_at' => '17:00', 'duration_minutes' => 60],
        ], [
            ['date' => '2026-10-30', 'starts_at' => '16:00', 'duration_minutes' => 90],
            ['date' => '2026-11-03', 'starts_at' => '17:00', 'duration_minutes' => 60],
            ['date' => '2026-10-27', 'starts_at' => '17:00', 'duration_minutes' => 60],
        ]);
        $sessions = (new SessionScheduleGenerator)->generate($pattern, ['2026-10-27'], 'Europe/Sofia');
        $this->assertSame(['2026-10-30 16:00', '2026-11-03 17:00'], array_map(fn ($s) => $s['starts_at']->format('Y-m-d H:i'), $sessions));
    }

    public function test_sofia_wall_clock_is_preserved_across_both_dst_changes(): void
    {
        foreach ([['2026-03-22', '2026-03-29', '+02:00', '+03:00'], ['2026-10-18', '2026-10-25', '+03:00', '+02:00']] as [$start, $end, $before, $after]) {
            $pattern = new SchedulePattern($start, $end, 'weekly', [
                ['weekday' => 7, 'starts_at' => '17:00', 'duration_minutes' => 60],
            ]);
            $sessions = (new SessionScheduleGenerator)->generate($pattern, [], 'Europe/Sofia');
            $this->assertSame('17:00'.$before, $sessions[0]['starts_at']->format('H:iP'));
            $this->assertSame('17:00'.$after, $sessions[1]['starts_at']->format('H:iP'));
        }
    }

    public function test_nonexistent_dst_local_time_is_rejected_instead_of_silently_moved(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SessionScheduleGenerator)->generate(new SchedulePattern('2026-03-29', '2026-03-29', 'weekly', [
            ['weekday' => 7, 'starts_at' => '03:30', 'duration_minutes' => 60],
        ]), [], 'Europe/Sofia');
    }

    public function test_invalid_dates_and_zero_durations_are_rejected(): void
    {
        foreach ([['2026-02-30', '2026-03-03', 60], ['2026-03-03', '2026-03-02', 60], ['2026-03-03', '2026-03-04', 0]] as [$start, $end, $duration]) {
            try {
                new SchedulePattern($start, $end, 'weekly', [['weekday' => 2, 'starts_at' => '17:00', 'duration_minutes' => $duration]]);
                $this->fail('Invalid pattern was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_repeated_autumn_time_uses_first_occurrence_and_duration_is_elapsed_time(): void
    {
        $sessions = (new SessionScheduleGenerator)->generate(new SchedulePattern('2026-10-25', '2026-10-25', 'weekly', [
            ['weekday' => 7, 'starts_at' => '03:30', 'duration_minutes' => 60],
        ]), [], 'Europe/Sofia');
        $this->assertSame('2026-10-25T03:30:00+03:00', $sessions[0]['starts_at']->toIso8601String());
        $this->assertSame('2026-10-25T03:30:00+02:00', $sessions[0]['ends_at']->toIso8601String());
    }
}
