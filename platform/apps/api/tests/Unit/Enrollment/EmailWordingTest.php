<?php

namespace Tests\Unit\Enrollment;

use App\Domain\Enrollment\Delivery\Branding;
use App\Domain\Enrollment\Delivery\BulgarianDates;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class EmailWordingTest extends TestCase
{
    public function test_dates_and_weekly_slots_read_as_people_write_them(): void
    {
        $this->assertSame('13 януари', BulgarianDates::day('2027-01-13'));
        $this->assertSame('сряда, 13 януари', BulgarianDates::weekdayDay('2027-01-13'));
        $this->assertSame('сряда, 13 януари · 17:30–18:30', BulgarianDates::session(
            CarbonImmutable::parse('2027-01-13T15:30:00Z'), CarbonImmutable::parse('2027-01-13T16:30:00Z'), 'Europe/Sofia',
        ));
        $slot = fn (int $weekday, string $at = '17:30', int $minutes = 60): array => ['weekday' => $weekday, 'starts_at' => $at, 'duration_minutes' => $minutes];
        $this->assertSame('Всяка сряда · 17:30–18:30', BulgarianDates::weekly(['frequency' => 'weekly', 'slots' => [$slot(3)]]));
        $this->assertSame('През седмица: събота · 10:00–11:30', BulgarianDates::weekly(['frequency' => 'biweekly', 'slots' => [$slot(6, '10:00', 90)]]));
        $this->assertSame('', BulgarianDates::weekly(null));
    }

    public function test_days_with_the_same_time_are_said_once_and_every_day_agrees_with_its_gender(): void
    {
        $slot = fn (int $weekday, string $at = '17:30', int $minutes = 60): array => ['weekday' => $weekday, 'starts_at' => $at, 'duration_minutes' => $minutes];
        $weekly = fn (array ...$slots): string => BulgarianDates::weekly(['frequency' => 'weekly', 'slots' => array_values($slots)]);

        $this->assertSame('Всеки вторник и четвъртък · 16:00–16:45', $weekly($slot(2, '16:00', 45), $slot(4, '16:00', 45)));
        $this->assertSame('Всяка сряда и всеки петък · 17:30–18:30', $weekly($slot(3), $slot(5)));
        $this->assertSame('Всяка събота и неделя · 10:00–11:30', $weekly($slot(6, '10:00', 90), $slot(7, '10:00', 90)));
        $this->assertSame('Всеки понеделник, всяка сряда и всеки петък · 17:30–18:30', $weekly($slot(1), $slot(3), $slot(5)));
        // Different times stay apart, each with its own word.
        $this->assertSame('Всеки понеделник · 16:00–17:00, всяка сряда · 17:30–18:30', $weekly($slot(1, '16:00'), $slot(3)));
        $this->assertSame('През седмица: вторник и четвъртък · 16:00–16:45', BulgarianDates::weekly(['frequency' => 'biweekly', 'slots' => [$slot(2, '16:00', 45), $slot(4, '16:00', 45)]]));
    }

    public function test_a_period_within_one_month_is_written_short(): void
    {
        $this->assertSame('3–10 ноември', BulgarianDates::period('2026-11-03', '2026-11-10'));
        $this->assertSame('30 ноември – 6 декември', BulgarianDates::period('2026-11-30', '2026-12-06'));
        $this->assertSame('3 ноември', BulgarianDates::period('2026-11-03', '2026-11-03'));
    }

    public function test_text_on_the_organizations_colour_stays_readable(): void
    {
        $this->assertSame('#FFFFFF', Branding::readableOn('#216653'));
        $this->assertSame('#FFFFFF', Branding::readableOn('#173F5F'));
        // A light colour gets dark text.
        $this->assertSame('#173F5F', Branding::readableOn('#F2D649'));
        $this->assertSame('#173F5F', Branding::readableOn('#FFFFFF'));
        $this->assertSame('PA', Branding::initials('Progressive Academy Demo'));
        $this->assertSame('ШЗ', Branding::initials('Школа „Звезда“'));
        $this->assertSame('•', Branding::initials('  '));
    }

    public function test_a_colour_that_is_not_a_colour_falls_back_to_the_platform(): void
    {
        $brand = Branding::forOrganization('Клуб', null, 'http://localhost:3000', true);
        $this->assertSame(Branding::PLATFORM, $brand->accent);
        $this->assertNull($brand->logoUrl);
    }
}
