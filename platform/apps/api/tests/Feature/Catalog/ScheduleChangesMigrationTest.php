<?php

namespace Tests\Feature\Catalog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * The step of the changes of the schedule (docs/product/schedule-changes.md, т. 3.3а, 3.4, 3.5): a continuation after a
 * transfer gets the day it may not start before, once — from the event of the transfer, else the day after the old
 * enrollment's last day, else its own start, never after the start. Back only without history.
 */
class ScheduleChangesMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** Only this step goes back and forth, whatever migrations come after it. */
    private const MIGRATION = 'database/migrations/2026_10_31_182000_create_schedule_changes.php';

    private string $organization;

    private string $parent;

    private string $group;

    private string $price;

    public function test_continuations_get_their_day_once_and_the_step_goes_back_only_without_history(): void
    {
        // The migrator keeps the migration it has loaded; requiring the file again would declare its class twice.
        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--force' => true]);
        $this->assertFalse(Schema::hasTable('schedule_changes'));
        $this->assertFalse(Schema::hasColumn('enrollments', 'starts_not_before'));
        $this->assertFalse(Schema::hasColumn('activity_sessions', 'original_starts_at'));
        $this->seedTenant();

        // The event of the transfer says the day chosen.
        $fromEvent = $this->continuation('ended', '2026-11-09', '2026-11-12', '2026-11-10');
        // No event: the day after the old enrollment's last day.
        $afterEnd = $this->continuation('ended', '2026-11-04', '2026-11-06', null);
        // No event and the old one cancelled before it began: the start itself.
        $ownStart = $this->continuation('cancelled', null, '2026-11-12', null);
        // Never after the start.
        $bounded = $this->continuation('ended', '2026-11-09', '2026-11-12', '2026-11-20');
        $plain = $this->enrollment('2026-11-03', 'active', null, null);

        Artisan::call('migrate', ['--force' => true]);
        $this->assertTrue(Schema::hasTable('schedule_changes'));
        $this->assertTrue(Schema::hasTable('session_changes'));
        $notBefore = fn (string $id): ?string => ($value = DB::table('enrollments')->where('id', $id)->value('starts_not_before')) === null ? null : substr((string) $value, 0, 10);
        $this->assertSame('2026-11-10', $notBefore($fromEvent));
        $this->assertSame('2026-11-05', $notBefore($afterEnd));
        $this->assertSame('2026-11-12', $notBefore($ownStart));
        $this->assertSame('2026-11-12', $notBefore($bounded));
        $this->assertNull($notBefore($plain));
        // Existing sessions are neither moved nor added.
        $session = $this->freshSessionId();
        $this->assertSame([null, 0], [DB::table('activity_sessions')->where('id', $session)->value('original_starts_at'),
            (int) DB::table('activity_sessions')->where('id', $session)->value('extra')]);

        // Back: refused while the schedule has history or a moved or added session.
        $change = (string) Str::uuid();
        DB::table('schedule_changes')->insert([
            'id' => $change, 'organization_id' => $this->organization, 'action' => 'cancel', 'reason' => 'Ремонт',
            'reason_to_parents' => false, 'notify_parents' => true, 'session_count' => 1, 'idempotency_key' => 'k',
            'request_hash' => str_repeat('a', 64), 'result' => '{}', 'actor_user_id' => $this->parent, 'created_at' => now(),
        ]);
        $this->refusedBack();
        DB::table('schedule_changes')->where('id', $change)->delete();
        DB::table('activity_sessions')->where('id', $session)->update(['extra' => true]);
        $this->refusedBack();
        DB::table('activity_sessions')->where('id', $session)->update(['extra' => false]);

        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--force' => true]);
        $this->assertFalse(Schema::hasTable('session_changes'));
        $this->assertFalse(Schema::hasColumn('enrollments', 'starts_not_before'));
        $this->assertFalse(Schema::hasColumn('activity_sessions', 'extra'));
        Artisan::call('migrate', ['--force' => true]);
        $this->assertSame('2026-11-10', $notBefore($fromEvent));
    }

    private function refusedBack(): void
    {
        try {
            Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--force' => true]);
            $this->fail('The history of the schedule must not be dropped quietly.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Changes of the schedule are in use', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasTable('schedule_changes'));
    }

    private function seedTenant(): void
    {
        $now = now();
        $this->organization = (string) Str::uuid();
        DB::table('organizations')->insert(['id' => $this->organization, 'name' => 'Школа', 'slug' => 'school-'.Str::random(6), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $this->parent = (string) Str::uuid();
        DB::table('users')->insert(['id' => $this->parent, 'name' => 'Мария Иванова', 'email' => Str::random(8).'@example.test', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now]);
        $activity = (string) Str::uuid();
        DB::table('activities')->insert(['id' => $activity, 'organization_id' => $this->organization, 'title' => 'Шах', 'slug' => 'chess-'.Str::random(6), 'category' => 'Игри', 'summary' => 'Шах', 'age_label' => '7+', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now]);
        $offering = (string) Str::uuid();
        DB::table('offerings')->insert(['id' => $offering, 'organization_id' => $this->organization, 'activity_id' => $activity, 'created_at' => $now, 'updated_at' => $now]);
        $this->group = (string) Str::uuid();
        DB::table('activity_groups')->insert(['id' => $this->group, 'organization_id' => $this->organization, 'offering_id' => $offering, 'name' => 'Вторник', 'capacity_min' => 1, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now]);
        $this->price = (string) Str::uuid();
        DB::table('price_options')->insert(['id' => $this->price, 'organization_id' => $this->organization, 'offering_id' => $offering, 'enrollment_mode' => 'monthly', 'unit' => 'month', 'amount_cents' => 4500, 'currency' => 'EUR', 'created_at' => $now, 'updated_at' => $now]);
    }

    private function continuation(string $oldStatus, ?string $oldEnds, string $startsOn, ?string $effectiveDate): string
    {
        $old = $this->enrollment('2026-10-01', $oldStatus, $oldEnds, null);
        $new = $this->enrollment($startsOn, 'active', null, $old);
        if ($effectiveDate !== null) {
            DB::table('enrollment_events')->insert([
                'id' => (string) Str::uuid(), 'organization_id' => $this->organization, 'type' => 'enrollment.transferred',
                'application_id' => DB::table('enrollments')->where('id', $new)->value('application_id'), 'enrollment_id' => $new,
                'payload' => json_encode(['transferred_from_id' => $old, 'effective_date' => $effectiveDate]),
                'occurred_at' => now(), 'expanded_at' => now(), 'attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $new;
    }

    private function enrollment(string $startsOn, string $status, ?string $endsOn, ?string $transferredFrom): string
    {
        $now = now();
        $child = (string) Str::uuid();
        DB::table('children')->insert(['id' => $child, 'owner_user_id' => $this->parent, 'first_name' => 'Ани', 'last_name' => 'Иванова', 'status' => 'active', 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $application = (string) Str::uuid();
        DB::table('enrollment_applications')->insert([
            'id' => $application, 'organization_id' => $this->organization, 'parent_user_id' => $this->parent, 'child_id' => $child,
            'group_id' => $this->group, 'price_option_id' => $this->price, 'scope' => 'full_period', 'source' => 'staff', 'status' => 'approved',
            'idempotency_key' => (string) Str::uuid(), 'accepted_terms_version' => 'v1', 'accepted_terms_at' => $now,
            'amount_cents_snapshot' => 4500, 'currency_snapshot' => 'EUR', 'unit_snapshot' => 'month', 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $id = (string) Str::uuid();
        DB::table('enrollments')->insert([
            'id' => $id, 'organization_id' => $this->organization, 'application_id' => $application, 'child_id' => $child,
            'group_id' => $this->group, 'price_option_id' => $this->price, 'scope' => 'full_period', 'status' => $status,
            'starts_on' => $startsOn, 'ends_on' => $endsOn, 'transferred_from_id' => $transferredFrom, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $id;
    }

    private function freshSessionId(): string
    {
        $id = (string) Str::uuid();
        DB::table('activity_sessions')->insert([
            'id' => $id, 'organization_id' => $this->organization, 'group_id' => $this->group, 'starts_at' => '2026-11-03 15:00:00',
            'ends_at' => '2026-11-03 16:00:00', 'status' => 'scheduled', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
