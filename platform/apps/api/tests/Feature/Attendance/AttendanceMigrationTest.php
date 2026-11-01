<?php

namespace Tests\Feature\Attendance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\VisitOfferFixture;
use Tests\TestCase;

class AttendanceMigrationTest extends TestCase
{
    use RefreshDatabase, VisitOfferFixture;

    private const MIGRATION = 'database/migrations/2026_11_01_075042_create_attendance_tables.php';

    public function test_the_step_is_reversible_when_empty_and_refuses_to_drop_attendance_data(): void
    {
        $this->assertTrue(Schema::hasTable('attendance_marks'));
        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--force' => true]);
        foreach (['attendance_marks', 'attendance_mark_changes', 'attendance_sessions', 'attendance_session_changes'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        Artisan::call('migrate', ['--path' => self::MIGRATION, '--force' => true]);
        foreach (['attendance_marks', 'attendance_mark_changes', 'attendance_sessions', 'attendance_session_changes'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        [, , $group, $session, , $child] = $this->visitFixture();
        $enrollment = $this->visitEnrollment($child, $group, null, 'full_period');
        DB::table('attendance_marks')->insert([
            'id' => (string) Str::uuid(), 'organization_id' => $group->organization_id,
            'session_id' => $session->id, 'enrollment_id' => $enrollment->id, 'status' => 'present', 'version' => 1,
            'marked_by' => $child->owner_user_id, 'marked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--force' => true]);
            $this->fail('A populated attendance migration must never roll back silently.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Attendance data', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasTable('attendance_marks'));
    }
}
