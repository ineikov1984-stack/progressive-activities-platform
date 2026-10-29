<?php

namespace Database\Seeders;

use App\Domain\Organizations\Models\Organization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A second, unrelated organization for cross-tenant browser tests (CI job "e2e-stack").
 * The demo owner has no membership here, so nothing of it may ever be visible to them.
 */
class E2eForeignTenantSeeder extends Seeder
{
    public const ORGANIZATION_ID = '00000000-0000-4000-8000-000000000103';

    public const ACTIVITY_ID = '00000000-0000-4000-8000-000000000301';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('E2E test data cannot be seeded in this environment.');
        }

        DB::transaction(function (): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::select("SELECT set_config('app.current_organization_id', ?, true)", [self::ORGANIZATION_ID]);
            }

            Organization::query()->withoutGlobalScopes()->updateOrCreate(
                ['id' => self::ORGANIZATION_ID],
                [
                    'name' => 'Чужда организация (e2e)',
                    'slug' => 'e2e-foreign-organization',
                    'status' => 'active',
                ],
            );

            $now = now();
            DB::table('activities')->upsert([[
                'id' => self::ACTIVITY_ID,
                'organization_id' => self::ORGANIZATION_ID,
                'slug' => 'e2e-chuzhda-deinost',
                'title' => 'Чужда дейност',
                'category' => 'Друго',
                'summary' => 'Не трябва да се вижда от други организации.',
                'age_label' => '7–10 г.',
                'benefits' => json_encode([], JSON_THROW_ON_ERROR),
                'faq' => json_encode([], JSON_THROW_ON_ERROR),
                'gallery' => json_encode([], JSON_THROW_ON_ERROR),
                'status' => 'draft',
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]], ['id'], ['title', 'updated_at']);
        });
    }
}
