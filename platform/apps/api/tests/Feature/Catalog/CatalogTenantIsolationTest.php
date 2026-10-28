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
use PDOException;
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
            $fixtureProfiles = $pdo->prepare(
                'SELECT organization_id FROM organization_catalog_profiles '
                .'WHERE organization_id IN (:selected, :foreign, :draft) ORDER BY organization_id'
            );
            $fixtureProfiles->execute([
                'selected' => $selectedId,
                'foreign' => $foreignId,
                'draft' => $draftId,
            ]);
            $publicProfileIds = $fixtureProfiles->fetchAll(PDO::FETCH_COLUMN);
            $expectedPublicProfileIds = [$selectedId, $foreignId];
            sort($expectedPublicProfileIds);
            $this->assertSame($expectedPublicProfileIds, $publicProfileIds);
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

    public function test_request_role_cannot_link_foreign_catalog_parents_or_instructors(): void
    {
        if (! $this->postgresIsAvailable()) {
            $this->markTestSkipped('PostgreSQL is required for the catalog foreign-key isolation check.');
        }

        Artisan::call('migrate', ['--database' => 'pgsql_admin', '--force' => true]);

        $pdo = $this->postgresPdo();
        $selectedId = (string) Str::uuid();
        $foreignId = (string) Str::uuid();
        $selectedActivity = (string) Str::uuid();
        $foreignActivity = (string) Str::uuid();
        $pdo->beginTransaction();

        try {
            $insertOrganization = $pdo->prepare(
                'INSERT INTO organizations (id, name, slug, status, created_at, updated_at) '
                .'VALUES (:id, :name, :slug, :status, NOW(), NOW())'
            );
            $insertActivity = $pdo->prepare(
                'INSERT INTO activities (id, organization_id, title, slug, category, summary, age_label, status, version, created_at, updated_at) '
                .'VALUES (:id, :organization_id, :title, :slug, :category, :summary, :age_label, :status, 1, NOW(), NOW())'
            );
            foreach ([[$selectedId, $selectedActivity, 'Selected'], [$foreignId, $foreignActivity, 'Foreign']] as [$organizationId, $activityId, $name]) {
                $insertOrganization->execute([
                    'id' => $organizationId,
                    'name' => $name,
                    'slug' => Str::lower($name).'-'.$organizationId,
                    'status' => 'active',
                ]);
                $insertActivity->execute([
                    'id' => $activityId,
                    'organization_id' => $organizationId,
                    'title' => $name,
                    'slug' => Str::lower($name).'-'.$activityId,
                    'category' => 'arts',
                    'summary' => 'Catalog isolation fixture',
                    'age_label' => '7+',
                    'status' => 'draft',
                ]);
            }

            $selected = $this->insertLinkedCatalogRows($pdo, $selectedId, $selectedActivity);
            $foreign = $this->insertLinkedCatalogRows($pdo, $foreignId, $foreignActivity);

            $pdo->exec('SET LOCAL ROLE progressive_app');
            $setTenant = $pdo->prepare("SELECT set_config('app.current_organization_id', :id, true)");
            $setTenant->execute(['id' => $selectedId]);

            $attempts = [
                ['offering activity', 'offerings', ['id' => (string) Str::uuid(), 'organization_id' => $selectedId, 'activity_id' => $foreignActivity]],
                ['offering location', 'offerings', ['id' => (string) Str::uuid(), 'organization_id' => $selectedId, 'activity_id' => $selectedActivity, 'location_id' => $foreign['location']]],
                ['offering lead instructor', 'offerings', ['id' => (string) Str::uuid(), 'organization_id' => $selectedId, 'activity_id' => $selectedActivity, 'lead_instructor_profile_id' => $foreign['instructor']]],
                ['group offering', 'activity_groups', ['id' => (string) Str::uuid(), 'organization_id' => $selectedId, 'offering_id' => $foreign['offering'], 'name' => 'Invalid group']],
                ['group location', 'activity_groups', ['id' => (string) Str::uuid(), 'organization_id' => $selectedId, 'offering_id' => $selected['offering'], 'location_id' => $foreign['location'], 'name' => 'Invalid group']],
                ['session group', 'activity_sessions', ['id' => (string) Str::uuid(), 'organization_id' => $selectedId, 'group_id' => $foreign['group'], 'starts_at' => '2026-11-04 16:00:00+02', 'ends_at' => '2026-11-04 17:00:00+02']],
                ['session location', 'activity_sessions', ['id' => (string) Str::uuid(), 'organization_id' => $selectedId, 'group_id' => $selected['group'], 'location_id' => $foreign['location'], 'starts_at' => '2026-11-04 16:00:00+02', 'ends_at' => '2026-11-04 17:00:00+02']],
                ['price offering', 'price_options', ['id' => (string) Str::uuid(), 'organization_id' => $selectedId, 'offering_id' => $foreign['offering'], 'enrollment_mode' => 'single', 'unit' => 'session', 'amount_cents' => 1500]],
                ['price group', 'price_options', ['id' => (string) Str::uuid(), 'organization_id' => $selectedId, 'offering_id' => $selected['offering'], 'group_id' => $foreign['group'], 'enrollment_mode' => 'single', 'unit' => 'session', 'amount_cents' => 1500]],
                ['group pivot group', 'activity_group_instructors', ['organization_id' => $selectedId, 'group_id' => $foreign['group'], 'instructor_profile_id' => $selected['instructor']]],
                ['group pivot instructor', 'activity_group_instructors', ['organization_id' => $selectedId, 'group_id' => $selected['group'], 'instructor_profile_id' => $foreign['instructor']]],
                ['session pivot session', 'activity_session_instructors', ['organization_id' => $selectedId, 'session_id' => $foreign['session'], 'instructor_profile_id' => $selected['instructor']]],
                ['session pivot instructor', 'activity_session_instructors', ['organization_id' => $selectedId, 'session_id' => $selected['session'], 'instructor_profile_id' => $foreign['instructor']]],
            ];

            foreach ($attempts as [$label, $table, $values]) {
                $this->assertForeignCatalogLinkRejected($pdo, $label, $table, $values);
            }

            $validOfferingId = (string) Str::uuid();
            $validOffering = $pdo->prepare(
                'INSERT INTO offerings (id, organization_id, activity_id, location_id, lead_instructor_profile_id) '
                .'VALUES (:id, :organization_id, :activity_id, :location_id, :lead_instructor_profile_id)'
            );
            $validOffering->execute([
                'id' => $validOfferingId,
                'organization_id' => $selectedId,
                'activity_id' => $selectedActivity,
                'location_id' => $selected['location'],
                'lead_instructor_profile_id' => $selected['instructor'],
            ]);
            $this->assertSame(1, $validOffering->rowCount());

            $deleteLocation = $pdo->prepare('DELETE FROM locations WHERE id = :id');
            $deleteLocation->execute(['id' => $selected['location']]);
            $this->assertSame(1, $deleteLocation->rowCount());
            $this->assertCatalogReferenceCleared($pdo, 'offerings', 'location_id', $validOfferingId);
            $this->assertCatalogReferenceCleared($pdo, 'activity_groups', 'location_id', $selected['group']);
            $this->assertCatalogReferenceCleared($pdo, 'activity_sessions', 'location_id', $selected['session']);

            $deleteInstructor = $pdo->prepare('DELETE FROM instructor_profiles WHERE id = :id');
            $deleteInstructor->execute(['id' => $selected['instructor']]);
            $this->assertSame(1, $deleteInstructor->rowCount());
            $this->assertCatalogReferenceCleared($pdo, 'offerings', 'lead_instructor_profile_id', $validOfferingId);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
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

    /** @return array{location: string, instructor: string, offering: string, group: string, session: string} */
    private function insertLinkedCatalogRows(PDO $pdo, string $organizationId, string $activityId): array
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

        return [
            'location' => $locationId,
            'instructor' => $instructorId,
            'offering' => $offeringId,
            'group' => $groupId,
            'session' => $sessionId,
        ];
    }

    /** @param array<string, string|int> $values */
    private function assertForeignCatalogLinkRejected(PDO $pdo, string $label, string $table, array $values): void
    {
        $pdo->exec('SAVEPOINT catalog_link_attempt');

        try {
            $columns = implode(', ', array_keys($values));
            $bindings = implode(', ', array_map(fn (string $column): string => ':'.$column, array_keys($values)));
            $statement = $pdo->prepare("INSERT INTO {$table} ({$columns}) VALUES ({$bindings})");
            $statement->execute($values);
            $this->fail("{$label} accepted a foreign catalog UUID.");
        } catch (PDOException $exception) {
            $this->assertSame('23503', $exception->getCode(), $label);
        } finally {
            $pdo->exec('ROLLBACK TO SAVEPOINT catalog_link_attempt');
            $pdo->exec('RELEASE SAVEPOINT catalog_link_attempt');
        }
    }

    private function assertCatalogReferenceCleared(PDO $pdo, string $table, string $column, string $id): void
    {
        $statement = $pdo->prepare("SELECT id, {$column} FROM {$table} WHERE id = :id");
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $this->assertSame($id, $row['id']);
        $this->assertNull($row[$column]);
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
