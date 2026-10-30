<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The team and the group assignments (docs/product/team.md, т. 3, 5, 12): a person of the team with or without a login,
 * the public teacher profile linked to that person, lead or assistant with a period in a group, and whether a session's
 * teachers come from the assignments or were changed by hand. Existing memberships and teacher profiles get a person.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Migrations run as the database owner (docs/process/DECISIONS.md, "Миграции с ролята-собственик").
        if (DB::getDriverName() === 'pgsql') {
            $role = DB::selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
            if ($role !== null && ! $role->rolsuper && ! $role->rolbypassrls) {
                throw new RuntimeException('Run migrations as the database owner: php artisan migrate --database=pgsql_admin --force');
            }
        }

        Schema::create('staff_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('full_name', 255);
            // Never public and never logged (docs/product/team.md, т. 11).
            $table->string('phone', 32)->nullable();
            $table->string('email', 254)->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('archived_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'full_name']);
        });
        // One person of the team per user in an organization. Same SQL on PostgreSQL and SQLite.
        DB::unprepared('CREATE UNIQUE INDEX staff_members_organization_user_unique ON staff_members (organization_id, user_id) WHERE user_id IS NOT NULL');

        Schema::table('instructor_profiles', function (Blueprint $table): void {
            $table->uuid('staff_member_id')->nullable()->unique();
        });
        Schema::table('invitations', function (Blueprint $table): void {
            $table->uuid('staff_member_id')->nullable()->index();
        });
        Schema::table('activity_group_instructors', function (Blueprint $table): void {
            $table->string('role', 16)->default('lead');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('created_by')->nullable();
            $table->index('instructor_profile_id');
        });
        Schema::table('activity_session_instructors', function (Blueprint $table): void {
            $table->string('role', 16)->default('lead');
            // "assignment": follows the group's assignments; "manual": changed by hand for this session (т. 5.2).
            $table->string('source', 16)->default('assignment');
        });

        $this->backfillPeople();
        $this->backfillSessionSources();

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE staff_members ADD CONSTRAINT staff_members_id_organization_unique UNIQUE (id, organization_id);
            ALTER TABLE staff_members ENABLE ROW LEVEL SECURITY;
            ALTER TABLE staff_members FORCE ROW LEVEL SECURITY;
            CREATE POLICY staff_members_tenant_policy ON staff_members
                USING (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid)
                WITH CHECK (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid);

            ALTER TABLE instructor_profiles ADD CONSTRAINT instructor_profiles_staff_member_tenant_fk
                FOREIGN KEY (staff_member_id, organization_id) REFERENCES staff_members (id, organization_id) ON DELETE SET NULL (staff_member_id);
            ALTER TABLE invitations ADD CONSTRAINT invitations_staff_member_tenant_fk
                FOREIGN KEY (staff_member_id, organization_id) REFERENCES staff_members (id, organization_id) ON DELETE SET NULL (staff_member_id);

            ALTER TABLE activity_group_instructors ADD CONSTRAINT activity_group_instructors_role_valid CHECK (role IN ('lead', 'assistant'));
            ALTER TABLE activity_group_instructors ADD CONSTRAINT activity_group_instructors_period_valid
                CHECK (starts_on IS NULL OR ends_on IS NULL OR starts_on <= ends_on);
            ALTER TABLE activity_group_instructors ADD CONSTRAINT activity_group_instructors_created_by_fk
                FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL;
            ALTER TABLE activity_session_instructors ADD CONSTRAINT activity_session_instructors_role_valid CHECK (role IN ('lead', 'assistant'));
            ALTER TABLE activity_session_instructors ADD CONSTRAINT activity_session_instructors_source_valid CHECK (source IN ('assignment', 'manual'));
            SQL);

        $username = config('database.connections.pgsql.username');
        if (is_string($username) && $username !== '') {
            $role = '"'.str_replace('"', '""', $username).'"';
            // A person of the team is archived, never deleted (т. 6); the default privileges give every row operation.
            DB::unprepared("REVOKE ALL ON staff_members FROM {$role}");
            DB::unprepared("GRANT SELECT, INSERT, UPDATE ON staff_members TO {$role}");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE activity_session_instructors DROP CONSTRAINT IF EXISTS activity_session_instructors_source_valid;
                ALTER TABLE activity_session_instructors DROP CONSTRAINT IF EXISTS activity_session_instructors_role_valid;
                ALTER TABLE activity_group_instructors DROP CONSTRAINT IF EXISTS activity_group_instructors_created_by_fk;
                ALTER TABLE activity_group_instructors DROP CONSTRAINT IF EXISTS activity_group_instructors_period_valid;
                ALTER TABLE activity_group_instructors DROP CONSTRAINT IF EXISTS activity_group_instructors_role_valid;
                ALTER TABLE invitations DROP CONSTRAINT IF EXISTS invitations_staff_member_tenant_fk;
                ALTER TABLE instructor_profiles DROP CONSTRAINT IF EXISTS instructor_profiles_staff_member_tenant_fk;
                SQL);
        }

        // An ended assignment would become current again without its period (docs/product/team.md, т. 12).
        DB::table('activity_group_instructors')->whereNotNull('ends_on')->where('ends_on', '<', now()->toDateString())->delete();

        Schema::table('activity_session_instructors', function (Blueprint $table): void {
            $table->dropColumn(['role', 'source']);
        });
        Schema::table('activity_group_instructors', function (Blueprint $table): void {
            $table->dropIndex(['instructor_profile_id']);
            $table->dropColumn(['role', 'starts_on', 'ends_on', 'version', 'created_by']);
        });
        Schema::table('invitations', function (Blueprint $table): void {
            $table->dropIndex(['staff_member_id']);
            $table->dropColumn('staff_member_id');
        });
        Schema::table('instructor_profiles', function (Blueprint $table): void {
            $table->dropUnique(['staff_member_id']);
            $table->dropColumn('staff_member_id');
        });
        Schema::dropIfExists('staff_members');
    }

    /** A person for every member of a team (not the platform operator) and for every teacher profile. */
    private function backfillPeople(): void
    {
        $now = now();
        $members = DB::table('organization_memberships')
            ->join('users', 'users.id', '=', 'organization_memberships.user_id')
            ->where('organization_memberships.role', '<>', 'platform_admin')
            ->select('organization_memberships.organization_id', 'organization_memberships.user_id', 'users.name', 'users.email', 'users.phone')
            ->orderBy('organization_memberships.created_at')
            ->get();
        foreach ($members as $member) {
            DB::table('staff_members')->insert([
                'id' => (string) Str::uuid(), 'organization_id' => $member->organization_id, 'full_name' => $member->name,
                'phone' => $member->phone, 'email' => $member->email, 'user_id' => $member->user_id,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach (DB::table('instructor_profiles')->select('id', 'organization_id', 'user_id', 'public_name')->orderBy('created_at')->get() as $profile) {
            $person = $profile->user_id === null ? null : DB::table('staff_members')
                ->where('organization_id', $profile->organization_id)->where('user_id', $profile->user_id)->value('id');
            if ($person !== null && DB::table('instructor_profiles')->where('staff_member_id', $person)->exists()) {
                $person = null;
            }
            if ($person === null) {
                $person = (string) Str::uuid();
                DB::table('staff_members')->insert([
                    'id' => $person, 'organization_id' => $profile->organization_id, 'full_name' => $profile->public_name,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            DB::table('instructor_profiles')->where('id', $profile->id)->update(['staff_member_id' => $person]);
        }
    }

    /** A session whose teachers differ from its group's was changed by hand. */
    private function backfillSessionSources(): void
    {
        $groupTeachers = DB::table('activity_group_instructors')->get(['group_id', 'instructor_profile_id'])
            ->groupBy('group_id')->map(fn ($rows) => $rows->pluck('instructor_profile_id')->sort()->values()->all());
        $sessions = DB::table('activity_session_instructors')
            ->join('activity_sessions', 'activity_sessions.id', '=', 'activity_session_instructors.session_id')
            ->get(['activity_session_instructors.session_id', 'activity_sessions.group_id', 'activity_session_instructors.instructor_profile_id'])
            ->groupBy('session_id');
        foreach ($sessions as $sessionId => $rows) {
            $own = $rows->pluck('instructor_profile_id')->sort()->values()->all();
            if ($own !== ($groupTeachers[$rows->first()->group_id] ?? [])) {
                DB::table('activity_session_instructors')->where('session_id', $sessionId)->update(['source' => 'manual']);
            }
        }
    }
};
