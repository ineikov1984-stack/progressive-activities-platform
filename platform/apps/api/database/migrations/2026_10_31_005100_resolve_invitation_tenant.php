<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Opening an invitation (docs/product/team.md, т. 7.4): the person has no organization yet, often no login, and
 * `invitations` is under RLS. The database gives only the organization's id for a token's hash; the invitation itself
 * is then read under that organization's RLS. Two tokens: the e-mail's (`token_hash`, it proves the address and may
 * make a new profile) and the link the inviter copies (`copy_token_hash`, it only joins an existing profile with that
 * address). Both are of 64 random characters and never shown again.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The function reads past RLS only when it belongs to the owner (docs/process/DECISIONS.md, "Миграции с ролята-собственик").
        if (DB::getDriverName() === 'pgsql') {
            $owner = DB::selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
            if ($owner !== null && ! $owner->rolsuper && ! $owner->rolbypassrls) {
                throw new RuntimeException('Run migrations as the database owner: php artisan migrate --database=pgsql_admin --force');
            }
        }

        Schema::table('invitations', function (Blueprint $table): void {
            $table->string('copy_token_hash', 64)->nullable()->unique();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION public.invitation_tenant(selected_token_hash text)
            RETURNS uuid LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, public AS $$
                SELECT i.organization_id FROM public.invitations i
                WHERE length(selected_token_hash) = 64
                  AND (i.token_hash = selected_token_hash OR i.copy_token_hash = selected_token_hash)
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
        Schema::table('invitations', function (Blueprint $table): void {
            $table->dropUnique(['copy_token_hash']);
            $table->dropColumn('copy_token_hash');
        });
    }
};
