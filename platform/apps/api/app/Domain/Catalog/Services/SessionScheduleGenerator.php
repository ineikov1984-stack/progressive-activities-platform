<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\ValueObjects\SchedulePattern;
use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class SessionScheduleGenerator
{
    /**
     * @param  list<string>  $excludedDates
     * @return list<array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}>
     */
    public function generate(SchedulePattern $pattern, array $excludedDates, string $timezone): array
    {
        new DateTimeZone($timezone);
        foreach ($excludedDates as $date) {
            SchedulePattern::validateDate($date);
        }
        $excluded = array_fill_keys($excludedDates, true);
        $sessions = [];
        // Calendar iteration in UTC prevents DST from changing weekday/week arithmetic.
        $start = CarbonImmutable::parse($pattern->startDate, 'UTC')->startOfDay();
        $end = CarbonImmutable::parse($pattern->endDate, 'UTC')->startOfDay();
        $anchor = $start->startOfWeek();
        for ($date = $start; $date <= $end; $date = $date->addDay()) {
            $week = intdiv((int) $anchor->diffInDays($date), 7);
            if ($pattern->frequency === 'biweekly' && $week % 2 !== 0) {
                continue;
            }
            foreach ($pattern->slots as $slot) {
                if ($slot['weekday'] === $date->isoWeekday()) {
                    $this->append($sessions, $excluded, $date->format('Y-m-d'), $slot, $timezone);
                }
            }
        }
        foreach ($pattern->addedDates as $slot) {
            $this->append($sessions, $excluded, $slot['date'], $slot, $timezone);
        }
        ksort($sessions);

        return array_values($sessions);
    }

    /**
     * @param  array<string, array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}>  $sessions
     * @param  array<string, bool>  $excluded
     * @param  array{starts_at: string, duration_minutes: int}  $slot
     */
    private function append(array &$sessions, array $excluded, string $date, array $slot, string $timezone): void
    {
        if (isset($excluded[$date])) {
            return;
        }
        $local = $date.' '.$slot['starts_at'];
        $start = CarbonImmutable::createFromFormat('!Y-m-d H:i', $local, $timezone);
        if (! $start || $start->format('Y-m-d H:i') !== $local) {
            throw new InvalidArgumentException('Началният час не съществува при смяната на часовото време.');
        }
        // An autumn wall time can occur twice. Always choose the earlier instant.
        $wallTimestamp = CarbonImmutable::parse($local, 'UTC')->getTimestamp();
        $transitions = (new DateTimeZone($timezone))->getTransitions($wallTimestamp - 86400, $wallTimestamp + 86400);
        foreach ($transitions ?: [] as $transition) {
            $candidate = CarbonImmutable::createFromTimestampUTC($wallTimestamp - $transition['offset'])->setTimezone($timezone);
            if ($candidate->format('Y-m-d H:i') === $local && $candidate < $start) {
                $start = $candidate;
            }
        }
        // Duration is elapsed time, including on days when the clock changes.
        $end = $start->utc()->addMinutes($slot['duration_minutes'])->setTimezone($timezone);
        if (isset($sessions[$local]) && ! $sessions[$local]['ends_at']->equalTo($end)) {
            throw new InvalidArgumentException('Еднакви начални часове имат различна продължителност.');
        }
        $sessions[$local] = ['starts_at' => $start, 'ends_at' => $end];
    }
}
