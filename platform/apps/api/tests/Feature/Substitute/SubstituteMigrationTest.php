<?php

namespace Tests\Feature\Substitute;

use App\Domain\Catalog\Models\InstructorProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\VisitOfferFixture;
use Tests\TestCase;

class SubstituteMigrationTest extends TestCase
{
    use RefreshDatabase, VisitOfferFixture;

    public function test_empty_rollback_and_populated_refusal(): void
    {
        $this->assertTrue(Schema::hasTable('substitute_requests'));
        $path = 'database/migrations/2026_11_01_115259_create_substitute_requests.php';
        Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]);
        $this->assertFalse(Schema::hasTable('substitute_requests'));
        $this->assertFalse(Schema::hasColumn('activity_session_instructors', 'replaces_instructor_profile_id'));
        Artisan::call('migrate', ['--path' => $path, '--force' => true]);
        $this->assertTrue(Schema::hasTable('substitute_request_sessions'));
        foreach (['replaced_profile_id', 'removed_profile_id', 'added_profile_id'] as $column) {
            $this->assertTrue(Schema::hasColumn('session_changes', $column));
        }
        [$organization, , , , , $child] = $this->visitFixture();
        $profile = InstructorProfile::create(['public_name' => 'Teacher']);
        DB::table('substitute_requests')->insert(['id' => (string) Str::uuid(), 'organization_id' => $organization->id,
            'requester_user_id' => $child->owner_user_id, 'requester_profile_id' => $profile->id, 'reason' => 'personal',
            'idempotency_key' => 'test', 'request_hash' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
        try {
            Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]);
            $this->fail('Substitute history must survive rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Substitute data', $exception->getMessage());
        }
    }
}
