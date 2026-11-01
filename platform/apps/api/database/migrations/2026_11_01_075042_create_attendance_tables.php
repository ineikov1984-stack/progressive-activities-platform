<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['attendance_marks', 'attendance_mark_changes', 'attendance_sessions', 'attendance_session_changes'];

    public function up(): void
    {
        $this->assertOwner();
        Schema::create('attendance_marks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('session_id');
            $table->uuid('enrollment_id');
            $table->string('status', 16)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignUuid('marked_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('marked_at');
            $table->timestampsTz();
            $table->unique(['id', 'organization_id']);
            $table->unique(['session_id', 'enrollment_id']);
            $table->index('enrollment_id');
            if (DB::getDriverName() !== 'pgsql') {
                $table->foreign('session_id')->references('id')->on('activity_sessions')->noActionOnDelete();
                $table->foreign('enrollment_id')->references('id')->on('enrollments')->cascadeOnDelete();
            }
        });
        Schema::create('attendance_mark_changes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('mark_id');
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16)->nullable();
            $table->unsignedInteger('version');
            $table->string('kind', 16);
            $table->text('reason')->nullable();
            $table->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('request_key', 120);
            $table->string('request_hash', 64);
            $table->timestampTz('created_at');
            $table->unique(['mark_id', 'version']);
            $table->index(['organization_id', 'actor_user_id', 'request_key'], 'attendance_mark_changes_actor_request');
            $table->foreign(['mark_id', 'organization_id'], 'attendance_mark_changes_mark_tenant_fk')
                ->references(['id', 'organization_id'])->on('attendance_marks')->cascadeOnDelete();
        });
        Schema::create('attendance_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('session_id')->unique();
            $table->text('topic')->nullable();
            $table->timestampTz('not_held_at')->nullable();
            $table->foreignUuid('not_held_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('not_held_reason')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->unique(['id', 'organization_id']);
            if (DB::getDriverName() !== 'pgsql') {
                $table->foreign('session_id')->references('id')->on('activity_sessions')->noActionOnDelete();
            }
        });
        Schema::create('attendance_session_changes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('attendance_session_id');
            $table->string('action', 16);
            $table->text('reason');
            $table->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->timestampTz('created_at');
            $table->unique(['attendance_session_id', 'version'], 'attendance_session_changes_session_version');
            $table->foreign(['attendance_session_id', 'organization_id'], 'attendance_session_changes_session_tenant_fk')
                ->references(['id', 'organization_id'])->on('attendance_sessions')->cascadeOnDelete();
        });
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE attendance_marks ADD CONSTRAINT attendance_marks_session_tenant_fk
                FOREIGN KEY (session_id, organization_id) REFERENCES activity_sessions (id, organization_id) ON DELETE NO ACTION;
            ALTER TABLE attendance_marks ADD CONSTRAINT attendance_marks_enrollment_tenant_fk
                FOREIGN KEY (enrollment_id, organization_id) REFERENCES enrollments (id, organization_id) ON DELETE CASCADE;
            ALTER TABLE attendance_sessions ADD CONSTRAINT attendance_sessions_session_tenant_fk
                FOREIGN KEY (session_id, organization_id) REFERENCES activity_sessions (id, organization_id) ON DELETE NO ACTION;
            ALTER TABLE attendance_marks ADD CONSTRAINT attendance_marks_status CHECK (status IS NULL OR status IN ('present', 'absent'));
            ALTER TABLE attendance_marks ADD CONSTRAINT attendance_marks_version CHECK (version >= 1);
            ALTER TABLE attendance_mark_changes ADD CONSTRAINT attendance_mark_changes_status
                CHECK ((from_status IS NULL OR from_status IN ('present', 'absent')) AND (to_status IS NULL OR to_status IN ('present', 'absent')));
            ALTER TABLE attendance_mark_changes ADD CONSTRAINT attendance_mark_changes_different CHECK (from_status IS DISTINCT FROM to_status);
            ALTER TABLE attendance_mark_changes ADD CONSTRAINT attendance_mark_changes_version CHECK (version >= 1);
            ALTER TABLE attendance_mark_changes ADD CONSTRAINT attendance_mark_changes_kind CHECK (kind IN ('mark', 'all_present', 'late'));
            ALTER TABLE attendance_mark_changes ADD CONSTRAINT attendance_mark_changes_reason
                CHECK (((kind = 'late') = (reason IS NOT NULL)) AND (reason IS NULL OR (char_length(reason) BETWEEN 1 AND 500 AND reason ~ '[^[:space:]]')));
            ALTER TABLE attendance_mark_changes ADD CONSTRAINT attendance_mark_changes_key CHECK (char_length(request_key) BETWEEN 1 AND 120 AND btrim(request_key) <> '');
            ALTER TABLE attendance_mark_changes ADD CONSTRAINT attendance_mark_changes_hash CHECK (request_hash ~ '^[0-9a-f]{64}$');
            ALTER TABLE attendance_sessions ADD CONSTRAINT attendance_sessions_version CHECK (version >= 1);
            ALTER TABLE attendance_sessions ADD CONSTRAINT attendance_sessions_topic CHECK (topic IS NULL OR (char_length(topic) BETWEEN 1 AND 200 AND topic ~ '[^[:space:]]'));
            ALTER TABLE attendance_sessions ADD CONSTRAINT attendance_sessions_not_held_whole
                CHECK ((not_held_at IS NULL) = (not_held_by IS NULL) AND (not_held_at IS NULL) = (not_held_reason IS NULL));
            ALTER TABLE attendance_sessions ADD CONSTRAINT attendance_sessions_reason
                CHECK (not_held_reason IS NULL OR (char_length(not_held_reason) BETWEEN 1 AND 500 AND not_held_reason ~ '[^[:space:]]'));
            ALTER TABLE attendance_session_changes ADD CONSTRAINT attendance_session_changes_action CHECK (action IN ('not_held', 'held'));
            ALTER TABLE attendance_session_changes ADD CONSTRAINT attendance_session_changes_version CHECK (version >= 1);
            ALTER TABLE attendance_session_changes ADD CONSTRAINT attendance_session_changes_reason
                CHECK (char_length(reason) BETWEEN 1 AND 500 AND reason ~ '[^[:space:]]');

            CREATE FUNCTION protect_attendance_marks() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF pg_trigger_depth() > 1 THEN RETURN OLD; END IF;
                    RAISE EXCEPTION 'Attendance marks may only be deleted by cascade';
                END IF;
                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                    OR NEW.session_id IS DISTINCT FROM OLD.session_id OR NEW.enrollment_id IS DISTINCT FROM OLD.enrollment_id
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at OR NEW.version <> OLD.version + 1 THEN
                    RAISE EXCEPTION 'Attendance mark identity and successive version are protected';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER attendance_marks_protect BEFORE UPDATE OR DELETE ON attendance_marks
                FOR EACH ROW EXECUTE FUNCTION protect_attendance_marks();
            CREATE FUNCTION protect_attendance_sessions() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF pg_trigger_depth() > 1 THEN RETURN OLD; END IF;
                    RAISE EXCEPTION 'Attendance sessions may only be deleted by cascade';
                END IF;
                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                    OR NEW.session_id IS DISTINCT FROM OLD.session_id OR NEW.created_at IS DISTINCT FROM OLD.created_at
                    OR NEW.version <> OLD.version + 1 THEN
                    RAISE EXCEPTION 'Attendance session identity and successive version are protected';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER attendance_sessions_protect BEFORE UPDATE OR DELETE ON attendance_sessions
                FOR EACH ROW EXECUTE FUNCTION protect_attendance_sessions();

            -- The owner must see the parent and history regardless of the caller's RLS context at commit.
            -- Fixed, qualified tables and pg_catalog-only search_path prevent temporary-table/function shadowing.
            CREATE FUNCTION require_attendance_mark_history() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM public.attendance_marks WHERE id = NEW.id) THEN RETURN NULL; END IF;
                IF NOT EXISTS (SELECT 1 FROM public.attendance_mark_changes
                    WHERE mark_id = NEW.id AND organization_id = NEW.organization_id AND version = NEW.version
                    AND to_status IS NOT DISTINCT FROM NEW.status) THEN
                    RAISE EXCEPTION 'Every attendance mark version requires its matching history';
                END IF;
                RETURN NULL;
            END $$;
            CREATE CONSTRAINT TRIGGER attendance_marks_require_history AFTER INSERT OR UPDATE ON attendance_marks
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION require_attendance_mark_history();
            CREATE FUNCTION require_attendance_session_history() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.not_held_at IS NULL THEN RETURN NULL; END IF;
                ELSIF (NEW.not_held_at IS NULL) = (OLD.not_held_at IS NULL) THEN RETURN NULL;
                END IF;
                IF NOT EXISTS (SELECT 1 FROM public.attendance_sessions WHERE id = NEW.id) THEN RETURN NULL; END IF;
                IF NOT EXISTS (SELECT 1 FROM public.attendance_session_changes
                    WHERE attendance_session_id = NEW.id AND organization_id = NEW.organization_id AND version = NEW.version
                    AND action = CASE WHEN NEW.not_held_at IS NULL THEN 'held' ELSE 'not_held' END) THEN
                    RAISE EXCEPTION 'Every held-state change requires its matching history';
                END IF;
                RETURN NULL;
            END $$;
            CREATE CONSTRAINT TRIGGER attendance_sessions_require_history AFTER INSERT OR UPDATE ON attendance_sessions
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION require_attendance_session_history();
            CREATE FUNCTION protect_started_session_exceptions() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND pg_trigger_depth() > 1 THEN RETURN OLD; END IF;
                IF TG_OP <> 'INSERT' AND EXISTS (SELECT 1 FROM public.activity_sessions WHERE id = OLD.session_id AND starts_at <= now()) THEN
                    RAISE EXCEPTION 'Started session exceptions are immutable';
                END IF;
                IF TG_OP <> 'DELETE' AND EXISTS (SELECT 1 FROM public.activity_sessions WHERE id = NEW.session_id AND starts_at <= now()) THEN
                    RAISE EXCEPTION 'Started session exceptions are immutable';
                END IF;
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER enrollment_session_exceptions_protect_started BEFORE INSERT OR UPDATE OR DELETE ON enrollment_session_exceptions
                FOR EACH ROW EXECUTE FUNCTION protect_started_session_exceptions();
            SQL);
        foreach (['attendance_mark_changes', 'attendance_session_changes'] as $table) {
            DB::unprepared("CREATE FUNCTION protect_{$table}() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
                IF TG_OP = 'DELETE' AND pg_trigger_depth() > 1 THEN RETURN OLD; END IF;
                RAISE EXCEPTION 'Attendance history is immutable'; END \$\$;
                CREATE TRIGGER {$table}_protect BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION protect_{$table}();");
        }
        foreach (self::TABLES as $table) {
            DB::unprepared("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY; ALTER TABLE {$table} FORCE ROW LEVEL SECURITY;
                CREATE POLICY {$table}_tenant_policy ON {$table}
                USING (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid)
                WITH CHECK (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid);");
        }
        $username = config('database.connections.pgsql.username');
        if (is_string($username) && $username !== '') {
            $role = '"'.str_replace('"', '""', $username).'"';
            foreach (self::TABLES as $table) {
                DB::unprepared("REVOKE ALL ON {$table} FROM {$role}; GRANT SELECT, INSERT ON {$table} TO {$role};");
            }
            DB::unprepared("GRANT UPDATE (status, version, marked_by, marked_at, updated_at) ON attendance_marks TO {$role};
                GRANT UPDATE (topic, not_held_at, not_held_by, not_held_reason, version, updated_at) ON attendance_sessions TO {$role};");
        }
    }

    public function down(): void
    {
        $this->assertOwner();
        foreach (self::TABLES as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Attendance data is in use; refusing to drop its history.');
            }
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS enrollment_session_exceptions_protect_started ON enrollment_session_exceptions;
                DROP FUNCTION IF EXISTS protect_started_session_exceptions();');
        }
        foreach (['attendance_mark_changes', 'attendance_session_changes', 'attendance_marks', 'attendance_sessions'] as $table) {
            Schema::drop($table);
        }
        if (DB::getDriverName() === 'pgsql') {
            foreach (['protect_attendance_marks', 'protect_attendance_sessions', 'protect_attendance_mark_changes', 'protect_attendance_session_changes',
                'require_attendance_mark_history', 'require_attendance_session_history'] as $function) {
                DB::unprepared("DROP FUNCTION IF EXISTS {$function}();");
            }
        }
    }

    private function assertOwner(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $role = DB::selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        if ($role !== null && ! $role->rolsuper && ! $role->rolbypassrls) {
            throw new RuntimeException('Run migrations as the database owner: php artisan migrate --database=pgsql_admin --force');
        }
    }
};
