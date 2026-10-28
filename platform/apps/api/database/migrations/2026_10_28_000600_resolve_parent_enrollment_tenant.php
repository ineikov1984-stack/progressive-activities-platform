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

        // Only a scalar tenant identifier is exposed; all subsequent reads use tenant RLS.
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION public.enrollment_catalog_tenant(selected_group uuid, selected_offer uuid, parent_id uuid)
            RETURNS uuid LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, public AS $$
                SELECT g.organization_id
                FROM public.activity_groups g
                JOIN public.offerings offering ON offering.id = g.offering_id AND offering.organization_id = g.organization_id
                JOIN public.activities a ON a.id = offering.activity_id AND a.organization_id = g.organization_id
                JOIN public.organizations org ON org.id = g.organization_id
                LEFT JOIN public.visit_offers v ON v.id = selected_offer AND v.group_id = g.id AND v.organization_id = g.organization_id
                WHERE parent_id = NULLIF(current_setting('app.current_user_id', true), '')::uuid
                  AND EXISTS (SELECT 1 FROM public.users u WHERE u.id = parent_id AND u.is_active)
                  AND org.status = 'active'
                  AND ((selected_offer IS NULL AND selected_group = g.id)
                    OR (selected_group IS NULL AND selected_offer = v.id))
                  AND ((g.status = 'published' AND a.status = 'published' AND (selected_offer IS NULL OR v.status = 'published'))
                    OR EXISTS (
                        SELECT 1 FROM public.enrollment_applications application
                        WHERE application.parent_user_id = parent_id AND application.organization_id = g.organization_id
                          AND ((selected_offer IS NULL AND application.group_id = g.id)
                            OR application.visit_offer_id = selected_offer)
                    ))
                LIMIT 1
            $$;
            REVOKE ALL ON FUNCTION public.enrollment_catalog_tenant(uuid, uuid, uuid) FROM PUBLIC;
            SQL);
        $username = config('database.connections.pgsql.username');
        if (is_string($username) && $username !== '') {
            $role = '"'.str_replace('"', '""', $username).'"';
            DB::unprepared("GRANT EXECUTE ON FUNCTION public.enrollment_catalog_tenant(uuid, uuid, uuid) TO {$role}");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS public.enrollment_catalog_tenant(uuid, uuid, uuid)');
        }
    }
};
