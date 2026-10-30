<?php

namespace Tests\Feature\Organizations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Tests\TestCase;

/**
 * An invitation link opens before anyone knows the organization (docs/product/team.md, т. 7.4): the application's role
 * learns only the organization of a token's hash; the invitations themselves stay under RLS.
 */
class InvitationTenantSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[TestGroup('tenant-isolation')]
    public function test_a_token_hash_gives_only_its_organization_and_the_invitations_stay_under_rls(): void
    {
        if (! $this->postgresIsAvailable()) {
            $this->markTestSkipped('PostgreSQL is required for the database-layer invitation checks.');
        }

        Artisan::call('migrate', ['--database' => 'pgsql_admin', '--force' => true]);
        $pdo = DB::connection('pgsql_admin')->getPdo();
        $pdo->beginTransaction();

        try {
            $organization = (string) Str::uuid();
            $pdo->prepare("INSERT INTO organizations (id, name, slug, status, created_at, updated_at) VALUES (:id, 'Синтетична школа', :slug, 'active', NOW(), NOW())")
                ->execute(['id' => $organization, 'slug' => 'org-'.$organization]);
            $inviter = (string) Str::uuid();
            $pdo->prepare("INSERT INTO users (id, name, email, password, created_at, updated_at) VALUES (:id, 'Синтетичен', :email, 'x', NOW(), NOW())")
                ->execute(['id' => $inviter, 'email' => $inviter.'@example.test']);
            $hash = hash('sha256', Str::random(64));
            $pdo->prepare('INSERT INTO invitations (id, organization_id, email, role, token_hash, expires_at, invited_by, created_at, updated_at) '
                ."VALUES (:id, :organization, 'teacher@example.test', 'instructor', :hash, NOW() + interval '1 day', :inviter, NOW(), NOW())")
                ->execute(['id' => (string) Str::uuid(), 'organization' => $organization, 'hash' => $hash, 'inviter' => $inviter]);

            $pdo->exec('SET LOCAL ROLE progressive_app');
            $pdo->prepare("SELECT set_config('app.current_organization_id', '', true)")->execute();
            $this->assertSame([], $pdo->query('SELECT id FROM invitations')->fetchAll(\PDO::FETCH_COLUMN));

            $tenant = $pdo->prepare('SELECT public.invitation_tenant(:hash)');
            $tenant->execute(['hash' => $hash]);
            $this->assertSame($organization, $tenant->fetchColumn());
            $tenant->execute(['hash' => hash('sha256', Str::random(64))]);
            $this->assertNull($tenant->fetchColumn());
            // Still nothing readable without the organization in the setting.
            $this->assertSame([], $pdo->query('SELECT email FROM invitations')->fetchAll(\PDO::FETCH_COLUMN));
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
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
}
