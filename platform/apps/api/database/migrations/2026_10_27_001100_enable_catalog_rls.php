<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PRIVATE_TABLES = [
        'activities',
        'offerings',
        'activity_groups',
        'activity_sessions',
        'locations',
        'instructor_profiles',
        'price_options',
        'activity_group_instructors',
        'activity_session_instructors',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::PRIVATE_TABLES as $table) {
            DB::unprepared("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::unprepared("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::unprepared(<<<SQL
                CREATE POLICY {$table}_tenant_policy ON {$table}
                    USING (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid)
                    WITH CHECK (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid)
                SQL);
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE organization_catalog_profiles ENABLE ROW LEVEL SECURITY;
            ALTER TABLE organization_catalog_profiles FORCE ROW LEVEL SECURITY;
            CREATE POLICY organization_catalog_profiles_select_policy ON organization_catalog_profiles
                FOR SELECT USING (
                    organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid
                    OR (
                        NULLIF(current_setting('app.current_organization_id', true), '') IS NULL
                        AND is_published = true
                    )
                );
            CREATE POLICY organization_catalog_profiles_insert_policy ON organization_catalog_profiles
                FOR INSERT WITH CHECK (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid);
            CREATE POLICY organization_catalog_profiles_update_policy ON organization_catalog_profiles
                FOR UPDATE
                USING (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid)
                WITH CHECK (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid);
            CREATE POLICY organization_catalog_profiles_delete_policy ON organization_catalog_profiles
                FOR DELETE USING (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid);
            SQL);

        $username = config('database.connections.pgsql.username');
        if (is_string($username) && $username !== '') {
            $role = '"'.str_replace('"', '""', $username).'"';
            $tables = implode(', ', [...self::PRIVATE_TABLES, 'organization_catalog_profiles']);
            DB::unprepared("GRANT USAGE ON SCHEMA public TO {$role}");
            DB::unprepared("GRANT SELECT, INSERT, UPDATE, DELETE ON {$tables} TO {$role}");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP POLICY IF EXISTS organization_catalog_profiles_delete_policy ON organization_catalog_profiles;
            DROP POLICY IF EXISTS organization_catalog_profiles_update_policy ON organization_catalog_profiles;
            DROP POLICY IF EXISTS organization_catalog_profiles_insert_policy ON organization_catalog_profiles;
            DROP POLICY IF EXISTS organization_catalog_profiles_select_policy ON organization_catalog_profiles;
            ALTER TABLE organization_catalog_profiles DISABLE ROW LEVEL SECURITY;
            SQL);

        foreach (array_reverse(self::PRIVATE_TABLES) as $table) {
            DB::unprepared("DROP POLICY IF EXISTS {$table}_tenant_policy ON {$table}");
            DB::unprepared("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
