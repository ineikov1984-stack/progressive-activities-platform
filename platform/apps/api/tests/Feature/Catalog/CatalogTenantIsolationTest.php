<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Catalog\Models\Location;
use App\Domain\Catalog\Models\Offering;
use App\Domain\Catalog\Models\OrganizationCatalogProfile;
use App\Domain\Catalog\Models\PriceOption;
use App\Domain\Catalog\Models\Session;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;

class CatalogTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_models_are_scoped_to_selected_organization(): void
    {
        $selected = $this->organization('selected-catalog');
        $foreign = $this->organization('foreign-catalog');
        $selectedRows = $this->catalogRows($selected);
        $foreignRows = $this->catalogRows($foreign);

        app(TenantContext::class)->select($selected);

        foreach ($selectedRows as $modelClass => $id) {
            $this->assertSame($id, $modelClass::query()->findOrFail($id)->id, $modelClass);
            $this->assertNull($modelClass::query()->find($foreignRows[$modelClass]), $modelClass);
        }

        $this->assertSame($selectedRows[Offering::class], Activity::query()->findOrFail($selectedRows[Activity::class])->offerings->sole()->id);
        $this->assertSame($selectedRows[Group::class], Offering::query()->findOrFail($selectedRows[Offering::class])->groups->sole()->id);
        $this->assertSame($selectedRows[Session::class], Group::query()->findOrFail($selectedRows[Group::class])->sessions->sole()->id);
        $this->assertSame($selectedRows[InstructorProfile::class], Group::query()->findOrFail($selectedRows[Group::class])->instructors->sole()->id);
        $this->assertSame($selectedRows[InstructorProfile::class], Session::query()->findOrFail($selectedRows[Session::class])->instructors->sole()->id);
    }

    public function test_request_role_cannot_read_foreign_catalog_rows(): void
    {
        if (! $this->postgresIsAvailable()) {
            $this->markTestSkipped('PostgreSQL is required for the database-layer catalog isolation check.');
        }

        Artisan::call('migrate', ['--database' => 'pgsql_admin', '--force' => true]);

        $pdo = $this->postgresPdo();
        $selectedId = (string) Str::uuid();
        $foreignId = (string) Str::uuid();
        $draftId = (string) Str::uuid();
        $selectedActivity = (string) Str::uuid();
        $foreignActivity = (string) Str::uuid();

        $pdo->beginTransaction();

        try {
            $insertOrganization = $pdo->prepare(
                'INSERT INTO organizations (id, name, slug, status, created_at, updated_at) '
                .'VALUES (:id, :name, :slug, :status, NOW(), NOW())'
            );
            foreach ([$selectedId => 'Selected', $foreignId => 'Foreign', $draftId => 'Draft'] as $id => $name) {
                $insertOrganization->execute(['id' => $id, 'name' => $name, 'slug' => Str::lower($name).'-'.$id, 'status' => 'active']);
            }

            $insertActivity = $pdo->prepare(
                'INSERT INTO activities (id, organization_id, title, slug, category, summary, age_label, status, version, created_at, updated_at) '
                .'VALUES (:id, :organization_id, :title, :slug, :category, :summary, :age_label, :status, 1, NOW(), NOW())'
            );
            foreach ([[$selectedActivity, $selectedId, 'Selected'], [$foreignActivity, $foreignId, 'Foreign']] as [$id, $organizationId, $title]) {
                $insertActivity->execute([
                    'id' => $id,
                    'organization_id' => $organizationId,
                    'title' => $title,
                    'slug' => Str::lower($title).'-'.$id,
                    'category' => 'arts',
                    'summary' => 'Catalog isolation fixture',
                    'age_label' => '7+',
                    'status' => 'draft',
                ]);
            }

            $insertProfile = $pdo->prepare(
                'INSERT INTO organization_catalog_profiles (id, organization_id, slug, public_name, is_published, version, created_at, updated_at) '
                .'VALUES (:id, :organization_id, :slug, :public_name, :is_published, 1, NOW(), NOW())'
            );
            $insertProfile->execute(['id' => (string) Str::uuid(), 'organization_id' => $selectedId, 'slug' => 'selected-'.$selectedId, 'public_name' => 'Selected', 'is_published' => 'true']);
            $insertProfile->execute(['id' => (string) Str::uuid(), 'organization_id' => $foreignId, 'slug' => 'foreign-'.$foreignId, 'public_name' => 'Foreign', 'is_published' => 'true']);
            $insertProfile->execute(['id' => (string) Str::uuid(), 'organization_id' => $draftId, 'slug' => 'draft-'.$draftId, 'public_name' => 'Draft', 'is_published' => 'false']);

            $this->insertLinkedCatalogRows($pdo, $selectedId, $selectedActivity);
            $this->insertLinkedCatalogRows($pdo, $foreignId, $foreignActivity);

            $pdo->exec('SET LOCAL ROLE progressive_app');
            $this->assertSame('progressive_app', $pdo->query('SELECT current_user')->fetchColumn());

            $setTenant = $pdo->prepare("SELECT set_config('app.current_organization_id', :id, true)");
            $setTenant->execute(['id' => $selectedId]);

            $this->assertSame([$selectedActivity], $pdo->query('SELECT id FROM activities ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
            $this->assertSame([$selectedId], $pdo->query('SELECT organization_id FROM organization_catalog_profiles ORDER BY organization_id')->fetchAll(PDO::FETCH_COLUMN));
            foreach ([
                'offerings',
                'activity_groups',
                'activity_sessions',
                'locations',
                'instructor_profiles',
                'price_options',
                'activity_group_instructors',
                'activity_session_instructors',
            ] as $table) {
                $this->assertSame([$selectedId], $pdo->query("SELECT organization_id FROM {$table}")->fetchAll(PDO::FETCH_COLUMN), $table);
            }

            $setTenant->execute(['id' => '']);
            $this->assertSame([], $pdo->query('SELECT id FROM activities')->fetchAll(PDO::FETCH_COLUMN));
            $publicProfileIds = $pdo->query('SELECT organization_id FROM organization_catalog_profiles')->fetchAll(PDO::FETCH_COLUMN);
            $this->assertCount(2, $publicProfileIds);
            $this->assertContains($selectedId, $publicProfileIds);
            $this->assertContains($foreignId, $publicProfileIds);
            $this->assertNotContains($draftId, $publicProfileIds);
            $updateProfile = $pdo->prepare("UPDATE organization_catalog_profiles SET public_name = 'Not allowed' WHERE organization_id = :id");
            $updateProfile->execute(['id' => $selectedId]);
            $this->assertSame(0, $updateProfile->rowCount());
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    public function test_catalog_payload_cannot_override_organization_id(): void
    {
        $selected = $this->organization('selected-payload');
        $foreign = $this->organization('foreign-payload');
        app(TenantContext::class)->select($selected);

        $activity = Activity::query()->create([
            'organization_id' => $foreign->id,
            'title' => 'Painting',
            'slug' => 'painting',
            'category' => 'arts',
            'summary' => 'After-school painting',
            'age_label' => '7+',
        ]);

        $this->assertSame($selected->id, $activity->organization_id);

        $activity->fill(['organization_id' => $foreign->id, 'title' => 'Updated painting'])->save();

        $this->assertSame($selected->id, $activity->fresh()->organization_id);
        $this->assertSame('Updated painting', $activity->fresh()->title);
    }

    private function organization(string $slug): Organization
    {
        return Organization::query()->withoutGlobalScopes()->create([
            'name' => Str::headline($slug),
            'slug' => $slug,
            'status' => 'active',
        ]);
    }

    /** @return array<class-string, string> */
    private function catalogRows(Organization $organization): array
    {
        app(TenantContext::class)->select($organization);

        $profile = OrganizationCatalogProfile::query()->create(['public_name' => $organization->name]);
        $location = Location::query()->create(['name' => 'Studio', 'type' => 'physical', 'space_usage' => 'exclusive', 'address' => '1 Main Street']);
        $instructor = InstructorProfile::query()->create(['public_name' => 'Teacher']);
        $activity = Activity::query()->create([
            'title' => 'Painting',
            'slug' => 'painting',
            'category' => 'arts',
            'summary' => 'After-school painting',
            'age_label' => '7+',
        ]);
        $offering = $activity->offerings()->create(['title' => 'Autumn course', 'location_id' => $location->id]);
        $group = $offering->groups()->create(['name' => 'Tuesday group', 'capacity_min' => 1, 'capacity_max' => 12, 'location_id' => $location->id]);
        $session = $group->sessions()->create(['starts_at' => '2026-11-03 16:00:00', 'ends_at' => '2026-11-03 17:00:00', 'location_id' => $location->id]);
        $price = $offering->priceOptions()->create(['group_id' => $group->id, 'enrollment_mode' => 'single', 'unit' => 'session', 'amount_cents' => 1500]);
        $group->instructors()->attach($instructor->id);
        $session->instructors()->attach($instructor->id);

        return [
            Activity::class => $activity->id,
            Offering::class => $offering->id,
            Group::class => $group->id,
            Session::class => $session->id,
            Location::class => $location->id,
            InstructorProfile::class => $instructor->id,
            PriceOption::class => $price->id,
            OrganizationCatalogProfile::class => $profile->id,
        ];
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

    private function insertLinkedCatalogRows(PDO $pdo, string $organizationId, string $activityId): void
    {
        $locationId = (string) Str::uuid();
        $instructorId = (string) Str::uuid();
        $offeringId = (string) Str::uuid();
        $groupId = (string) Str::uuid();
        $sessionId = (string) Str::uuid();

        $rows = [
            ['locations', ['id' => $locationId, 'organization_id' => $organizationId, 'name' => 'Studio', 'type' => 'physical', 'space_usage' => 'exclusive']],
            ['instructor_profiles', ['id' => $instructorId, 'organization_id' => $organizationId, 'public_name' => 'Teacher']],
            ['offerings', ['id' => $offeringId, 'organization_id' => $organizationId, 'activity_id' => $activityId]],
            ['activity_groups', ['id' => $groupId, 'organization_id' => $organizationId, 'offering_id' => $offeringId, 'name' => 'Group']],
            ['activity_sessions', ['id' => $sessionId, 'organization_id' => $organizationId, 'group_id' => $groupId, 'starts_at' => '2026-11-03 16:00:00+02', 'ends_at' => '2026-11-03 17:00:00+02']],
            ['price_options', ['id' => (string) Str::uuid(), 'organization_id' => $organizationId, 'offering_id' => $offeringId, 'group_id' => $groupId, 'enrollment_mode' => 'single', 'unit' => 'session', 'amount_cents' => 1500]],
            ['activity_group_instructors', ['organization_id' => $organizationId, 'group_id' => $groupId, 'instructor_profile_id' => $instructorId]],
            ['activity_session_instructors', ['organization_id' => $organizationId, 'session_id' => $sessionId, 'instructor_profile_id' => $instructorId]],
        ];

        foreach ($rows as [$table, $values]) {
            $columns = implode(', ', array_keys($values));
            $bindings = implode(', ', array_map(fn (string $column): string => ':'.$column, array_keys($values)));
            $statement = $pdo->prepare("INSERT INTO {$table} ({$columns}) VALUES ({$bindings})");
            $statement->execute($values);
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
}
