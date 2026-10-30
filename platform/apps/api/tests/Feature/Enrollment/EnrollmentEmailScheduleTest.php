<?php

namespace Tests\Feature\Enrollment;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * The `worker` container (platform/compose.yaml) runs `schedule:work`; this is the schedule it follows
 * (ADR 0005).
 */
class EnrollmentEmailScheduleTest extends TestCase
{
    public function test_enrollment_emails_go_out_every_minute_one_run_at_a_time(): void
    {
        $events = array_values(array_filter(
            app(Schedule::class)->events(),
            fn (Event $event): bool => str_contains((string) $event->command, 'enrollment:deliver-events'),
        ));

        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events[0]->expression);
        $this->assertTrue($events[0]->withoutOverlapping);
        // A run that was killed must not hold the emails back for long.
        $this->assertSame(10, $events[0]->expiresAt);
        $this->assertTrue($events[0]->onOneServer);
    }
}
