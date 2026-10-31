<?php

namespace Tests\Feature\Organizations;

use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Services\MembershipActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Tests\TestCase;

/**
 * "Последен вход" and "Последна активност" under the application role and row level security (DECISIONS 31.10.2026
 * „Екип — последен вход и филтър по роли“): the person's own memberships in two organizations are written, someone
 * else's is not, an UPDATE with another organization set is refused, and the settings are put back afterwards.
 */
class MembershipActivitySchemaTest extends TestCase
{
    use RefreshDatabase;

    #[TestGroup('tenant-isolation')]
    public function test_the_application_role_writes_only_the_memberships_of_the_organization_it_sets(): void
    {
        if (! $this->postgresIsAvailable()) {
            $this->markTestSkipped('PostgreSQL is required for the row level security of the last sign-in.');
        }

        Artisan::call('migrate', ['--database' => 'pgsql_admin', '--force' => true]);
        $connection = DB::connection('pgsql_admin');
        $default = DB::getDefaultConnection();
        $connection->beginTransaction();
        DB::setDefaultConnection('pgsql_admin');

        try {
            $pdo = $connection->getPdo();
            $school = $this->organization($pdo);
            $club = $this->organization($pdo);
            $foreign = $this->organization($pdo);
            $teacher = $this->user($pdo);
            $stranger = $this->user($pdo);
            $here = $this->membership($pdo, $school, $teacher);
            $there = $this->membership($pdo, $club, $teacher);
            $theirs = $this->membership($pdo, $foreign, $stranger);

            // What the application does, as the application.
            $pdo->exec('SET LOCAL ROLE progressive_app');
            $activity = app(MembershipActivity::class);
            $activity->signedIn((new User)->forceFill(['id' => $teacher]));
            $activity->seen($school, $here);
            // Another organization's membership under this organization's id: not even seen.
            $activity->seen($school, $theirs);
            // The settings are put back as they were.
            $this->assertSame('', $pdo->query("SELECT COALESCE(current_setting('app.current_organization_id', true), '')")->fetchColumn());
            $this->assertSame('', $pdo->query("SELECT COALESCE(current_setting('app.current_user_id', true), '')")->fetchColumn());

            // An UPDATE with another organization set: the person's own other membership is refused, a foreign one not found.
            $pdo->prepare("SELECT set_config('app.current_user_id', :id, true)")->execute(['id' => $teacher]);
            $pdo->prepare("SELECT set_config('app.current_organization_id', :id, true)")->execute(['id' => $school]);
            $this->refused($pdo, fn () => $pdo->exec("UPDATE organization_memberships SET last_seen_at = NOW() WHERE id = '{$there}'"), '42501');
            $this->assertSame(0, $pdo->exec("UPDATE organization_memberships SET last_seen_at = NOW() WHERE id = '{$theirs}'"));
            $pdo->exec('RESET ROLE');

            $rows = $pdo->query("SELECT id, (last_login_at IS NOT NULL)::int AS login, (last_seen_at IS NOT NULL)::int AS seen FROM organization_memberships WHERE id IN ('{$here}', '{$there}', '{$theirs}')")
                ->fetchAll(\PDO::FETCH_UNIQUE | \PDO::FETCH_ASSOC);
            $this->assertEquals(['login' => 1, 'seen' => 1], $rows[$here]);
            $this->assertEquals(['login' => 1, 'seen' => 0], $rows[$there]);
            $this->assertEquals(['login' => 0, 'seen' => 0], $rows[$theirs]);
        } finally {
            DB::setDefaultConnection($default);
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
    }

    private function refused(\PDO $pdo, callable $statement, string $code): void
    {
        $savepoint = 'sp_'.Str::lower(Str::random(8));
        $pdo->exec("SAVEPOINT {$savepoint}");
        try {
            $statement();
            $this->fail('The database accepted a statement it must refuse.');
        } catch (\PDOException $exception) {
            $this->assertSame($code, $exception->getCode(), $exception->getMessage());
            $pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
        }
    }

    private function organization(\PDO $pdo): string
    {
        $id = (string) Str::uuid();
        $pdo->prepare("INSERT INTO organizations (id, name, slug, status, created_at, updated_at) VALUES (:id, :name, :slug, 'active', NOW(), NOW())")
            ->execute(['id' => $id, 'name' => 'Организация '.$id, 'slug' => 'org-'.$id]);

        return $id;
    }

    private function user(\PDO $pdo): string
    {
        $id = (string) Str::uuid();
        $pdo->prepare("INSERT INTO users (id, name, email, password, created_at, updated_at) VALUES (:id, 'Синтетичен човек', :email, 'x', NOW(), NOW())")
            ->execute(['id' => $id, 'email' => 'person-'.$id.'@example.test']);

        return $id;
    }

    private function membership(\PDO $pdo, string $organization, string $user): string
    {
        $id = (string) Str::uuid();
        $pdo->prepare("INSERT INTO organization_memberships (id, organization_id, user_id, role, status, created_at, updated_at) VALUES (:id, :organization, :user, 'instructor', 'active', NOW(), NOW())")
            ->execute(['id' => $id, 'organization' => $organization, 'user' => $user]);

        return $id;
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
