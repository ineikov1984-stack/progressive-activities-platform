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
            CREATE FUNCTION public.enrollment_owned_tenant(selected_enrollment uuid, parent_id uuid)
            RETURNS uuid LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, public AS $$
                SELECT e.organization_id
                FROM public.enrollments e
                JOIN public.enrollment_applications a ON a.id = e.application_id AND a.organization_id = e.organization_id
                WHERE e.id = selected_enrollment AND a.parent_user_id = parent_id
                  AND parent_id = NULLIF(current_setting('app.current_user_id', true), '')::uuid
                  AND EXISTS (SELECT 1 FROM public.users u WHERE u.id = parent_id AND u.is_active)
            $$;
            REVOKE ALL ON FUNCTION public.enrollment_owned_tenant(uuid, uuid) FROM PUBLIC;
            SQL);
        $username = config('database.connections.pgsql.username');
        if (is_string($username) && $username !== '') {
            $role = '"'.str_replace('"', '""', $username).'"';
            DB::unprepared("GRANT EXECUTE ON FUNCTION public.enrollment_owned_tenant(uuid, uuid) TO {$role}");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS public.enrollment_owned_tenant(uuid, uuid)');
        }
    }
};
