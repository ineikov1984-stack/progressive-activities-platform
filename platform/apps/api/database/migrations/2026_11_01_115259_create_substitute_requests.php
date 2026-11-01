<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Substitute requests and explicit replacement history (docs/product/substitute.md, section 3). */
return new class extends Migration
{
    public function up(): void
    {
        $this->assertOwner();
        Schema::create('substitute_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('requester_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('requester_profile_id');
            $table->string('reason', 16);
            $table->text('note')->nullable();
            $table->uuid('suggested_profile_id')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('idempotency_key', 120);
            $table->string('request_hash', 64);
            $table->timestampsTz();
            $table->unique(['id', 'organization_id']);
            $table->unique(['id', 'requester_profile_id', 'organization_id'], 'substitute_requests_profile_tenant_unique');
            $table->unique(['organization_id', 'requester_user_id', 'idempotency_key'], 'substitute_requests_actor_key_unique');
            foreach (['requester_profile_id', 'suggested_profile_id'] as $column) {
                if (DB::getDriverName() === 'pgsql') {
                    $table->foreign([$column, 'organization_id'], 'substitute_requests_'.$column.'_tenant_fk')
                        ->references(['id', 'organization_id'])->on('instructor_profiles')->noActionOnDelete();
                } else {
                    $table->foreign($column)->references('id')->on('instructor_profiles')->noActionOnDelete();
                }
            }
        });
        Schema::create('substitute_request_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('request_id');
            $table->uuid('requester_profile_id');
            $table->uuid('session_id');
            $table->string('status', 16)->default('open');
            $table->string('closed_reason', 16)->nullable();
            $table->uuid('substitute_profile_id')->nullable();
            $table->uuid('schedule_change_id')->nullable();
            $table->foreignUuid('decided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['id', 'organization_id']);
            $table->unique(['request_id', 'session_id']);
            $table->foreign(['request_id', 'requester_profile_id', 'organization_id'], 'substitute_request_sessions_request_tenant_fk')
                ->references(['id', 'requester_profile_id', 'organization_id'])->on('substitute_requests')->cascadeOnDelete();
            foreach (['session_id' => 'activity_sessions', 'substitute_profile_id' => 'instructor_profiles', 'schedule_change_id' => 'schedule_changes'] as $column => $target) {
                if (DB::getDriverName() === 'pgsql') {
                    $table->foreign([$column, 'organization_id'], 'substitute_request_sessions_'.$column.'_tenant_fk')
                        ->references(['id', 'organization_id'])->on($target)->noActionOnDelete();
                } else {
                    $table->foreign($column)->references('id')->on($target)->noActionOnDelete();
                }
            }
        });
        Schema::table('activity_session_instructors', function (Blueprint $table): void {
            $table->uuid('replaces_instructor_profile_id')->nullable();
            if (DB::getDriverName() === 'pgsql') {
                $table->foreign(['replaces_instructor_profile_id', 'organization_id'], 'session_instructors_replaces_tenant_fk')
                    ->references(['id', 'organization_id'])->on('instructor_profiles')->noActionOnDelete();
            } else {
                $table->foreign('replaces_instructor_profile_id')->references('id')->on('instructor_profiles')->noActionOnDelete();
            }
        });
        Schema::table('session_changes', function (Blueprint $table): void {
            foreach (['replaced_profile_id', 'removed_profile_id', 'added_profile_id'] as $column) {
                $table->uuid($column)->nullable();
                if (DB::getDriverName() === 'pgsql') {
                    $table->foreign([$column, 'organization_id'], 'session_changes_'.$column.'_tenant_fk')
                        ->references(['id', 'organization_id'])->on('instructor_profiles')->noActionOnDelete();
                } else {
                    $table->foreign($column)->references('id')->on('instructor_profiles')->noActionOnDelete();
                }
            }
        });
        DB::statement("CREATE UNIQUE INDEX substitute_request_sessions_open_unique ON substitute_request_sessions (session_id, requester_profile_id) WHERE status = 'open'");
        DB::statement("CREATE INDEX substitute_request_sessions_open_session ON substitute_request_sessions (session_id) WHERE status = 'open'");
        DB::statement('CREATE INDEX substitute_requests_open_created ON substitute_requests (organization_id, created_at) WHERE closed_at IS NULL');
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
            ALTER TABLE substitute_requests ADD CONSTRAINT substitute_requests_reason_valid CHECK (reason IN ('vacation', 'personal', 'other'));
            ALTER TABLE substitute_requests ADD CONSTRAINT substitute_requests_note_valid CHECK (note IS NULL OR char_length(note) BETWEEN 1 AND 300);
            ALTER TABLE substitute_requests ADD CONSTRAINT substitute_requests_suggestion_valid CHECK (suggested_profile_id IS DISTINCT FROM requester_profile_id);
            ALTER TABLE substitute_requests ADD CONSTRAINT substitute_requests_version_valid CHECK (version >= 1);
            ALTER TABLE substitute_requests ADD CONSTRAINT substitute_requests_key_valid CHECK (char_length(idempotency_key) >= 1);
            ALTER TABLE substitute_requests ADD CONSTRAINT substitute_requests_hash_valid CHECK (request_hash ~ '^[0-9a-f]{64}$');
            ALTER TABLE substitute_request_sessions ADD CONSTRAINT substitute_request_sessions_status_valid CHECK (status IN ('open', 'assigned', 'declined', 'withdrawn', 'expired'));
            ALTER TABLE substitute_request_sessions ADD CONSTRAINT substitute_request_sessions_reason_valid CHECK (closed_reason IS NULL OR closed_reason IN ('started', 'cancelled', 'moved', 'not_teaching', 'archived'));
            ALTER TABLE substitute_request_sessions ADD CONSTRAINT substitute_request_sessions_expired_reason CHECK ((status = 'expired') = (closed_reason IS NOT NULL));
            ALTER TABLE substitute_request_sessions ADD CONSTRAINT substitute_request_sessions_substitute_valid CHECK (status = 'assigned' OR substitute_profile_id IS NULL);
            ALTER TABLE substitute_request_sessions ADD CONSTRAINT substitute_request_sessions_change_valid CHECK ((status = 'assigned') = (schedule_change_id IS NOT NULL));
            ALTER TABLE substitute_request_sessions ADD CONSTRAINT substitute_request_sessions_decision_valid CHECK ((status IN ('assigned', 'declined')) = (decided_by_user_id IS NOT NULL));
            ALTER TABLE substitute_request_sessions ADD CONSTRAINT substitute_request_sessions_closed_valid CHECK ((status = 'open') = (closed_at IS NULL));
            ALTER TABLE activity_session_instructors ADD CONSTRAINT session_instructors_replaces_different CHECK (replaces_instructor_profile_id IS DISTINCT FROM instructor_profile_id);
            ALTER TABLE session_changes ADD CONSTRAINT session_changes_substitute_requires_replaced CHECK (replaced_profile_id IS NOT NULL OR (removed_profile_id IS NULL AND added_profile_id IS NULL));
            ALTER TABLE session_changes ADD CONSTRAINT session_changes_substitute_requires_change CHECK (replaced_profile_id IS NULL OR removed_profile_id IS NOT NULL OR added_profile_id IS NOT NULL);

            ALTER TABLE schedule_changes DROP CONSTRAINT schedule_changes_action_valid;
            ALTER TABLE schedule_changes ADD CONSTRAINT schedule_changes_action_valid CHECK (action IN ('move', 'cancel', 'restore', 'change_location', 'holiday', 'add', 'substitute'));
            ALTER TABLE schedule_changes DROP CONSTRAINT schedule_changes_reason_required;
            ALTER TABLE schedule_changes ADD CONSTRAINT schedule_changes_reason_required CHECK (action IN ('restore', 'add', 'substitute') OR (reason IS NOT NULL AND btrim(reason) <> ''));

            CREATE FUNCTION protect_substitute_requests() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF pg_trigger_depth() > 1 THEN RETURN OLD; END IF;
                    RAISE EXCEPTION 'Substitute requests cannot be deleted';
                END IF;
                IF (to_jsonb(NEW) - ARRAY['closed_at', 'version', 'updated_at']) IS DISTINCT FROM
                   (to_jsonb(OLD) - ARRAY['closed_at', 'version', 'updated_at']) OR
                   (OLD.closed_at IS NOT NULL AND NEW.closed_at IS DISTINCT FROM OLD.closed_at) THEN
                    RAISE EXCEPTION 'Substitute request history is immutable';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER substitute_requests_protect BEFORE UPDATE OR DELETE ON substitute_requests FOR EACH ROW EXECUTE FUNCTION protect_substitute_requests();
            CREATE FUNCTION protect_substitute_request_sessions() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF pg_trigger_depth() > 1 THEN RETURN OLD; END IF;
                    RAISE EXCEPTION 'Substitute request sessions cannot be deleted';
                END IF;
                IF OLD.status <> 'open' OR
                    (to_jsonb(NEW) - ARRAY['status', 'closed_reason', 'substitute_profile_id', 'schedule_change_id', 'decided_by_user_id', 'closed_at', 'updated_at']) IS DISTINCT FROM
                    (to_jsonb(OLD) - ARRAY['status', 'closed_reason', 'substitute_profile_id', 'schedule_change_id', 'decided_by_user_id', 'closed_at', 'updated_at']) THEN
                    RAISE EXCEPTION 'Substitute request session history is immutable';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER substitute_request_sessions_protect BEFORE UPDATE OR DELETE ON substitute_request_sessions FOR EACH ROW EXECUTE FUNCTION protect_substitute_request_sessions();
            SQL);
        $username = config('database.connections.pgsql.username');
        $role = is_string($username) && $username !== '' ? '"'.str_replace('"', '""', $username).'"' : null;
        foreach (['substitute_requests', 'substitute_request_sessions'] as $table) {
            DB::unprepared("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY;
                ALTER TABLE {$table} FORCE ROW LEVEL SECURITY;
                CREATE POLICY {$table}_tenant_policy ON {$table}
                    USING (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid)
                    WITH CHECK (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid);");
            if ($role !== null) {
                DB::unprepared("REVOKE ALL ON {$table} FROM {$role}; GRANT SELECT, INSERT, UPDATE ON {$table} TO {$role};");
            }
        }
    }

    public function down(): void
    {
        $this->assertOwner();
        if (DB::table('substitute_requests')->exists() || DB::table('substitute_request_sessions')->exists()
            || DB::table('schedule_changes')->where('action', 'substitute')->exists()
            || DB::table('activity_session_instructors')->whereNotNull('replaces_instructor_profile_id')->exists()) {
            throw new RuntimeException('Substitute data exists; rollback would destroy history.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("ALTER TABLE schedule_changes DROP CONSTRAINT schedule_changes_action_valid;
                ALTER TABLE schedule_changes ADD CONSTRAINT schedule_changes_action_valid CHECK (action IN ('move', 'cancel', 'restore', 'change_location', 'holiday', 'add'));
                ALTER TABLE schedule_changes DROP CONSTRAINT schedule_changes_reason_required;
                ALTER TABLE schedule_changes ADD CONSTRAINT schedule_changes_reason_required CHECK (action IN ('restore', 'add') OR (reason IS NOT NULL AND btrim(reason) <> ''));
                ALTER TABLE session_changes DROP CONSTRAINT session_changes_substitute_requires_replaced;
                ALTER TABLE session_changes DROP CONSTRAINT session_changes_substitute_requires_change;
                ALTER TABLE activity_session_instructors DROP CONSTRAINT session_instructors_replaces_different;");
        }
        Schema::drop('substitute_request_sessions');
        Schema::drop('substitute_requests');
        Schema::table('session_changes', function (Blueprint $table): void {
            foreach (['replaced_profile_id', 'removed_profile_id', 'added_profile_id'] as $column) {
                $table->dropForeign(DB::getDriverName() === 'pgsql' ? 'session_changes_'.$column.'_tenant_fk' : [$column]);
                $table->dropColumn($column);
            }
        });
        Schema::table('activity_session_instructors', function (Blueprint $table): void {
            $table->dropForeign(DB::getDriverName() === 'pgsql' ? 'session_instructors_replaces_tenant_fk' : ['replaces_instructor_profile_id']);
            $table->dropColumn('replaces_instructor_profile_id');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION protect_substitute_requests(); DROP FUNCTION protect_substitute_request_sessions();');
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
