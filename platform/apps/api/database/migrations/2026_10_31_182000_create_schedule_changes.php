<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Changes of the schedule (docs/product/schedule-changes.md, т. 3): one row per change of the office and one per session
 * in it, never changed and never deleted on their own; on a session the start it had by the group's schedule before its
 * first move and whether it was added from the schedule; on an enrollment that continues a transfer, the day it may not
 * start before. Existing sessions are neither moved nor added; a continuation gets its day once, here (т. 3.5).
 */
return new class extends Migration
{
    /** The actions of `schedule_changes` (т. 3.1). */
    private const ACTIONS = ['move', 'cancel', 'restore', 'change_location', 'holiday', 'add'];

    public function up(): void
    {
        $this->assertOwner();

        Schema::table('activity_sessions', function (Blueprint $table): void {
            // The start by the group's schedule before the first move; NULL — not moved (т. 3.3).
            $table->timestampTz('original_starts_at')->nullable();
            // Added from the schedule for the whole group (т. 4.7), not a date of the draft's editor.
            $table->boolean('extra')->default(false);
        });
        Schema::table('enrollments', function (Blueprint $table): void {
            // The day a continuation after a transfer may start on at the earliest (т. 3.3а); NULL — today is the bound.
            $table->date('starts_not_before')->nullable();
        });
        $this->backfillStartsNotBefore();

        Schema::create('schedule_changes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('action', 16);
            // Seen by the office; in a letter only with `reason_to_parents`; never in the audit log (т. 7.6).
            $table->text('reason')->nullable();
            $table->boolean('reason_to_parents')->default(false);
            $table->boolean('notify_parents')->default(true);
            // "Почивен ден": the days and the identifiers of places and activities, no text.
            $table->jsonb('criteria')->nullable();
            $table->unsignedInteger('session_count');
            $table->string('idempotency_key', 120);
            $table->string('request_hash', 64);
            // The answer of the change (т. 7.3): identifiers, versions, times, places and numbers — no names, no reason.
            $table->jsonb('result');
            // Who acted stays in the history: a profile is stopped, never deleted.
            $table->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->unique(['organization_id', 'actor_user_id', 'idempotency_key'], 'schedule_changes_actor_key_unique');
            $table->unique(['id', 'organization_id']);
        });

        Schema::create('session_changes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('schedule_change_id');
            $table->uuid('session_id');
            // NULL only for a session the change added.
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->timestampTz('from_starts_at')->nullable();
            $table->timestampTz('from_ends_at')->nullable();
            $table->timestampTz('to_starts_at');
            $table->timestampTz('to_ends_at');
            $table->uuid('from_location_id')->nullable();
            $table->uuid('to_location_id')->nullable();
            // The warnings the person confirmed (т. 4.8): `[{type, session_id, other_session_id}]`, identifiers only.
            $table->jsonb('confirmed_overlaps');
            $table->timestampTz('created_at');
            $table->unique(['schedule_change_id', 'session_id']);
            $table->index(['session_id', 'created_at']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $actions = implode(', ', array_map(fn (string $action): string => "'{$action}'", self::ACTIONS));
        DB::unprepared(<<<SQL
            ALTER TABLE enrollments ADD CONSTRAINT enrollments_starts_not_before_valid
                CHECK (starts_not_before IS NULL OR starts_on >= starts_not_before);

            ALTER TABLE schedule_changes ADD CONSTRAINT schedule_changes_action_valid CHECK (action IN ({$actions}));
            ALTER TABLE schedule_changes ADD CONSTRAINT schedule_changes_reason_length
                CHECK (reason IS NULL OR char_length(reason) BETWEEN 1 AND 2000);
            ALTER TABLE schedule_changes ADD CONSTRAINT schedule_changes_reason_required
                CHECK (action IN ('restore', 'add') OR (reason IS NOT NULL AND btrim(reason) <> ''));
            ALTER TABLE schedule_changes ADD CONSTRAINT schedule_changes_criteria_valid
                CHECK (criteria IS NULL OR (action = 'holiday' AND jsonb_typeof(criteria) = 'object'));
            ALTER TABLE schedule_changes ADD CONSTRAINT schedule_changes_session_count_positive CHECK (session_count > 0);
            ALTER TABLE schedule_changes ADD CONSTRAINT schedule_changes_key_present CHECK (char_length(idempotency_key) >= 1);
            ALTER TABLE schedule_changes ADD CONSTRAINT schedule_changes_hash_valid CHECK (request_hash ~ '^[0-9a-f]{64}$');
            ALTER TABLE schedule_changes ADD CONSTRAINT schedule_changes_result_object CHECK (jsonb_typeof(result) = 'object');
            ALTER TABLE schedule_changes ENABLE ROW LEVEL SECURITY;
            ALTER TABLE schedule_changes FORCE ROW LEVEL SECURITY;
            CREATE POLICY schedule_changes_tenant_policy ON schedule_changes
                USING (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid)
                WITH CHECK (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid);

            ALTER TABLE session_changes ADD CONSTRAINT session_changes_change_tenant_fk
                FOREIGN KEY (schedule_change_id, organization_id) REFERENCES schedule_changes (id, organization_id) ON DELETE CASCADE;
            -- A session with history is not deleted, so that the history never goes quietly. The editor deletes only
            -- the sessions of a draft, which have none; with the organization both go in one command, and NO ACTION
            -- checks only at its end (т. 3.2).
            ALTER TABLE session_changes ADD CONSTRAINT session_changes_session_tenant_fk
                FOREIGN KEY (session_id, organization_id) REFERENCES activity_sessions (id, organization_id) ON DELETE NO ACTION;
            -- The history stays without a deleted place; only the place's column is cleared.
            ALTER TABLE session_changes ADD CONSTRAINT session_changes_from_location_tenant_fk
                FOREIGN KEY (from_location_id, organization_id) REFERENCES locations (id, organization_id) ON DELETE SET NULL (from_location_id);
            ALTER TABLE session_changes ADD CONSTRAINT session_changes_to_location_tenant_fk
                FOREIGN KEY (to_location_id, organization_id) REFERENCES locations (id, organization_id) ON DELETE SET NULL (to_location_id);
            ALTER TABLE session_changes ADD CONSTRAINT session_changes_status_valid
                CHECK ((from_status IS NULL OR from_status IN ('scheduled', 'cancelled')) AND to_status IN ('scheduled', 'cancelled'));
            ALTER TABLE session_changes ADD CONSTRAINT session_changes_from_whole
                CHECK ((from_status IS NULL) = (from_starts_at IS NULL) AND (from_starts_at IS NULL) = (from_ends_at IS NULL));
            ALTER TABLE session_changes ADD CONSTRAINT session_changes_times_valid
                CHECK (to_ends_at >= to_starts_at AND (from_starts_at IS NULL OR from_ends_at >= from_starts_at));
            ALTER TABLE session_changes ADD CONSTRAINT session_changes_overlaps_array CHECK (jsonb_typeof(confirmed_overlaps) = 'array');
            ALTER TABLE session_changes ENABLE ROW LEVEL SECURITY;
            ALTER TABLE session_changes FORCE ROW LEVEL SECURITY;
            CREATE POLICY session_changes_tenant_policy ON session_changes
                USING (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid)
                WITH CHECK (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid);

            -- History is never corrected — a wrong change is put right by a new one. Also for the owner role: rows go only
            -- with their organization (a cascade runs at trigger depth > 1).
            CREATE FUNCTION protect_schedule_changes() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' AND pg_trigger_depth() > 1 THEN RETURN OLD; END IF;
                RAISE EXCEPTION 'schedule changes are history: %', TG_OP;
            END \$\$;
            CREATE TRIGGER schedule_changes_protect BEFORE UPDATE OR DELETE ON schedule_changes
                FOR EACH ROW EXECUTE FUNCTION protect_schedule_changes();

            -- The one change a row takes: a deleted place clears itself (ON DELETE SET NULL), from the cascade only, and
            -- nothing else in the row changes (the rule of protect_payment_terms).
            CREATE FUNCTION protect_session_changes() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' AND pg_trigger_depth() > 1 THEN RETURN OLD; END IF;
                IF TG_OP = 'UPDATE' AND pg_trigger_depth() > 1
                    AND (NEW.from_location_id IS NULL OR NEW.from_location_id = OLD.from_location_id)
                    AND (NEW.to_location_id IS NULL OR NEW.to_location_id = OLD.to_location_id)
                    AND (to_jsonb(NEW) - 'from_location_id' - 'to_location_id') = (to_jsonb(OLD) - 'from_location_id' - 'to_location_id') THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'session changes are history: %', TG_OP;
            END \$\$;
            CREATE TRIGGER session_changes_protect BEFORE UPDATE OR DELETE ON session_changes
                FOR EACH ROW EXECUTE FUNCTION protect_session_changes();
            SQL);

        $username = config('database.connections.pgsql.username');
        if (is_string($username) && $username !== '') {
            $role = '"'.str_replace('"', '""', $username).'"';
            // The default privileges give the application every row operation on new tables: it only reads and adds.
            DB::unprepared("REVOKE ALL ON schedule_changes, session_changes FROM {$role}");
            DB::unprepared("GRANT SELECT, INSERT ON schedule_changes, session_changes TO {$role}");
        }
    }

    public function down(): void
    {
        $this->assertOwner();
        // The history of the schedule is never dropped quietly (т. 3.4).
        if (DB::table('schedule_changes')->exists() || DB::table('session_changes')->exists()
            || DB::table('activity_sessions')->where(fn ($query) => $query->whereNotNull('original_starts_at')->orWhere('extra', true))->exists()) {
            throw new RuntimeException('Changes of the schedule are in use: schedule_changes, session_changes or activity_sessions.original_starts_at/extra hold data.');
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS session_changes_protect ON session_changes;
                DROP TRIGGER IF EXISTS schedule_changes_protect ON schedule_changes;
                DROP FUNCTION IF EXISTS protect_session_changes();
                DROP FUNCTION IF EXISTS protect_schedule_changes();
                ALTER TABLE enrollments DROP CONSTRAINT IF EXISTS enrollments_starts_not_before_valid;
                SQL);
        }
        Schema::dropIfExists('session_changes');
        Schema::dropIfExists('schedule_changes');
        // Worked out again from the transfers when the step runs again.
        Schema::table('enrollments', function (Blueprint $table): void {
            $table->dropColumn('starts_not_before');
        });
        Schema::table('activity_sessions', function (Blueprint $table): void {
            $table->dropColumn(['original_starts_at', 'extra']);
        });
    }

    /**
     * Once, here (т. 3.5): the day chosen for the transfer, from the event `enrollment.transferred` of the continuation;
     * without it, the day after the old enrollment's last day when it has ended; otherwise its own start — never after
     * the start, so that the check holds. The code never reads the event's payload for it again.
     */
    private function backfillStartsNotBefore(): void
    {
        $continuations = DB::table('enrollments')->whereNotNull('transferred_from_id')->orderBy('id')
            ->get(['id', 'starts_on', 'transferred_from_id']);
        foreach ($continuations as $enrollment) {
            $startsOn = substr((string) $enrollment->starts_on, 0, 10);
            $notBefore = $this->effectiveDate((string) $enrollment->id)
                ?? $this->dayAfterEnd((string) $enrollment->transferred_from_id)
                ?? $startsOn;
            DB::table('enrollments')->where('id', $enrollment->id)->update(['starts_not_before' => min($notBefore, $startsOn)]);
        }
    }

    private function effectiveDate(string $enrollmentId): ?string
    {
        $payloads = DB::table('enrollment_events')->where('enrollment_id', $enrollmentId)->where('type', 'enrollment.transferred')
            ->orderBy('occurred_at')->orderBy('id')->pluck('payload');
        foreach ($payloads as $payload) {
            $data = is_string($payload) ? json_decode($payload, true) : null;
            $date = is_array($data) ? ($data['effective_date'] ?? null) : null;
            if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
                return $date;
            }
        }

        return null;
    }

    private function dayAfterEnd(string $previousId): ?string
    {
        $previous = DB::table('enrollments')->where('id', $previousId)->first(['status', 'ends_on']);
        if ($previous === null || $previous->status !== 'ended' || $previous->ends_on === null) {
            return null;
        }

        return Carbon::parse(substr((string) $previous->ends_on, 0, 10))->addDay()->toDateString();
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
