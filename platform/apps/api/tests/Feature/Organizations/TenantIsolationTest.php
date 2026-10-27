<?php

namespace Tests\Feature\Organizations;

use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('tenant-isolation')]
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    public function test_user_lists_only_organizations_with_an_active_membership(): void
    {
        $user = User::factory()->create();
        $visible = $this->organization('visible-school');
        $inactive = $this->organization('inactive-school');
        $foreign = $this->organization('foreign-school');

        $this->membership($user, $visible, 'active');
        $this->membership($user, $inactive, 'inactive');

        $this->actingAs($user)
            ->getJson('/api/v1/organizations')
            ->assertOk()
            ->assertJsonCount(1, 'organizations')
            ->assertJsonPath('organizations.0.id', $visible->id)
            ->assertJsonMissing(['id' => $inactive->id])
            ->assertJsonMissing(['id' => $foreign->id]);
    }

    public function test_user_cannot_select_an_organization_without_an_active_membership(): void
    {
        $user = User::factory()->create();
        $foreign = $this->organization('foreign-school');

        $this->actingAs($user)
            ->postJson('/api/v1/context/organization', ['organization_id' => $foreign->id])
            ->assertNotFound();

        $this->assertNull(session('active_organization_id'));
    }

    public function test_user_with_two_memberships_can_switch_the_active_organization(): void
    {
        $user = User::factory()->create();
        $school = $this->organization('progressive-school');
        $academy = $this->organization('progressive-academy');
        $this->membership($user, $school);
        $this->membership($user, $academy);

        $this->actingAs($user)
            ->postJson('/api/v1/context/organization', ['organization_id' => $school->id])
            ->assertOk()
            ->assertJsonPath('organization.id', $school->id);

        $this->getJson('/api/v1/context/organization')
            ->assertOk()
            ->assertJsonPath('organization.id', $school->id);

        $this->postJson('/api/v1/context/organization', ['organization_id' => $academy->id])
            ->assertOk()
            ->assertJsonPath('organization.id', $academy->id);

        $this->getJson('/api/v1/context/organization')
            ->assertOk()
            ->assertJsonPath('organization.id', $academy->id);
    }

    public function test_tenant_route_fails_closed_without_a_selected_context(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/context/organization')
            ->assertStatus(409)
            ->assertJsonPath('message', 'Изберете организация, за да продължите.');
    }

    public function test_request_body_cannot_override_the_selected_tenant(): void
    {
        $user = User::factory()->create();
        $selected = $this->organization('selected-school');
        $foreign = $this->organization('foreign-school');
        $this->membership($user, $selected);

        $this->actingAs($user)
            ->withSession(['active_organization_id' => $selected->id])
            ->getJson('/api/v1/context/organization?organization_id='.$foreign->id)
            ->assertOk()
            ->assertJsonPath('organization.id', $selected->id);
    }

    public function test_direct_organization_query_is_scoped_to_the_tenant_context(): void
    {
        $selected = $this->organization('selected-school');
        $foreign = $this->organization('foreign-school');

        app(TenantContext::class)->select($selected);

        $this->assertSame($selected->id, Organization::query()->findOrFail($selected->id)->id);
        $this->assertNull(Organization::query()->find($foreign->id));
    }

    public function test_postgresql_rls_hides_foreign_organizations_from_the_request_role(): void
    {
        if (! $this->postgresIsAvailable()) {
            $this->markTestSkipped('PostgreSQL is required for the database-layer isolation check.');
        }

        Artisan::call('migrate', ['--database' => 'pgsql_admin', '--force' => true]);

        $pdo = $this->postgresPdo();
        $selectedId = (string) Str::uuid();
        $foreignId = (string) Str::uuid();
        $role = 'tenant_test_'.Str::lower(Str::random(12));

        $pdo->beginTransaction();

        try {
            $pdo->exec(sprintf('CREATE ROLE "%s" NOLOGIN NOSUPERUSER NOBYPASSRLS', $role));
            $pdo->exec(sprintf('GRANT USAGE ON SCHEMA public TO "%s"', $role));
            $pdo->exec(sprintf('GRANT SELECT ON organizations, organization_memberships TO "%s"', $role));

            $insert = $pdo->prepare(
                'INSERT INTO organizations (id, name, slug, status, created_at, updated_at) '
                .'VALUES (:id, :name, :slug, :status, NOW(), NOW())'
            );
            $insert->execute(['id' => $selectedId, 'name' => 'Selected', 'slug' => 'selected-'.$selectedId, 'status' => 'active']);
            $insert->execute(['id' => $foreignId, 'name' => 'Foreign', 'slug' => 'foreign-'.$foreignId, 'status' => 'active']);

            $pdo->exec(sprintf('SET LOCAL ROLE "%s"', $role));
            $setTenant = $pdo->prepare("SELECT set_config('app.current_organization_id', :id, true)");
            $setTenant->execute(['id' => $selectedId]);

            $visibleIds = $pdo->query('SELECT id FROM organizations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);

            $this->assertSame([$selectedId], $visibleIds);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    public function test_postgresql_rls_lists_only_the_current_users_membership_organizations_before_tenant_selection(): void
    {
        if (! $this->postgresIsAvailable()) {
            $this->markTestSkipped('PostgreSQL is required for the database-layer isolation check.');
        }

        Artisan::call('migrate', ['--database' => 'pgsql_admin', '--force' => true]);

        $pdo = $this->postgresPdo();
        $userId = (string) Str::uuid();
        $selectedId = (string) Str::uuid();
        $foreignId = (string) Str::uuid();
        $role = 'identity_test_'.Str::lower(Str::random(12));

        $pdo->beginTransaction();

        try {
            $pdo->exec(sprintf('CREATE ROLE "%s" NOLOGIN NOSUPERUSER NOBYPASSRLS', $role));
            $pdo->exec(sprintf('GRANT USAGE ON SCHEMA public TO "%s"', $role));
            $pdo->exec(sprintf('GRANT SELECT ON organizations, organization_memberships TO "%s"', $role));

            $insertUser = $pdo->prepare(
                'INSERT INTO users (id, name, email, password, created_at, updated_at) '
                .'VALUES (:id, :name, :email, :password, NOW(), NOW())'
            );
            $insertUser->execute([
                'id' => $userId,
                'name' => 'Tenant User',
                'email' => $userId.'@example.test',
                'password' => 'not-used',
            ]);

            $insertOrganization = $pdo->prepare(
                'INSERT INTO organizations (id, name, slug, status, created_at, updated_at) '
                .'VALUES (:id, :name, :slug, :status, NOW(), NOW())'
            );
            $insertOrganization->execute(['id' => $selectedId, 'name' => 'Selected', 'slug' => 'selected-'.$selectedId, 'status' => 'active']);
            $insertOrganization->execute(['id' => $foreignId, 'name' => 'Foreign', 'slug' => 'foreign-'.$foreignId, 'status' => 'active']);

            $insertMembership = $pdo->prepare(
                'INSERT INTO organization_memberships '
                .'(id, organization_id, user_id, role, status, created_at, updated_at) '
                .'VALUES (:id, :organization_id, :user_id, :role, :status, NOW(), NOW())'
            );
            $insertMembership->execute([
                'id' => (string) Str::uuid(),
                'organization_id' => $selectedId,
                'user_id' => $userId,
                'role' => 'organization_admin',
                'status' => 'active',
            ]);

            $pdo->exec(sprintf('SET LOCAL ROLE "%s"', $role));
            $setUser = $pdo->prepare("SELECT set_config('app.current_user_id', :id, true)");
            $setUser->execute(['id' => $userId]);

            $visibleIds = $pdo->query('SELECT id FROM organizations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);

            $this->assertSame([$selectedId], $visibleIds);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    public function test_application_postgresql_role_cannot_bypass_row_level_security(): void
    {
        if (! $this->postgresIsAvailable('pgsql')) {
            $this->markTestSkipped('PostgreSQL is required for the database-role isolation check.');
        }

        $role = DB::connection('pgsql')->selectOne(
            'SELECT current_user AS name, rolbypassrls, rolsuper '
            .'FROM pg_roles WHERE rolname = current_user'
        );

        $this->assertFalse($role->rolbypassrls);
        $this->assertFalse($role->rolsuper);
    }

    private function organization(string $slug): Organization
    {
        return Organization::query()->withoutGlobalScopes()->create([
            'name' => Str::headline($slug),
            'slug' => $slug,
            'status' => 'active',
        ]);
    }

    private function membership(User $user, Organization $organization, string $status = 'active'): OrganizationMembership
    {
        return OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => 'organization_admin',
            'status' => $status,
        ]);
    }

    private function postgresIsAvailable(string $connection = 'pgsql_admin'): bool
    {
        try {
            DB::connection($connection)->getPdo();

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
}
