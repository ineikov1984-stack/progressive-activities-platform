<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage Б, PR 1 (docs/product/enrollment-contacts-and-emails.md, т. 4): the parent's phone, the parent's
 * note on an application, and one delivery row per recipient of an enrollment email.
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

        Schema::table('users', function (Blueprint $table): void {
            // E.164, e.g. +359881234567. The team reaches it only through an application or enrollment it can see.
            $table->string('phone', 16)->nullable();
        });

        Schema::table('enrollment_applications', function (Blueprint $table): void {
            // Written by the parent when applying; never copied into history, audit or event payloads.
            $table->text('parent_note')->nullable();
        });

        Schema::table('enrollment_events', function (Blueprint $table): void {
            // Set when the sender has turned the event into deliveries (or decided there are none).
            $table->timestampTz('expanded_at')->nullable();
        });

        Schema::create('enrollment_event_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('enrollment_events')->cascadeOnDelete();
            $table->enum('audience', ['parent', 'team']);
            $table->enum('recipient_kind', ['user', 'offering_contact', 'organization_contact', 'organization_guardian']);
            // Never NULL, so the unique key also holds for contact addresses (and in SQLite).
            $table->string('recipient_key', 100);
            $table->foreignUuid('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['pending', 'sending', 'sent', 'failed', 'skipped'])->default('pending');
            $table->string('skipped_reason', 30)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('locked_until')->nullable();
            $table->timestampTz('sent_at')->nullable();
            // A class name or SMTP code only, never a message text (it can hold an address).
            $table->string('last_error', 100)->nullable();
            $table->timestamps();
            $table->unique(['event_id', 'audience', 'recipient_key']);
        });
        // Open rows only: the sender looks for pending ones and for 'sending' ones whose lease has expired.
        DB::unprepared("CREATE INDEX enrollment_event_deliveries_open ON enrollment_event_deliveries (organization_id, next_attempt_at) WHERE status IN ('pending', 'sending')");

        // Events from before this migration are not sent: their time has passed.
        DB::table('enrollment_events')->whereNull('expanded_at')->update(['expanded_at' => now()]);
        DB::unprepared('CREATE INDEX enrollment_events_unexpanded ON enrollment_events (organization_id) WHERE expanded_at IS NULL');

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE enrollment_events ADD CONSTRAINT enrollment_events_id_organization_unique UNIQUE (id, organization_id);
            ALTER TABLE enrollment_event_deliveries ADD CONSTRAINT enrollment_event_deliveries_event_tenant_fk
                FOREIGN KEY (event_id, organization_id) REFERENCES enrollment_events (id, organization_id) ON DELETE CASCADE;
            ALTER TABLE enrollment_event_deliveries ENABLE ROW LEVEL SECURITY;
            ALTER TABLE enrollment_event_deliveries FORCE ROW LEVEL SECURITY;
            CREATE POLICY enrollment_event_deliveries_tenant_policy ON enrollment_event_deliveries
                USING (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid)
                WITH CHECK (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid);

            -- The sender has no user and no tenant: it learns only which organizations have work,
            -- then reads each one's rows under RLS. Identifiers only.
            CREATE FUNCTION public.pending_enrollment_delivery_tenants()
            RETURNS SETOF uuid LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, public AS $$
                SELECT e.organization_id FROM public.enrollment_events e
                JOIN public.organizations o ON o.id = e.organization_id AND o.status = 'active'
                WHERE e.expanded_at IS NULL
                UNION
                SELECT d.organization_id FROM public.enrollment_event_deliveries d
                JOIN public.organizations o ON o.id = d.organization_id AND o.status = 'active'
                WHERE d.status = 'pending' OR (d.status = 'sending' AND d.locked_until < now())
            $$;
            REVOKE ALL ON FUNCTION public.pending_enrollment_delivery_tenants() FROM PUBLIC;
            SQL);

        $username = config('database.connections.pgsql.username');
        if (is_string($username) && $username !== '') {
            $role = '"'.str_replace('"', '""', $username).'"';
            DB::unprepared("GRANT SELECT, INSERT, UPDATE, DELETE ON enrollment_event_deliveries TO {$role}");
            DB::unprepared("GRANT EXECUTE ON FUNCTION public.pending_enrollment_delivery_tenants() TO {$role}");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS public.pending_enrollment_delivery_tenants()');
        }
        Schema::dropIfExists('enrollment_event_deliveries');
        DB::unprepared('DROP INDEX IF EXISTS enrollment_events_unexpanded');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('ALTER TABLE enrollment_events DROP CONSTRAINT IF EXISTS enrollment_events_id_organization_unique');
        }
        Schema::table('enrollment_events', fn (Blueprint $table) => $table->dropColumn('expanded_at'));
        Schema::table('enrollment_applications', fn (Blueprint $table) => $table->dropColumn('parent_note'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('phone'));
    }
};
