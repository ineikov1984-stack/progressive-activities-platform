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
        $this->assertSame('Всеки вторник · 16:00–16:45, четвъртък · 16:00–16:45', BulgarianDates::weekly(['frequency' => 'weekly', 'slots' => [$slot(2, '16:00', 45), $slot(4, '16:00', 45)]]));
        $this->assertSame('През седмица: събота · 10:00–11:30', BulgarianDates::weekly(['frequency' => 'biweekly', 'slots' => [$slot(6, '10:00', 90)]]));
        $this->assertSame('', BulgarianDates::weekly(null));
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
