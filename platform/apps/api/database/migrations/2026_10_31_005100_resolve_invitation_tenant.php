<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Opening an invitation link (docs/product/team.md, т. 7.4): the person has no organization yet, often no login, and
 * `invitations` is under RLS. The database gives only the organization's id for a token's hash; the invitation itself
 * is then read under that organization's RLS. The hash is of a 64-character random token and is never shown.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION public.invitation_tenant(selected_token_hash text)
            RETURNS uuid LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, public AS $$
                SELECT i.organization_id FROM public.invitations i
                WHERE i.token_hash = selected_token_hash AND length(selected_token_hash) = 64
            $$;
            REVOKE ALL ON FUNCTION public.invitation_tenant(text) FROM PUBLIC;
            SQL);
        $username = config('database.connections.pgsql.username');
        if (is_string($username) && $username !== '') {
            $role = '"'.str_replace('"', '""', $username).'"';
            DB::unprepared("GRANT EXECUTE ON FUNCTION public.invitation_tenant(text) TO {$role}");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS public.invitation_tenant(text)');
        }
    }
};
