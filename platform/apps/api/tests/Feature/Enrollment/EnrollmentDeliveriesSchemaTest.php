<?php

namespace Tests\Feature\Enrollment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Tests\TestCase;

/** Stage Б data (docs/product/enrollment-contacts-and-emails.md, т. 4): deliveries under RLS and the sender's tenant lookup. */
class EnrollmentDeliveriesSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_contact_and_delivery_columns_exist(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasColumns('enrollment_event_deliveries', [
            'organization_id', 'event_id', 'audience', 'recipient_kind', 'recipient_key', 'recipient_user_id',
            'status', 'skipped_reason', 'attempts', 'next_attempt_at', 'locked_until', 'sent_at', 'last_error',
        ]));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('enrollment_events', 'expanded_at'));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('enrollment_applications', 'parent_note'));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('users', 'phone'));
    }

    #[TestGroup('tenant-isolation')]
    public function test_deliveries_stay_in_their_organization_and_the_sender_learns_only_identifiers(): void
    {
        if (! $this->postgresIsAvailable()) {
            $this->markTestSkipped('PostgreSQL is required for the database-layer delivery isolation check.');
        }

        Artisan::call('migrate', ['--database' => 'pgsql_admin', '--force' => true]);
        $pdo = DB::connection('pgsql_admin')->getPdo();
        $pdo->beginTransaction();

        try {
            $waiting = $this->organizationWithEvent($pdo, expanded: false);
            $pending = $this->organizationWithEvent($pdo, expanded: true, delivery: 'pending');
            $done = $this->organizationWithEvent($pdo, expanded: true, delivery: 'sent');

            $pdo->exec('SET LOCAL ROLE progressive_app');
            $pdo->prepare("SELECT set_config('app.current_organization_id', '', true)")->execute();

            // Without a tenant the sender sees no rows, only which organizations have work.
            $this->assertSame([], $pdo->query('SELECT id FROM enrollment_event_deliveries')->fetchAll(\PDO::FETCH_COLUMN));
            $tenants = $pdo->query('SELECT * FROM public.pending_enrollment_delivery_tenants()')->fetchAll(\PDO::FETCH_COLUMN);
            $this->assertContains($waiting['organization'], $tenants, 'An event not yet expanded is work.');
            $this->assertContains($pending['organization'], $tenants, 'A pending delivery is work.');
            $this->assertNotContains($done['organization'], $tenants, 'Sent deliveries are not work.');
            $this->assertSame(count($tenants), count(array_unique($tenants)));

            $pdo->prepare("SELECT set_config('app.current_organization_id', :id, true)")->execute(['id' => $pending['organization']]);
            $this->assertSame([$pending['delivery']], $pdo->query('SELECT id FROM enrollment_event_deliveries')->fetchAll(\PDO::FETCH_COLUMN));

            $pdo->prepare("SELECT set_config('app.current_organization_id', :id, true)")->execute(['id' => $waiting['organization']]);
            $this->assertSame([], $pdo->query('SELECT id FROM enrollment_event_deliveries')->fetchAll(\PDO::FETCH_COLUMN));
            // A delivery of this organization cannot point at another organization's event (composite key)…
            $pdo->exec('SAVEPOINT mixed_tenant');
            try {
                $this->insertDelivery($pdo, $waiting['organization'], $pending['event'], 'pending', 'user:mixed');
                $this->fail('A delivery must not point at another organization\'s event.');
            } catch (\PDOException $exception) {
                $this->assertSame('23503', $exception->getCode());
                $pdo->exec('ROLLBACK TO SAVEPOINT mixed_tenant');
            }
            // …and nobody can write a row for another organization.
            $pdo->exec('SAVEPOINT foreign_insert');
            try {
                $this->insertDelivery($pdo, $pending['organization'], $pending['event'], 'pending', 'user:other');
                $this->fail('A delivery for another organization must not be insertable.');
            } catch (\PDOException) {
                $pdo->exec('ROLLBACK TO SAVEPOINT foreign_insert');
            }
            // One delivery per event, audience and recipient.
            $this->insertDelivery($pdo, $waiting['organization'], $waiting['event'], 'pending', 'organization_contact');
            $pdo->exec('SAVEPOINT duplicate');
            try {
                $this->insertDelivery($pdo, $waiting['organization'], $waiting['event'], 'pending', 'organization_contact');
                $this->fail('The same recipient must not get a second delivery for one event.');
            } catch (\PDOException $exception) {
                $this->assertSame('23505', $exception->getCode());
                $pdo->exec('ROLLBACK TO SAVEPOINT duplicate');
            }
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    /** @return array{organization: string, event: string, delivery: string|null} */
    private function organizationWithEvent(\PDO $pdo, bool $expanded, ?string $delivery = null): array
    {
        $organization = (string) Str::uuid();
        $event = (string) Str::uuid();
        $pdo->prepare("INSERT INTO organizations (id, name, slug, status, created_at, updated_at) VALUES (:id, :name, :slug, 'active', NOW(), NOW())")
            ->execute(['id' => $organization, 'name' => 'Организация '.$organization, 'slug' => 'org-'.$organization]);
        $pdo->prepare('INSERT INTO enrollment_events (id, organization_id, type, payload, occurred_at, expanded_at, attempts, created_at, updated_at) '
            ."VALUES (:id, :organization, 'enrollment.application_approved', '{}', NOW(), ".($expanded ? 'NOW()' : 'NULL').', 0, NOW(), NOW())')
            ->execute(['id' => $event, 'organization' => $organization]);
        $deliveryId = $delivery === null ? null : $this->insertDelivery($pdo, $organization, $event, $delivery);

        return ['organization' => $organization, 'event' => $event, 'delivery' => $deliveryId];
    }

    private function insertDelivery(\PDO $pdo, string $organization, string $event, string $status, string $key = 'organization_contact'): string
    {
        $id = (string) Str::uuid();
        $pdo->prepare('INSERT INTO enrollment_event_deliveries (id, organization_id, event_id, audience, recipient_kind, recipient_key, status, attempts, created_at, updated_at) '
            ."VALUES (:id, :organization, :event, 'team', 'organization_contact', :key, :status, 0, NOW(), NOW())")
            ->execute(['id' => $id, 'organization' => $organization, 'event' => $event, 'key' => $key, 'status' => $status]);

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
