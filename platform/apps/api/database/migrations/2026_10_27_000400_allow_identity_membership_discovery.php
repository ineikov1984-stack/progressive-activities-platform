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
            DROP POLICY IF EXISTS organizations_tenant_policy ON organizations;
            DROP POLICY IF EXISTS organization_memberships_tenant_policy ON organization_memberships;

            CREATE POLICY organization_memberships_tenant_policy ON organization_memberships
                USING (
                    organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid
                    OR user_id = NULLIF(current_setting('app.current_user_id', true), '')::uuid
                )
                WITH CHECK (
                    organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid
                );

            CREATE POLICY organizations_tenant_policy ON organizations
                USING (
                    id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid
                    OR EXISTS (
                        SELECT 1
                        FROM organization_memberships membership
                        WHERE membership.organization_id = organizations.id
                          AND membership.user_id = NULLIF(current_setting('app.current_user_id', true), '')::uuid
                          AND membership.status = 'active'
                    )
                )
                WITH CHECK (
                    id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid
                );
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP POLICY IF EXISTS organizations_tenant_policy ON organizations;
            DROP POLICY IF EXISTS organization_memberships_tenant_policy ON organization_memberships;

            CREATE POLICY organizations_tenant_policy ON organizations
                USING (id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid)
                WITH CHECK (id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid);

            CREATE POLICY organization_memberships_tenant_policy ON organization_memberships
                USING (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid)
                WITH CHECK (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid);
            SQL);
    }
};
