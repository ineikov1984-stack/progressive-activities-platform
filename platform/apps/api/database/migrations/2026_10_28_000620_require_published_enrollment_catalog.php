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

        // CREATE OR REPLACE preserves the existing application-role-only execution grant.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.enrollment_catalog_tenant(selected_group uuid, selected_offer uuid, parent_id uuid)
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
                  AND ((g.status = 'published' AND a.status = 'published' AND (selected_offer IS NULL OR v.status = 'published')
                    AND EXISTS (SELECT 1 FROM public.organization_catalog_profiles profile
                        WHERE profile.organization_id = g.organization_id AND profile.is_published))
                    OR EXISTS (
                        SELECT 1 FROM public.enrollment_applications application
                        WHERE application.parent_user_id = parent_id AND application.organization_id = g.organization_id
                          AND ((selected_offer IS NULL AND application.group_id = g.id)
                            OR application.visit_offer_id = selected_offer)
                    ))
                LIMIT 1
            $$;
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $previous = require __DIR__.'/2026_10_28_000600_resolve_parent_enrollment_tenant.php';
            $previous->down();
            $previous->up();
        }
    }
};
