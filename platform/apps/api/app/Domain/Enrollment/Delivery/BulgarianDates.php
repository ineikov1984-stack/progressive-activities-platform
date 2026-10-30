<?php

namespace App\Domain\Enrollment\Delivery;

use Carbon\CarbonImmutable;

/** Dates and weekly slots as people write them in Bulgarian ("сряда, 13 януари · 17:30–18:30"). */
final class BulgarianDates
{
    private const WEEKDAYS = [1 => 'понеделник', 2 => 'вторник', 3 => 'сряда', 4 => 'четвъртък', 5 => 'петък', 6 => 'събота', 7 => 'неделя'];

    private const MONTHS = [1 => 'януари', 2 => 'февруари', 3 => 'март', 4 => 'април', 5 => 'май', 6 => 'юни', 7 => 'юли', 8 => 'август', 9 => 'септември', 10 => 'октомври', 11 => 'ноември', 12 => 'декември'];

    /** Сряда, събота and неделя are feminine: "всяка сряда", but "всеки вторник". */
    private const FEMININE_WEEKDAYS = [3, 6, 7];

    /** "13 януари" for a calendar day. */
    public static function day(string $date): string
    {
        $day = CarbonImmutable::parse($date);

        return $day->day.' '.self::MONTHS[$day->month];
    }

    /** "3–10 ноември" within one month; "30 ноември – 6 декември" across months; "3 ноември" for one day. */
    public static function period(string $from, string $to): string
    {
        $start = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);
        if ($start->isSameDay($end)) {
            return self::day($from);
        }
        if ($start->isSameMonth($end)) {
            return $start->day.'–'.$end->day.' '.self::MONTHS[$end->month];
        }

        return self::day($from).' – '.self::day($to);
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
     * "Всяка сряда · 17:30–18:30"; "Всеки вторник и четвъртък · 16:00–17:00"; "Всяка сряда и всеки петък · 17:30–18:30";
     * "Всеки понеделник · 16:00–17:00, всяка сряда · 17:30–18:30"; "През седмица: събота · 10:00–11:30"; '' without slots.
     * Days that share a time are said once, and "всеки/всяка" agrees with each day.
     *
     * @param  array{frequency: string, slots: list<array{weekday: int, starts_at: string, duration_minutes: int}>}|null  $schedule
     */
    public static function weekly(?array $schedule): string
    {
        if ($schedule === null || $schedule['slots'] === []) {
            return '';
        }
        /** @var array<string, list<int>> $byTime */
        $byTime = [];
        foreach ($schedule['slots'] as $slot) {
            $start = CarbonImmutable::createFromFormat('H:i', $slot['starts_at']) ?: CarbonImmutable::now()->startOfDay();
            $time = $slot['duration_minutes'] > 0 ? $slot['starts_at'].'–'.$start->addMinutes($slot['duration_minutes'])->format('H:i') : $slot['starts_at'];
            $byTime[$time][] = $slot['weekday'];
        }

        $biweekly = $schedule['frequency'] === 'biweekly';
        $parts = [];
        foreach ($byTime as $time => $weekdays) {
            $days = $biweekly
                ? self::listed(array_map(fn (int $weekday): string => self::WEEKDAYS[$weekday] ?? 'ден', $weekdays))
                : self::everyDay($weekdays);
            $parts[] = $days.' · '.$time;
        }
        $text = implode(', ', $parts);
        if ($biweekly) {
            return 'През седмица: '.$text;
        }

        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    /**
     * "всеки вторник и четвъртък", "всяка сряда и всеки петък": the word is said again when the gender changes.
     *
     * @param  list<int>  $weekdays
     */
    private static function everyDay(array $weekdays): string
    {
        $words = [];
        $previous = null;
        foreach ($weekdays as $weekday) {
            $feminine = in_array($weekday, self::FEMININE_WEEKDAYS, true);
            $day = self::WEEKDAYS[$weekday] ?? 'ден';
            $words[] = $previous === $feminine ? $day : ($feminine ? 'всяка ' : 'всеки ').$day;
            $previous = $feminine;
        }

        return self::listed($words);
    }

    /**
     * "вторник", "вторник и четвъртък", "понеделник, сряда и петък".
     *
     * @param  list<string>  $words
     */
    private static function listed(array $words): string
    {
        $last = array_pop($words);

        return $words === [] ? (string) $last : implode(', ', $words).' и '.$last;
    }
}
