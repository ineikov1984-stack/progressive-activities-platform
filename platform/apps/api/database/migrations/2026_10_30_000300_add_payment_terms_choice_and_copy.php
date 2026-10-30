<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which terms an activity uses and the copy a parent accepts (docs/product/payment-policies.md, т. 7.2–7.3,
 * т. 11; step 2d). The activity may point at one of its organization's sets; an application keeps the exact
 * version and numbers it was accepted with, and they never change afterwards — the enrollment reads them
 * through its application (one application per enrollment; a move creates a new one).
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

        Schema::table('offerings', function (Blueprint $table): void {
            $table->uuid('payment_terms_id')->nullable();
            $table->index('payment_terms_id');
        });
        Schema::table('enrollment_applications', function (Blueprint $table): void {
            $table->uuid('payment_terms_version_id')->nullable();
            $table->jsonb('payment_terms_snapshot')->nullable();
            $table->index('payment_terms_version_id');
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER enrollment_applications_terms_no_update
                BEFORE UPDATE OF payment_terms_version_id, payment_terms_snapshot, accepted_terms_version, accepted_terms_at
                ON enrollment_applications
                WHEN OLD.payment_terms_version_id IS NOT NEW.payment_terms_version_id
                    OR OLD.payment_terms_snapshot IS NOT NEW.payment_terms_snapshot
                    OR OLD.accepted_terms_version IS NOT NEW.accepted_terms_version
                    OR OLD.accepted_terms_at IS NOT NEW.accepted_terms_at
                BEGIN SELECT RAISE(ABORT, 'accepted payment terms are immutable'); END;
                SQL);

            return;
        }

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            -- The set and the version belong to the same organization as the row that points at them. Checked at
            -- commit: sets and versions go away only together with their organization, and with several cascade
            -- paths from it an immediate check would depend on the order of the cascades.
            ALTER TABLE offerings ADD CONSTRAINT offerings_payment_terms_tenant_fk
                FOREIGN KEY (payment_terms_id, organization_id) REFERENCES payment_terms (id, organization_id)
                DEFERRABLE INITIALLY DEFERRED;
            ALTER TABLE enrollment_applications ADD CONSTRAINT enrollment_applications_terms_version_tenant_fk
                FOREIGN KEY (payment_terms_version_id, organization_id) REFERENCES payment_terms_versions (id, organization_id)
                DEFERRABLE INITIALLY DEFERRED;

            -- A copy is a JSON object; a pointer to a version always comes with its copy (a copy without a
            -- version is the platform's ready-made "Стандартни", when the organization has not saved its own).
            ALTER TABLE enrollment_applications ADD CONSTRAINT enrollment_applications_terms_snapshot_object
                CHECK (payment_terms_snapshot IS NULL OR jsonb_typeof(payment_terms_snapshot) = 'object');
            ALTER TABLE enrollment_applications ADD CONSTRAINT enrollment_applications_terms_version_has_snapshot
                CHECK (payment_terms_version_id IS NULL OR payment_terms_snapshot IS NOT NULL);

            -- What the parent accepted never changes, for the database owner too.
            CREATE FUNCTION protect_accepted_payment_terms() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.payment_terms_version_id IS DISTINCT FROM OLD.payment_terms_version_id
                    OR NEW.payment_terms_snapshot IS DISTINCT FROM OLD.payment_terms_snapshot
                    OR NEW.accepted_terms_version IS DISTINCT FROM OLD.accepted_terms_version
                    OR NEW.accepted_terms_at IS DISTINCT FROM OLD.accepted_terms_at THEN
                    RAISE EXCEPTION 'accepted payment terms are immutable';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER enrollment_applications_terms_no_update
                BEFORE UPDATE OF payment_terms_version_id, payment_terms_snapshot, accepted_terms_version, accepted_terms_at
                ON enrollment_applications FOR EACH ROW EXECUTE FUNCTION protect_accepted_payment_terms();
            SQL);

        // No application code deletes an application; without the right, a copy cannot be replaced by deleting
        // the row and writing it again. Cascades from an organization, a child or a group still remove them.
        $username = config('database.connections.pgsql.username');
        if (is_string($username) && $username !== '') {
            DB::unprepared('REVOKE DELETE ON enrollment_applications FROM "'.str_replace('"', '""', $username).'"');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $username = config('database.connections.pgsql.username');
            if (is_string($username) && $username !== '') {
                DB::unprepared('GRANT DELETE ON enrollment_applications TO "'.str_replace('"', '""', $username).'"');
            }
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS enrollment_applications_terms_no_update ON enrollment_applications;
                DROP FUNCTION IF EXISTS protect_accepted_payment_terms();
                ALTER TABLE enrollment_applications DROP CONSTRAINT IF EXISTS enrollment_applications_terms_version_tenant_fk;
                ALTER TABLE enrollment_applications DROP CONSTRAINT IF EXISTS enrollment_applications_terms_snapshot_object;
                ALTER TABLE enrollment_applications DROP CONSTRAINT IF EXISTS enrollment_applications_terms_version_has_snapshot;
                ALTER TABLE offerings DROP CONSTRAINT IF EXISTS offerings_payment_terms_tenant_fk;
                SQL);
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS enrollment_applications_terms_no_update');
        }

        Schema::table('enrollment_applications', function (Blueprint $table): void {
            $table->dropIndex(['payment_terms_version_id']);
            $table->dropColumn(['payment_terms_version_id', 'payment_terms_snapshot']);
        });
        Schema::table('offerings', function (Blueprint $table): void {
            $table->dropIndex(['payment_terms_id']);
            $table->dropColumn('payment_terms_id');
        });
    }
};
