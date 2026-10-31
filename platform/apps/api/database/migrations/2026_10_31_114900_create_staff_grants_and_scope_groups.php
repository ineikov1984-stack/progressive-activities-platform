<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rights in the team (docs/product/team-rights.md, т. 4, 11): the person's scope ("own", "groups", "all"; empty is the
 * role's default) and the label of a template, the selected groups and activities of the scope, and the rights added
 * to a person on top of the role. Nothing is deleted: a right is revoked, a group is removed, both with who and when.
 * The tables start empty, so the rights of every existing person stay those of their role.
 */
return new class extends Migration
{
    /** Exactly the rights that are given on top of a role (т. 2). */
    private const GRANTABLE = [
        'enrollment.enroll', 'attendance.mark', 'attendance.view_month', 'schedule.view_all', 'schedule.manage',
        'contacts.view', 'contacts.message', 'team.view', 'team.assign', 'finance.view', 'finance.collect',
        'finance.pay_view', 'catalog.manage', 'catalog.publish',
    ];

    public function up(): void
    {
        $this->assertOwner();

        Schema::table('staff_members', function (Blueprint $table): void {
            $table->string('access_scope', 16)->nullable();
            $table->string('access_template', 32)->nullable();
        });

        Schema::create('staff_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('staff_member_id');
            $table->string('ability', 32);
            // Who acted stays in the history: a profile is stopped, never deleted.
            $table->foreignUuid('granted_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('granted_at');
            // "до 30 юни 2027" is kept as the start of 1 July 2027 in Sofia.
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignUuid('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('revoked_reason', 16)->nullable();
            $table->timestamps();
            $table->index(['staff_member_id', 'revoked_at']);
        });
        // One open right of a kind per person. Same SQL on PostgreSQL and SQLite.
        DB::unprepared('CREATE UNIQUE INDEX staff_grants_open_unique ON staff_grants (organization_id, staff_member_id, ability) WHERE revoked_at IS NULL');

        Schema::create('staff_scope_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('staff_member_id');
            // A group, or a whole activity with its later groups.
            $table->uuid('group_id')->nullable();
            $table->uuid('activity_id')->nullable();
            $table->foreignUuid('added_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('added_at');
            $table->timestampTz('removed_at')->nullable();
            $table->foreignUuid('removed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['staff_member_id', 'removed_at']);
        });
        DB::unprepared('CREATE UNIQUE INDEX staff_scope_groups_open_group_unique ON staff_scope_groups (staff_member_id, group_id) WHERE removed_at IS NULL AND group_id IS NOT NULL');
        DB::unprepared('CREATE UNIQUE INDEX staff_scope_groups_open_activity_unique ON staff_scope_groups (staff_member_id, activity_id) WHERE removed_at IS NULL AND activity_id IS NOT NULL');

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $abilities = implode(', ', array_map(fn (string $ability): string => "'{$ability}'", self::GRANTABLE));
        DB::unprepared(<<<SQL
            ALTER TABLE staff_members ADD CONSTRAINT staff_members_access_scope_valid CHECK (access_scope IN ('own', 'groups', 'all'));
            ALTER TABLE staff_members ADD CONSTRAINT staff_members_access_template_valid CHECK (access_template IN ('school_partner'));

            ALTER TABLE staff_grants ADD CONSTRAINT staff_grants_id_organization_unique UNIQUE (id, organization_id);
            ALTER TABLE staff_grants ADD CONSTRAINT staff_grants_staff_member_tenant_fk
                FOREIGN KEY (staff_member_id, organization_id) REFERENCES staff_members (id, organization_id);
            ALTER TABLE staff_grants ADD CONSTRAINT staff_grants_ability_valid CHECK (ability IN ({$abilities}));
            ALTER TABLE staff_grants ADD CONSTRAINT staff_grants_expiry_valid CHECK (expires_at IS NULL OR expires_at > granted_at);
            ALTER TABLE staff_grants ADD CONSTRAINT staff_grants_revocation_valid CHECK ((revoked_at IS NULL) = (revoked_reason IS NULL));
            -- Who revoked is kept; only an expired right may be closed without anyone (by the system).
            ALTER TABLE staff_grants ADD CONSTRAINT staff_grants_revoked_by_valid CHECK (
                (revoked_at IS NULL AND revoked_by IS NULL)
                OR (revoked_at IS NOT NULL AND (revoked_by IS NOT NULL OR revoked_reason = 'expired')));
            ALTER TABLE staff_grants ADD CONSTRAINT staff_grants_revoked_reason_valid
                CHECK (revoked_reason IN ('removed', 'role_changed', 'scope_changed', 'archived', 'expired'));
            ALTER TABLE staff_grants ENABLE ROW LEVEL SECURITY;
            ALTER TABLE staff_grants FORCE ROW LEVEL SECURITY;
            CREATE POLICY staff_grants_tenant_policy ON staff_grants
                USING (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid)
                WITH CHECK (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid);

            ALTER TABLE staff_scope_groups ADD CONSTRAINT staff_scope_groups_staff_member_tenant_fk
                FOREIGN KEY (staff_member_id, organization_id) REFERENCES staff_members (id, organization_id);
            ALTER TABLE staff_scope_groups ADD CONSTRAINT staff_scope_groups_group_tenant_fk
                FOREIGN KEY (group_id, organization_id) REFERENCES activity_groups (id, organization_id);
            ALTER TABLE staff_scope_groups ADD CONSTRAINT staff_scope_groups_activity_tenant_fk
                FOREIGN KEY (activity_id, organization_id) REFERENCES activities (id, organization_id);
            ALTER TABLE staff_scope_groups ADD CONSTRAINT staff_scope_groups_one_target CHECK (num_nonnulls(group_id, activity_id) = 1);
            ALTER TABLE staff_scope_groups ADD CONSTRAINT staff_scope_groups_removal_valid CHECK ((removed_at IS NULL) = (removed_by IS NULL));
            ALTER TABLE staff_scope_groups ENABLE ROW LEVEL SECURITY;
            ALTER TABLE staff_scope_groups FORCE ROW LEVEL SECURITY;
            CREATE POLICY staff_scope_groups_tenant_policy ON staff_scope_groups
                USING (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid)
                WITH CHECK (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid);

            -- Also for the owner role, which the rights per column do not bind: who, what and when it was given stay;
            -- a closed row stays closed; an expired right keeps its end and may only be closed as expired.
            CREATE FUNCTION protect_staff_grants() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                    OR NEW.staff_member_id IS DISTINCT FROM OLD.staff_member_id OR NEW.ability IS DISTINCT FROM OLD.ability
                    OR NEW.granted_by IS DISTINCT FROM OLD.granted_by OR NEW.granted_at IS DISTINCT FROM OLD.granted_at
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'a granted right keeps who, what and when' USING ERRCODE = 'check_violation';
                END IF;
                IF OLD.revoked_at IS NOT NULL THEN
                    RAISE EXCEPTION 'a revoked right is not changed' USING ERRCODE = 'check_violation';
                END IF;
                IF OLD.expires_at IS NOT NULL AND OLD.expires_at <= now() AND NEW.expires_at IS DISTINCT FROM OLD.expires_at THEN
                    RAISE EXCEPTION 'an expired right keeps its end' USING ERRCODE = 'check_violation';
                END IF;
                IF OLD.expires_at IS NOT NULL AND OLD.expires_at <= now() AND NEW.revoked_at IS NOT NULL
                    AND NEW.revoked_reason IS DISTINCT FROM 'expired' THEN
                    RAISE EXCEPTION 'an expired right is closed as expired' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END \$\$;
            CREATE TRIGGER staff_grants_protect BEFORE UPDATE ON staff_grants FOR EACH ROW EXECUTE FUNCTION protect_staff_grants();

            CREATE FUNCTION protect_staff_scope_groups() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                    OR NEW.staff_member_id IS DISTINCT FROM OLD.staff_member_id OR NEW.group_id IS DISTINCT FROM OLD.group_id
                    OR NEW.activity_id IS DISTINCT FROM OLD.activity_id OR NEW.added_by IS DISTINCT FROM OLD.added_by
                    OR NEW.added_at IS DISTINCT FROM OLD.added_at OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'a selected group keeps who, what and when' USING ERRCODE = 'check_violation';
                END IF;
                IF OLD.removed_at IS NOT NULL THEN
                    RAISE EXCEPTION 'a removed group is not changed' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END \$\$;
            CREATE TRIGGER staff_scope_groups_protect BEFORE UPDATE ON staff_scope_groups FOR EACH ROW EXECUTE FUNCTION protect_staff_scope_groups();
            SQL);

        $username = config('database.connections.pgsql.username');
        if (is_string($username) && $username !== '') {
            $role = '"'.str_replace('"', '""', $username).'"';
            // Revoking is a date, removing a group is a date: no DELETE, and only those columns change.
            DB::unprepared("REVOKE ALL ON staff_grants FROM {$role}");
            DB::unprepared("GRANT SELECT, INSERT ON staff_grants TO {$role}");
            DB::unprepared("GRANT UPDATE (expires_at, revoked_at, revoked_by, revoked_reason, updated_at) ON staff_grants TO {$role}");
            DB::unprepared("REVOKE ALL ON staff_scope_groups FROM {$role}");
            DB::unprepared("GRANT SELECT, INSERT ON staff_scope_groups TO {$role}");
            DB::unprepared("GRANT UPDATE (removed_at, removed_by, updated_at) ON staff_scope_groups TO {$role}");
        }
    }

    public function down(): void
    {
        $this->assertOwner();
        // The history of rights is never dropped quietly (т. 11).
        if (DB::table('staff_grants')->exists() || DB::table('staff_scope_groups')->exists()
            || DB::table('staff_members')->whereNotNull('access_scope')->orWhereNotNull('access_template')->exists()) {
            throw new RuntimeException('Rights in the team are in use: staff_grants, staff_scope_groups or staff_members.access_* hold data.');
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS staff_scope_groups_protect ON staff_scope_groups;
                DROP TRIGGER IF EXISTS staff_grants_protect ON staff_grants;
                DROP FUNCTION IF EXISTS protect_staff_scope_groups();
                DROP FUNCTION IF EXISTS protect_staff_grants();
                ALTER TABLE staff_members DROP CONSTRAINT IF EXISTS staff_members_access_template_valid;
                ALTER TABLE staff_members DROP CONSTRAINT IF EXISTS staff_members_access_scope_valid;
                SQL);
        }
        Schema::dropIfExists('staff_scope_groups');
        Schema::dropIfExists('staff_grants');
        Schema::table('staff_members', function (Blueprint $table): void {
            $table->dropColumn(['access_scope', 'access_template']);
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
