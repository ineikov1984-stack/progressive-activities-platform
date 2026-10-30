<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sets of payment terms and their versions (docs/product/payment-policies.md, т. 11; step 2b). A set is the
 * organization's choice for one way of paying; every change of its numbers is a new, immutable version, so a
 * copy taken at an application (step 2d) always points at the exact numbers the parent accepted.
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

        Schema::create('payment_terms', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            // The values of App\Domain\PaymentTerms\Enums\BillingKind and TermsPreset, fixed here on purpose.
            $table->enum('billing_kind', ['monthly', 'full_period', 'visit', 'package', 'day_camp', 'residential_camp', 'kindergarten']);
            $table->string('name', 80);
            $table->enum('preset', ['flexible', 'standard', 'strict', 'custom']);
            $table->boolean('is_default')->default(false);
            $table->jsonb('self_service');
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'billing_kind']);
            $table->unique(['id', 'organization_id']);
        });

        Schema::create('payment_terms_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('terms_id');
            $table->unsignedInteger('number');
            $table->unsignedSmallInteger('schema_version');
            $table->jsonb('rules');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at');
            $table->unique(['terms_id', 'number']);
            $table->unique(['id', 'organization_id']);
            // The version belongs to a set of the same organization. Sets and versions go away only together
            // with their organization: the triggers below refuse deleting either on its own. (No NO ACTION key
            // here: with two cascade paths from the organization its check would depend on the trigger order.)
            $table->foreign(['terms_id', 'organization_id'], 'payment_terms_versions_terms_tenant_fk')
                ->references(['id', 'organization_id'])->on('payment_terms')->cascadeOnDelete();
        });

        // One default set per way of paying; an archived set is never the default. Same SQL on both drivers.
        DB::unprepared("CREATE UNIQUE INDEX payment_terms_one_default ON payment_terms (organization_id, billing_kind) WHERE is_default AND status = 'active'");

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER payment_terms_default_is_active_insert BEFORE INSERT ON payment_terms
                WHEN NEW.is_default AND NEW.status <> 'active'
                BEGIN SELECT RAISE(ABORT, 'an archived set cannot be the default'); END;
                CREATE TRIGGER payment_terms_default_is_active_update BEFORE UPDATE ON payment_terms
                WHEN NEW.is_default AND NEW.status <> 'active'
                BEGIN SELECT RAISE(ABORT, 'an archived set cannot be the default'); END;
                CREATE TRIGGER payment_terms_versions_no_update
                BEFORE UPDATE OF id, organization_id, terms_id, number, schema_version, rules, created_at ON payment_terms_versions
                BEGIN SELECT RAISE(ABORT, 'payment terms versions are immutable'); END;
                CREATE TRIGGER payment_terms_versions_no_delete BEFORE DELETE ON payment_terms_versions
                WHEN EXISTS (SELECT 1 FROM organizations WHERE id = OLD.organization_id)
                BEGIN SELECT RAISE(ABORT, 'payment terms versions are immutable'); END;
                CREATE TRIGGER payment_terms_no_delete BEFORE DELETE ON payment_terms
                WHEN EXISTS (SELECT 1 FROM organizations WHERE id = OLD.organization_id)
                BEGIN SELECT RAISE(ABORT, 'payment terms are archived, never deleted'); END;
                SQL);

            return;
        }

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE payment_terms ADD CONSTRAINT payment_terms_default_is_active CHECK (NOT is_default OR status = 'active');
            ALTER TABLE payment_terms ADD CONSTRAINT payment_terms_name_present CHECK (btrim(name) <> '');
            ALTER TABLE payment_terms ADD CONSTRAINT payment_terms_version_positive CHECK (version >= 1);
            ALTER TABLE payment_terms ADD CONSTRAINT payment_terms_self_service_object CHECK (jsonb_typeof(self_service) = 'object');
            ALTER TABLE payment_terms_versions ADD CONSTRAINT payment_terms_versions_number_positive CHECK (number >= 1 AND schema_version >= 1);
            ALTER TABLE payment_terms_versions ADD CONSTRAINT payment_terms_versions_rules_object CHECK (jsonb_typeof(rules) = 'object');

            -- A version never changes; a set is archived, never deleted. Deleting either is allowed only as
            -- part of removing the whole organization (a cascade runs at trigger depth > 1); a removed user's
            -- id on a version may be cleared the same way. Holds for the database owner too.
            CREATE FUNCTION protect_payment_terms() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND pg_trigger_depth() > 1 THEN RETURN OLD; END IF;
                IF TG_OP = 'UPDATE' AND pg_trigger_depth() > 1 AND NEW.created_by IS NULL
                    AND (to_jsonb(NEW) - 'created_by') = (to_jsonb(OLD) - 'created_by') THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION '% are protected: %', TG_TABLE_NAME, TG_OP;
            END $$;
            CREATE TRIGGER payment_terms_versions_no_update BEFORE UPDATE ON payment_terms_versions
                FOR EACH ROW EXECUTE FUNCTION protect_payment_terms();
            CREATE TRIGGER payment_terms_versions_no_delete BEFORE DELETE ON payment_terms_versions
                FOR EACH ROW EXECUTE FUNCTION protect_payment_terms();
            CREATE TRIGGER payment_terms_no_delete BEFORE DELETE ON payment_terms
                FOR EACH ROW EXECUTE FUNCTION protect_payment_terms();

            ALTER TABLE payment_terms ENABLE ROW LEVEL SECURITY;
            ALTER TABLE payment_terms FORCE ROW LEVEL SECURITY;
            CREATE POLICY payment_terms_tenant_policy ON payment_terms
                USING (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid)
                WITH CHECK (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid);
            ALTER TABLE payment_terms_versions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE payment_terms_versions FORCE ROW LEVEL SECURITY;
            CREATE POLICY payment_terms_versions_tenant_policy ON payment_terms_versions
                USING (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid)
                WITH CHECK (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid);
            SQL);

        $username = config('database.connections.pgsql.username');
        if (is_string($username) && $username !== '') {
            $role = '"'.str_replace('"', '""', $username).'"';
            // Sets are archived, never deleted; versions are only added.
            DB::unprepared("GRANT SELECT, INSERT, UPDATE ON payment_terms TO {$role}");
            DB::unprepared("GRANT SELECT, INSERT ON payment_terms_versions TO {$role}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_terms_versions');
        Schema::dropIfExists('payment_terms');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS protect_payment_terms()');
        }
    }
};
