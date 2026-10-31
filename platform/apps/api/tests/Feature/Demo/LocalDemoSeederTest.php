<?php

namespace Tests\Feature\Demo;

use Database\Seeders\LocalDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class LocalDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_demo_seeding_is_idempotent(): void
    {
        config()->set('demo.email', 'owner@demo.local');
        config()->set('demo.password', 'local-demo-password');

        Artisan::call('db:seed', ['--class' => LocalDemoSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => LocalDemoSeeder::class, '--force' => true]);

        // The owner, an administrator and one synthetic parent with a child, an application with a note and an enrollment.
        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseHas('users', ['email' => 'parent@demo.progressive.local', 'phone' => '+359880000002']);
        $this->assertDatabaseCount('children', 1);
        $this->assertDatabaseCount('enrollment_applications', 2);
        $this->assertDatabaseCount('enrollments', 1);
        $this->assertSame(1, DB::table('enrollment_applications')->whereNotNull('parent_note')->where('status', 'submitted')->count());
        // Its history is already expanded: seeding never queues emails.
        $this->assertDatabaseCount('enrollment_events', 3);
        $this->assertSame(0, DB::table('enrollment_events')->whereNull('expanded_at')->count());
        $this->assertDatabaseCount('organizations', 2);
        $this->assertDatabaseCount('organization_memberships', 3);
        $this->assertDatabaseHas('organization_memberships', ['organization_id' => '00000000-0000-4000-8000-000000000101', 'role' => 'organization_admin', 'status' => 'active']);
        $this->assertSame(1, DB::table('staff_members')->where('email', 'admin@demo.progressive.local')->count());
        $this->assertDatabaseCount('organization_catalog_profiles', 1);
        $this->assertDatabaseCount('activities', 1);
        $this->assertDatabaseCount('offerings', 1);
        $this->assertDatabaseCount('locations', 1);
        $this->assertDatabaseCount('instructor_profiles', 1);
        $this->assertDatabaseCount('activity_groups', 2);
        $this->assertDatabaseCount('activity_sessions', 14);
        $this->assertDatabaseCount('price_options', 4);
        $this->assertDatabaseHas('organization_memberships', [
            'role' => 'organization_owner',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('organization_memberships', [
            'role' => 'platform_admin',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('organization_catalog_profiles', [
            'organization_id' => '00000000-0000-4000-8000-000000000101',
            'slug' => 'progressive-academy-demo',
            'public_name' => 'Progressive Academy Demo',
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('activities', [
            'id' => '00000000-0000-4000-8000-000000000201',
            'organization_id' => '00000000-0000-4000-8000-000000000101',
            'slug' => 'shahmat',
            'title' => 'Шахмат',
            'status' => 'published',
        ]);
        $this->assertDatabaseHas('locations', [
            'id' => '00000000-0000-4000-8000-000000000204',
            'type' => 'physical',
            'is_public' => true,
        ]);
        $this->assertDatabaseHas('instructor_profiles', [
            'id' => '00000000-0000-4000-8000-000000000205',
            'public_name' => 'Александър Петров',
            'is_public' => true,
        ]);
        $this->assertDatabaseHas('price_options', [
            'group_id' => '00000000-0000-4000-8000-000000000206',
            'amount_cents' => 3000,
            'currency' => 'EUR',
            'unit' => 'month',
        ]);
        $this->assertSame(7, DB::table('activity_sessions')->where('group_id', '00000000-0000-4000-8000-000000000206')->count());
        $this->assertSame(7, DB::table('activity_sessions')->where('group_id', '00000000-0000-4000-8000-000000000207')->count());
        $this->assertSame(
            ['00000000-0000-4000-8000-000000000101'],
            DB::table('activities')->distinct()->pluck('organization_id')->all(),
        );
    }

    public function test_demo_seeding_is_refused_outside_local_or_testing(): void
    {
        $originalEnvironment = $this->app->environment();
        $this->app->detectEnvironment(fn (): string => 'production');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Local demo data cannot be seeded in this environment.');

            (new LocalDemoSeeder)->run();
        } finally {
            $this->app->detectEnvironment(fn (): string => $originalEnvironment);
        }
    }
}
