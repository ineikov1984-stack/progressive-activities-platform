<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE organizations ENABLE ROW LEVEL SECURITY;
            ALTER TABLE organizations FORCE ROW LEVEL SECURITY;
            CREATE POLICY organizations_tenant_policy ON organizations
                USING (id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid)
                WITH CHECK (id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid);

            ALTER TABLE organization_memberships ENABLE ROW LEVEL SECURITY;
            ALTER TABLE organization_memberships FORCE ROW LEVEL SECURITY;
            CREATE POLICY organization_memberships_tenant_policy ON organization_memberships
                USING (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid)
                WITH CHECK (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid);
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP POLICY IF EXISTS organization_memberships_tenant_policy ON organization_memberships;
            ALTER TABLE organization_memberships DISABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS organizations_tenant_policy ON organizations;
            ALTER TABLE organizations DISABLE ROW LEVEL SECURITY;
            SQL);
    }
};
