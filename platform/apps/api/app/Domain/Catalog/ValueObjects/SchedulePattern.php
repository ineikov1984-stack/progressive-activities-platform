<?php

namespace App\Domain\Catalog\ValueObjects;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class SchedulePattern
{
    /**
     * @param  list<array{weekday: int, starts_at: string, duration_minutes: int}>  $slots
     * @param  list<array{date: string, starts_at: string, duration_minutes: int}>  $addedDates
     */
    public function __construct(
        public string $startDate,
        public string $endDate,
        public string $frequency,
        public array $slots,
        public array $addedDates = [],
    ) {
        self::validateDate($startDate);
        self::validateDate($endDate);
        if ($endDate < $startDate || ! in_array($frequency, ['weekly', 'biweekly'], true) || $slots === []) {
            throw new InvalidArgumentException('Невалиден период или честота на графика.');
        }
        if ((new DateTimeImmutable($startDate))->diff(new DateTimeImmutable($endDate))->days > 1830 || count($slots) > 28 || count($addedDates) > 366) {
            throw new InvalidArgumentException('Графикът надвишава допустимия размер.');
        }
        foreach ($slots as $slot) {
            self::validateWeekday($slot['weekday']);
            self::validateTime($slot);
        }
        foreach ($addedDates as $slot) {
            self::validateDate($slot['date']);
            if ($slot['date'] < $startDate || $slot['date'] > $endDate) {
                throw new InvalidArgumentException('Добавената дата трябва да е в периода.');
            }
            self::validateTime($slot);
        }
    }

    public static function validateDate(string $date): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        if (! $parsed || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Използвайте валидна дата във формат YYYY-MM-DD.');
        }
    }

    private static function validateWeekday(mixed $weekday): void
    {
        if (! is_int($weekday) || $weekday < 1 || $weekday > 7) {
            throw new InvalidArgumentException('Денят трябва да бъде между 1 и 7.');
        }
    }

    /** @param array<string, mixed> $slot */
    private static function validateTime(array $slot): void
    {
        if (! preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $slot['starts_at'])
            || ! is_int($slot['duration_minutes']) || $slot['duration_minutes'] < 1 || $slot['duration_minutes'] > 1440) {
            throw new InvalidArgumentException('Посочете валиден час и продължителност от 1 до 1440 минути.');
        }
    }
}
