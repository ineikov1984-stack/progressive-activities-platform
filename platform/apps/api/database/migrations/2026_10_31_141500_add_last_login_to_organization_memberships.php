<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Последен вход" and "Последна активност" in "Екип" (DECISIONS 31.10.2026 „Екип — последен вход и филтър по роли“):
 * when the person last signed in or selected the organization, and when they last used it — written by the system,
 * at most once in five minutes, without touching `updated_at`. Both start empty: "още не е влизал" until the next
 * sign-in. The application role already updates `organization_memberships` (infra/docker/postgres-init), so no right
 * is added; RLS keeps each row to its organization.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->assertOwner();

        Schema::table('organization_memberships', function (Blueprint $table): void {
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        $this->assertOwner();

        Schema::table('organization_memberships', function (Blueprint $table): void {
            $table->dropColumn(['last_login_at', 'last_seen_at']);
        });
    }

    /** Migrations run as the database owner (docs/process/DECISIONS.md, "Миграции с ролята-собственик"), both ways. */
    private function assertOwner(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $role = DB::selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
            if ($role !== null && ! $role->rolsuper && ! $role->rolbypassrls) {
                throw new RuntimeException('Run migrations as the database owner: php artisan migrate --database=pgsql_admin --force');
            }
        }
    }
};
