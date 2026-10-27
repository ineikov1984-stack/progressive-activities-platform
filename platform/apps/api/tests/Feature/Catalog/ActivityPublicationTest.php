<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Actions\PublishActivityInternally;
use App\Domain\Catalog\Actions\SaveGroupSchedule;
use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;

class ActivityPublicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_price_options_accept_positive_integer_euro_cents_and_multiple_enrollment_modes(): void
    {
        [, , $activity] = $this->setupCatalog();

        $group = $this->postJson($this->groupsUrl($activity), [
            ...$this->groupPayload(),
            'price_options' => [
                ['enrollment_mode' => 'trial', 'unit' => 'session', 'amount_cents' => 1000],
                ['enrollment_mode' => 'monthly', 'unit' => 'month', 'amount_cents' => 4500],
                ['enrollment_mode' => 'package', 'unit' => 'package', 'amount_cents' => 12000],
            ],
        ])->assertCreated()
            ->assertJsonCount(3, 'group.price_options')
            ->assertJsonPath('group.price_options.0.currency', 'EUR')
            ->assertJsonPath('group.price_options.1.enrollment_mode', 'monthly')
            ->json('group');

        $this->assertDatabaseHas('price_options', [
            'group_id' => $group['id'], 'enrollment_mode' => 'trial', 'unit' => 'session',
            'amount_cents' => 1000, 'currency' => 'EUR',
        ]);

        $this->patchJson('/api/v1/catalog/groups/'.$group['id'], [
            'version' => 1,
            'price_options' => [
                ['enrollment_mode' => 'single', 'unit' => 'session', 'amount_cents' => 1500],
                ['enrollment_mode' => 'full_period', 'unit' => 'period', 'amount_cents' => 18000],
            ],
        ])->assertOk()
            ->assertJsonCount(2, 'group.price_options')
            ->assertJsonPath('group.price_options.1.unit', 'period');
        $this->assertDatabaseMissing('price_options', ['group_id' => $group['id'], 'enrollment_mode' => 'trial']);
    }

    public function test_invalid_amount_currency_mode_or_unit_is_rejected_without_price_writes(): void
    {
        [, , $activity] = $this->setupCatalog();

        $invalidOptions = [
            ['enrollment_mode' => 'single', 'unit' => 'session', 'amount_cents' => -1],
            ['enrollment_mode' => 'single', 'unit' => 'session', 'amount_cents' => 12.5],
            ['enrollment_mode' => 'unknown', 'unit' => 'session', 'amount_cents' => 1200],
            ['enrollment_mode' => 'single', 'unit' => 'week', 'amount_cents' => 1200],
            ['enrollment_mode' => 'single', 'unit' => 'session', 'amount_cents' => 1200, 'currency' => 'BGN'],
        ];

        foreach ($invalidOptions as $priceOption) {
            $this->postJson($this->groupsUrl($activity), [
                ...$this->groupPayload(), 'price_options' => [$priceOption],
            ])->assertUnprocessable();
        }

        $this->assertDatabaseCount('activity_groups', 0);
        $this->assertDatabaseCount('price_options', 0);
    }

    public function test_offering_level_prices_round_trip_through_activity_payloads(): void
    {
        $user = User::factory()->create();
        $organization = $this->organizationFor($user);
        $this->authenticate($user, $organization);

        $activity = $this->postJson('/api/v1/catalog/activities', [
            ...$this->activityPayload(),
            'offering' => ['price_options' => [[
                'enrollment_mode' => 'full_period', 'unit' => 'period', 'amount_cents' => 24000,
                'terms' => 'Плащане на две вноски',
            ]]],
        ])->assertCreated()
            ->assertJsonPath('activity.offering.price_options.0.currency', 'EUR')
            ->json('activity');

        $this->patchJson('/api/v1/catalog/activities/'.$activity['id'], [
            'version' => 1,
            'offering' => ['price_options' => [[
                'enrollment_mode' => 'monthly', 'unit' => 'month', 'amount_cents' => 6000,
            ]]],
        ])->assertOk()
            ->assertJsonCount(1, 'activity.offering.price_options')
            ->assertJsonPath('activity.offering.price_options.0.enrollment_mode', 'monthly');
    }

    public function test_incomplete_activity_or_missing_offering_group_price_or_session_cannot_publish(): void
    {
        [, $organization] = $this->setupCatalog(withActivity: false);

        $cases = [
            'incomplete activity' => ['core' => false, 'offering' => true, 'group' => true, 'price' => true, 'session' => true],
            'missing offering' => ['core' => true, 'offering' => false, 'group' => false, 'price' => false, 'session' => false],
            'missing group' => ['core' => true, 'offering' => true, 'group' => false, 'price' => false, 'session' => false],
            'missing price' => ['core' => true, 'offering' => true, 'group' => true, 'price' => false, 'session' => true],
            'missing session' => ['core' => true, 'offering' => true, 'group' => true, 'price' => true, 'session' => false],
        ];

        foreach ($cases as $state) {
            app(TenantContext::class)->select($organization);
            $activity = $this->activityState($state);

            $this->postJson($this->publishUrl($activity), ['version' => 1])->assertUnprocessable();
            $this->assertDatabaseHas('activities', [
                'id' => $activity->id, 'status' => 'draft', 'published_at' => null, 'version' => 1,
            ]);
        }

        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_blocking_conflict_returns_conflict_and_keeps_activity_draft(): void
    {
        [, , $activity] = $this->setupCatalog();
        $teacher = InstructorProfile::query()->create(['public_name' => 'Анна']);
        $payload = [
            ...$this->groupPayload(), 'instructor_ids' => [$teacher->id],
            'price_options' => [['enrollment_mode' => 'monthly', 'unit' => 'month', 'amount_cents' => 5000]],
        ];
        $this->postJson($this->groupsUrl($activity), $payload)->assertCreated();
        $this->postJson($this->groupsUrl($activity), $payload)->assertCreated();

        $this->postJson($this->publishUrl($activity), ['version' => 1])->assertConflict();

        $this->assertDatabaseHas('activities', [
            'id' => $activity->id, 'status' => 'draft', 'published_at' => null, 'version' => 1,
        ]);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_publish_is_atomic_versioned_and_records_exactly_one_audit_event(): void
    {
        [$user, $organization, $activity] = $this->setupCatalog();
        $this->postJson($this->groupsUrl($activity), [
            ...$this->groupPayload(),
            'price_options' => [['enrollment_mode' => 'monthly', 'unit' => 'month', 'amount_cents' => 5000]],
        ])->assertCreated();

        $this->postJson($this->publishUrl($activity), ['version' => 1])
            ->assertOk()
            ->assertJsonPath('activity.status', 'published')
            ->assertJsonPath('activity.version', 2)
            ->assertJsonPath('activity.published_at', fn ($value) => is_string($value) && $value !== '');

        $event = DB::table('audit_events')->where('action', 'catalog.activity_published')->sole();
        $this->assertSame($user->id, $event->actor_id);
        $this->assertSame($organization->id, $event->organization_id);
        $this->assertSame('activity', $event->subject_type);
        $this->assertSame($activity->id, $event->subject_id);
        $this->assertSame(['version' => 2], json_decode($event->metadata, true, flags: JSON_THROW_ON_ERROR));

        $this->postJson($this->publishUrl($activity), ['version' => 1])->assertConflict();
        $this->assertSame(1, DB::table('audit_events')->where('action', 'catalog.activity_published')->count());
    }

    public function test_publication_requires_publish_permission_active_tenant_and_current_version(): void
    {
        [$user, $organization, $activity] = $this->setupCatalog();
        $this->postJson($this->groupsUrl($activity), [
            ...$this->groupPayload(),
            'price_options' => [['enrollment_mode' => 'single', 'unit' => 'session', 'amount_cents' => 1500]],
        ])->assertCreated();

        OrganizationMembership::query()->withoutGlobalScopes()
            ->where('organization_id', $organization->id)->where('user_id', $user->id)
            ->update(['role' => 'coordinator']);
        $this->postJson($this->publishUrl($activity), ['version' => 1])->assertForbidden();

        OrganizationMembership::query()->withoutGlobalScopes()
            ->where('organization_id', $organization->id)->where('user_id', $user->id)
            ->update(['role' => 'organization_owner']);
        $this->postJson($this->publishUrl($activity), ['version' => 2])->assertConflict();

        $organization->update(['status' => 'restricted']);
        $this->postJson($this->publishUrl($activity), ['version' => 1])->assertForbidden();

        $this->assertDatabaseHas('activities', ['id' => $activity->id, 'status' => 'draft', 'version' => 1]);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_schedule_writes_and_publication_serialize_on_the_organization_row(): void
    {
        if (! $this->postgresIsAvailable()) {
            $this->markTestSkipped('PostgreSQL is required for the concurrency check.');
        }

        Artisan::call('migrate', ['--database' => 'pgsql_admin', '--force' => true]);
        $originalConnection = DB::getDefaultConnection();
        DB::setDefaultConnection('pgsql_admin');
        $lock = $this->postgresPdo();

        try {
            $user = User::factory()->create();
            $organization = $this->organizationFor($user);
            $this->authenticate($user, $organization);
            $scheduleActivity = $this->activityState([
                'core' => true, 'offering' => true, 'group' => true, 'price' => false, 'session' => true,
            ]);
            $scheduleGroup = $scheduleActivity->offerings()->firstOrFail()->groups()->firstOrFail();
            $scheduleSession = $scheduleGroup->sessions()->firstOrFail();
            $publishActivity = $this->activityState([
                'core' => true, 'offering' => true, 'group' => true, 'price' => true, 'session' => true,
            ]);

            $lock->beginTransaction();
            $statement = $lock->prepare('SELECT id FROM organizations WHERE id = :id FOR UPDATE');
            $statement->execute(['id' => $organization->id]);
            DB::connection('pgsql_admin')->statement("SET lock_timeout = '100ms'");

            $scheduleTimedOut = $this->lockTimedOut(fn () => app(SaveGroupSchedule::class)->handle(
                $scheduleActivity,
                [
                    'id' => $scheduleGroup->id,
                    'version' => 1,
                    'session_overrides' => [[
                        'id' => $scheduleSession->id,
                        'starts_at' => '2026-11-03T17:00:00+02:00',
                        'ends_at' => '2026-11-03T18:00:00+02:00',
                    ]],
                ],
            ));
            $publishTimedOut = $this->lockTimedOut(fn () => app(PublishActivityInternally::class)->handle(
                $user,
                $publishActivity,
                1,
            ));

            $this->assertTrue($scheduleTimedOut, 'Schedule writes must wait for the organization publication lock.');
            $this->assertTrue($publishTimedOut, 'Publication must wait for the organization schedule lock.');
            $this->assertDatabaseHas('activities', [
                'id' => $publishActivity->id, 'status' => 'draft', 'version' => 1, 'published_at' => null,
            ]);
        } finally {
            if ($lock->inTransaction()) {
                $lock->rollBack();
            }
            DB::connection('pgsql_admin')->statement('SET lock_timeout TO DEFAULT');
            DB::setDefaultConnection($originalConnection);
            app(TenantContext::class)->clear();
        }
    }

    /** @return array{User, Organization, Activity}|array{User, Organization} */
    private function setupCatalog(bool $withActivity = true): array
    {
        $user = User::factory()->create();
        $organization = $this->organizationFor($user);
        $this->authenticate($user, $organization);
        if (! $withActivity) {
            return [$user, $organization];
        }

        $activity = Activity::query()->create([
            'title' => 'Art', 'slug' => 'art-'.Str::random(8), 'category' => 'Art',
            'summary' => 'Art class', 'age_label' => '6–9',
        ]);
        $activity->offerings()->create([]);

        return [$user, $organization, $activity];
    }

    private function organizationFor(User $user): Organization
    {
        $organization = Organization::query()->withoutGlobalScopes()->create([
            'name' => 'School', 'slug' => 'school-'.Str::random(12), 'status' => 'active',
        ]);
        OrganizationMembership::query()->withoutGlobalScopes()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id,
            'role' => 'organization_owner', 'status' => 'active',
        ]);

        return $organization;
    }

    private function authenticate(User $user, Organization $organization): void
    {
        app(TenantContext::class)->select($organization);
        $this->actingAs($user)->withSession(['active_organization_id' => $organization->id])
            ->withHeader('Accept', 'application/json');
    }

    /** @param array{core: bool, offering: bool, group: bool, price: bool, session: bool} $state */
    private function activityState(array $state): Activity
    {
        $activity = Activity::query()->create([
            'title' => $state['core'] ? 'Шахмат' : '', 'slug' => 'activity-'.Str::random(12),
            'category' => $state['core'] ? 'Спорт' : '', 'summary' => $state['core'] ? 'Занимание' : '',
            'age_label' => $state['core'] ? '7–10' : '',
        ]);
        if (! $state['offering']) {
            return $activity;
        }
        $offering = $activity->offerings()->create([]);
        if (! $state['group']) {
            return $activity;
        }
        $group = $offering->groups()->create([
            'name' => 'Група', 'capacity_min' => 1, 'capacity_max' => 12,
            'schedule_pattern' => ['timezone' => 'Europe/Sofia'],
        ]);
        if ($state['price']) {
            $offering->priceOptions()->create([
                'group_id' => $group->id, 'enrollment_mode' => 'single', 'unit' => 'session', 'amount_cents' => 1500,
            ]);
        }
        if ($state['session']) {
            $group->sessions()->create([
                'starts_at' => '2026-11-03 15:00:00+00', 'ends_at' => '2026-11-03 16:00:00+00', 'status' => 'scheduled',
            ]);
        }

        return $activity;
    }

    /** @return array<string, mixed> */
    private function activityPayload(): array
    {
        return ['title' => 'Шахмат', 'category' => 'Спорт', 'summary' => 'Учим чрез игра', 'age_label' => '7–10'];
    }

    /** @return array<string, mixed> */
    private function groupPayload(): array
    {
        return ['name' => 'Вторник', 'capacity_min' => 3, 'capacity_max' => 12, 'schedule_pattern' => [
            'start_date' => '2026-10-27', 'end_date' => '2026-11-03', 'frequency' => 'weekly',
            'slots' => [['weekday' => 2, 'starts_at' => '17:00', 'duration_minutes' => 60]],
        ]];
    }

    private function groupsUrl(Activity $activity): string
    {
        return '/api/v1/catalog/activities/'.$activity->id.'/groups';
    }

    private function publishUrl(Activity $activity): string
    {
        return '/api/v1/catalog/activities/'.$activity->id.'/publish';
    }

    private function postgresIsAvailable(): bool
    {
        try {
            DB::connection('pgsql_admin')->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function postgresPdo(): PDO
    {
        /** @var array{host: string, port: int|string, database: string, username: string, password: string} $config */
        $config = config('database.connections.pgsql_admin');

        return new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']),
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    /** @param callable(): mixed $callback */
    private function lockTimedOut(callable $callback): bool
    {
        try {
            $callback();

            return false;
        } catch (QueryException $exception) {
            return str_contains($exception->getMessage(), 'lock timeout');
        }
    }
}
