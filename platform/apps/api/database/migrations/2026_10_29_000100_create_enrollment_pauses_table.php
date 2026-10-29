<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pauses of a full-period enrollment set by the organization's team (docs/product/staff-enrollment.md, т. 9.3).
 * The enrollment stays active and keeps its place; sessions inside a pause do not count for the child.
 * Also: an enrollment continues in at most one other group (partial unique index on transferred_from_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_pauses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('enrollment_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            // A category instead of free text: health details of a child do not belong here.
            $table->enum('reason_category', ['health', 'holiday', 'family', 'other']);
            $table->string('note', 300)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['enrollment_id', 'starts_on']);
            $table->index(['organization_id', 'starts_on']);
        });

        // An enrollment continues in at most one other group (a cancelled move does not count);
        // works even when the parent is not known yet (Б3). Same SQL on PostgreSQL and SQLite.
        DB::unprepared("CREATE UNIQUE INDEX enrollments_transferred_from_unique ON enrollments (transferred_from_id) WHERE transferred_from_id IS NOT NULL AND status <> 'cancelled'");

        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                $suffix = strtolower($operation);
                DB::unprepared(<<<SQL
                    CREATE TRIGGER enrollment_pauses_valid_{$suffix} BEFORE {$operation} ON enrollment_pauses
                    WHEN NEW.ends_on < NEW.starts_on
                    BEGIN SELECT RAISE(ABORT, 'pause ends before it starts'); END
                    SQL);
            }

            return;
        }

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE enrollment_pauses ADD CONSTRAINT enrollment_pauses_dates_valid CHECK (ends_on >= starts_on);
            ALTER TABLE enrollments ADD CONSTRAINT enrollments_id_organization_unique UNIQUE (id, organization_id);
            ALTER TABLE enrollment_pauses ADD CONSTRAINT enrollment_pauses_enrollment_tenant_fk
                FOREIGN KEY (enrollment_id, organization_id) REFERENCES enrollments (id, organization_id) ON DELETE CASCADE;
            ALTER TABLE enrollment_pauses ENABLE ROW LEVEL SECURITY;
            ALTER TABLE enrollment_pauses FORCE ROW LEVEL SECURITY;
            CREATE POLICY enrollment_pauses_tenant_policy ON enrollment_pauses
                USING (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid)
                WITH CHECK (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid);
            SQL);

        $username = config('database.connections.pgsql.username');
        if (is_string($username) && $username !== '') {
            $role = '"'.str_replace('"', '""', $username).'"';
            DB::unprepared("GRANT SELECT, INSERT, UPDATE, DELETE ON enrollment_pauses TO {$role}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_pauses');
        DB::unprepared('DROP INDEX IF EXISTS enrollments_transferred_from_unique');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('ALTER TABLE enrollments DROP CONSTRAINT IF EXISTS enrollments_id_organization_unique');
        }
    }
};
