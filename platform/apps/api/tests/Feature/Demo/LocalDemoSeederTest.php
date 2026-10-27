<?php

namespace Tests\Feature\Demo;

use Database\Seeders\LocalDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('organizations', 2);
        $this->assertDatabaseCount('organization_memberships', 2);
        $this->assertDatabaseHas('organization_memberships', [
            'role' => 'organization_owner',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('organization_memberships', [
            'role' => 'platform_admin',
            'status' => 'active',
        ]);
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
