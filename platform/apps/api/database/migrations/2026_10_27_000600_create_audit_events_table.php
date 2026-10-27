<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->nullable()->index();
            $table->uuid('actor_id')->nullable()->index();
            $table->string('action', 100)->index();
            $table->string('subject_type', 80);
            $table->string('subject_id', 128);
            $table->string('correlation_id', 128)->index();
            $table->json('metadata');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['organization_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_events_no_update
                BEFORE UPDATE ON audit_events BEGIN
                    SELECT RAISE(ABORT, 'audit events are immutable');
                END;
                CREATE TRIGGER audit_events_no_delete
                BEFORE DELETE ON audit_events BEGIN
                    SELECT RAISE(ABORT, 'audit events are immutable');
                END;
                SQL);
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION prevent_audit_event_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'audit events are immutable';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER audit_events_no_update
                    BEFORE UPDATE ON audit_events FOR EACH ROW EXECUTE FUNCTION prevent_audit_event_mutation();
                CREATE TRIGGER audit_events_no_delete
                    BEFORE DELETE ON audit_events FOR EACH ROW EXECUTE FUNCTION prevent_audit_event_mutation();

                ALTER TABLE audit_events ENABLE ROW LEVEL SECURITY;
                ALTER TABLE audit_events FORCE ROW LEVEL SECURITY;
                CREATE POLICY audit_events_tenant_policy ON audit_events
                    USING (
                        organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid
                        OR (organization_id IS NULL AND actor_id = NULLIF(current_setting('app.current_user_id', true), '')::uuid)
                    )
                    WITH CHECK (
                        actor_id = NULLIF(current_setting('app.current_user_id', true), '')::uuid
                        AND (
                            organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid
                            OR organization_id IS NULL
                        )
                    );
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_audit_event_mutation() CASCADE');
        }

        Schema::dropIfExists('audit_events');
    }
};
