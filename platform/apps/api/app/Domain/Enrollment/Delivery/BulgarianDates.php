<?php

namespace App\Domain\Enrollment\Delivery;

use Carbon\CarbonImmutable;

/** Dates and weekly slots as people write them in Bulgarian ("сряда, 13 януари · 17:30–18:30"). */
final class BulgarianDates
{
    private const WEEKDAYS = [1 => 'понеделник', 2 => 'вторник', 3 => 'сряда', 4 => 'четвъртък', 5 => 'петък', 6 => 'събота', 7 => 'неделя'];

    private const MONTHS = [1 => 'януари', 2 => 'февруари', 3 => 'март', 4 => 'април', 5 => 'май', 6 => 'юни', 7 => 'юли', 8 => 'август', 9 => 'септември', 10 => 'октомври', 11 => 'ноември', 12 => 'декември'];

    /** "13 януари" for a calendar day. */
    public static function day(string $date): string
    {
        $day = CarbonImmutable::parse($date);

        return $day->day.' '.self::MONTHS[$day->month];
    }

    /** "сряда, 13 януари" for a calendar day. */
    public static function weekdayDay(string $date): string
    {
        $day = CarbonImmutable::parse($date);

        return self::WEEKDAYS[$day->dayOfWeekIso].', '.self::day($date);
    }

    /** "сряда, 13 януари · 17:30–18:30" for a session, in the group's time zone. */
    public static function session(CarbonImmutable $startsAt, ?CarbonImmutable $endsAt, string $timezone): string
    {
        $start = $startsAt->setTimezone($timezone);
        $time = $start->format('H:i').($endsAt === null ? '' : '–'.$endsAt->setTimezone($timezone)->format('H:i'));

        return self::weekdayDay($start->toDateString()).' · '.$time;
    }

    /**
     * "Всяка сряда · 17:30–18:30"; "През седмица в събота · 10:00–11:30"; '' without slots.
     *
     * @param  array{frequency: string, slots: list<array{weekday: int, starts_at: string, duration_minutes: int}>}|null  $schedule
     */
    public static function weekly(?array $schedule): string
    {
        if ($schedule === null || $schedule['slots'] === []) {
            return '';
        }
        $parts = array_map(function (array $slot): string {
            $start = CarbonImmutable::createFromFormat('H:i', $slot['starts_at']) ?: CarbonImmutable::now()->startOfDay();
            $time = $slot['duration_minutes'] > 0 ? $slot['starts_at'].'–'.$start->addMinutes($slot['duration_minutes'])->format('H:i') : $slot['starts_at'];

            return (self::WEEKDAYS[$slot['weekday']] ?? 'ден').' · '.$time;
        }, $schedule['slots']);

        $text = implode(', ', $parts);
        if ($schedule['frequency'] === 'biweekly') {
            return 'През седмица: '.$text;
        }
        // "Всяка сряда", "Всеки вторник": the word agrees with the first day.
        $feminine = in_array($schedule['slots'][0]['weekday'], [3, 6, 7], true);

        return ($feminine ? 'Всяка ' : 'Всеки ').$text;
    }
}
