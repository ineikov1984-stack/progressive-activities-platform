<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TENANT_TABLES = [
        'enrollment_applications',
        'enrollments',
        'enrollment_transitions',
        'enrollment_session_exceptions',
        'enrollment_events',
    ];

    public function up(): void
    {
        Schema::table('activity_groups', function (Blueprint $table): void {
            $table->enum('enrollment_approval_mode', ['manual', 'automatic'])->default('manual');
            $table->boolean('waitlist_enabled')->default(true);
        });

        Schema::create('children', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->date('birth_date')->nullable();
            $table->string('school_name')->nullable();
            $table->string('class_label', 100)->nullable();
            $table->string('institution_group', 100)->nullable();
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['owner_user_id', 'status']);
        });

        Schema::create('enrollment_applications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('parent_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('child_id')->constrained('children')->cascadeOnDelete();
            $table->foreignUuid('group_id')->constrained('activity_groups')->cascadeOnDelete();
            $table->foreignUuid('price_option_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('session_id')->nullable()->constrained('activity_sessions')->nullOnDelete();
            $table->enum('scope', ['trial', 'single', 'full_period']);
            $table->enum('source', ['organization_catalog', 'direct_link', 'marketplace', 'staff']);
            $table->enum('status', ['draft', 'submitted', 'waitlisted', 'approved', 'rejected', 'withdrawn', 'expired']);
            $table->string('idempotency_key', 120);
            $table->string('accepted_terms_version', 100);
            $table->timestampTz('accepted_terms_at');
            $table->unsignedInteger('amount_cents_snapshot');
            $table->string('currency_snapshot', 3)->default('EUR');
            $table->string('unit_snapshot', 24);
            $table->text('public_reason')->nullable();
            $table->text('internal_note')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['parent_user_id', 'source', 'idempotency_key'], 'enrollment_applications_actor_source_key_unique');
            $table->index(['organization_id', 'status']);
            $table->index(['child_id', 'status']);
        });

        Schema::create('enrollments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('application_id')->unique()->constrained('enrollment_applications')->restrictOnDelete();
            $table->foreignUuid('child_id')->constrained('children')->cascadeOnDelete();
            $table->foreignUuid('group_id')->constrained('activity_groups')->cascadeOnDelete();
            $table->foreignUuid('price_option_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('session_id')->nullable()->constrained('activity_sessions')->nullOnDelete();
            $table->enum('scope', ['trial', 'single', 'full_period']);
            $table->enum('status', ['active', 'paused', 'ended', 'cancelled'])->default('active');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->uuid('transferred_from_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['organization_id', 'status']);
            $table->index(['child_id', 'status']);
        });

        Schema::table('enrollments', function (Blueprint $table): void {
            $table->foreign('transferred_from_id')->references('id')->on('enrollments')->nullOnDelete();
        });

        Schema::create('enrollment_transitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('application_id')->nullable()->constrained('enrollment_applications')->cascadeOnDelete();
            $table->foreignUuid('enrollment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->text('public_reason')->nullable();
            $table->text('internal_note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('enrollment_session_exceptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('session_id')->constrained('activity_sessions')->cascadeOnDelete();
            $table->enum('kind', ['included', 'excluded'])->default('excluded');
            $table->text('reason')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['enrollment_id', 'session_id']);
            $table->index(['organization_id', 'session_id']);
        });

        Schema::create('enrollment_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type', 100);
            $table->foreignUuid('application_id')->nullable()->constrained('enrollment_applications')->cascadeOnDelete();
            $table->foreignUuid('enrollment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->json('payload');
            $table->timestampTz('occurred_at');
            $table->timestampTz('delivered_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();
            $table->index(['organization_id', 'occurred_at']);
            $table->index(['delivered_at', 'attempts']);
        });

        $this->addDatabaseGuards();
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_events');
        Schema::dropIfExists('enrollment_session_exceptions');
        Schema::dropIfExists('enrollment_transitions');
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('enrollment_applications');
        Schema::dropIfExists('children');

        Schema::table('activity_groups', function (Blueprint $table): void {
            $table->dropColumn(['enrollment_approval_mode', 'waitlist_enabled']);
        });
    }

    private function addDatabaseGuards(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (['children', 'enrollment_applications', 'enrollments'] as $table) {
                DB::unprepared(<<<SQL
                    CREATE TRIGGER {$table}_version_positive_insert
                    BEFORE INSERT ON {$table}
                    WHEN NEW.version <= 0
                    BEGIN
                        SELECT RAISE(ABORT, 'version must be positive');
                    END
                    SQL);
                DB::unprepared(<<<SQL
                    CREATE TRIGGER {$table}_version_positive_update
                    BEFORE UPDATE OF version ON {$table}
                    WHEN NEW.version <= 0
                    BEGIN
                        SELECT RAISE(ABORT, 'version must be positive');
                    END
                    SQL);
            }

            return;
        }

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE children ADD CONSTRAINT children_version_positive CHECK (version > 0);
            ALTER TABLE enrollment_applications ADD CONSTRAINT enrollment_applications_version_positive CHECK (version > 0);
            ALTER TABLE enrollments ADD CONSTRAINT enrollments_version_positive CHECK (version > 0);
            SQL);

        foreach (self::TENANT_TABLES as $table) {
            DB::unprepared("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::unprepared("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::unprepared(<<<SQL
                CREATE POLICY {$table}_tenant_policy ON {$table}
                    USING (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid)
                    WITH CHECK (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid)
                SQL);
        }

        $username = config('database.connections.pgsql.username');
        if (is_string($username) && $username !== '') {
            $role = '"'.str_replace('"', '""', $username).'"';
            $tables = implode(', ', self::TENANT_TABLES);
            DB::unprepared("GRANT SELECT, INSERT, UPDATE, DELETE ON children, {$tables} TO {$role}");
        }
    }
};
