<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TENANT_TABLES = ['open_day_campaigns', 'visit_offers', 'visit_offer_sessions'];

    public function up(): void
    {
        Schema::create('open_day_campaigns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('image_path')->nullable();
            $table->enum('status', ['draft', 'published', 'closed', 'cancelled'])->default('draft');
            $table->timestampTz('published_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['organization_id', 'slug']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('visit_offers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('group_id')->constrained('activity_groups')->cascadeOnDelete();
            $table->foreignUuid('campaign_id')->nullable()->constrained('open_day_campaigns')->nullOnDelete();
            $table->enum('type', ['trial', 'single', 'open_day']);
            $table->enum('status', ['draft', 'published', 'closed', 'cancelled'])->default('draft');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('audience_age_label', 100)->nullable();
            $table->string('audience_class_label', 100)->nullable();
            $table->unsignedInteger('visit_limit');
            $table->enum('fee_type', ['free', 'paid', 'from_price'])->default('free');
            $table->unsignedInteger('amount_cents')->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->text('fee_reason')->nullable();
            $table->unsignedInteger('booking_deadline_hours')->default(12);
            $table->unsignedInteger('cancellation_deadline_hours')->default(24);
            $table->text('cancellation_policy')->nullable();
            $table->enum('cancellation_outcome', ['refund', 'credit', 'non_refundable'])->default('non_refundable');
            $table->string('terms_version', 100)->nullable();
            $table->unsignedInteger('trial_limit_per_academic_year')->default(1);
            $table->unsignedInteger('single_visit_limit')->default(2);
            $table->unsignedInteger('single_visit_window_days')->default(30);
            $table->boolean('unlimited_single_visits')->default(false);
            $table->unsignedInteger('no_show_trial_limit')->nullable();
            $table->boolean('waitlist_enabled')->default(true);
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['organization_id', 'status', 'type']);
            $table->index(['organization_id', 'activity_id']);
            $table->index(['organization_id', 'group_id']);
            $table->index(['organization_id', 'campaign_id']);
        });

        Schema::create('visit_offer_sessions', function (Blueprint $table): void {
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('visit_offer_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('session_id')->constrained('activity_sessions')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['visit_offer_id', 'session_id']);
            $table->index(['organization_id', 'session_id']);
        });

        Schema::table('enrollment_applications', function (Blueprint $table): void {
            $table->foreignUuid('visit_offer_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('visit_offer_title_snapshot')->nullable();
            $table->text('fee_reason_snapshot')->nullable();
            $table->text('cancellation_policy_snapshot')->nullable();
            $table->index(['organization_id', 'visit_offer_id']);
        });

        $this->addDatabaseGuards();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('ALTER TABLE enrollment_applications DROP CONSTRAINT enrollment_applications_visit_offer_tenant_fk');
        }

        Schema::table('enrollment_applications', function (Blueprint $table): void {
            $table->dropForeign(['visit_offer_id']);
            $table->dropIndex(['organization_id', 'visit_offer_id']);
            $table->dropColumn([
                'visit_offer_id', 'visit_offer_title_snapshot',
                'fee_reason_snapshot', 'cancellation_policy_snapshot',
            ]);
        });
        Schema::dropIfExists('visit_offer_sessions');
        Schema::dropIfExists('visit_offers');
        Schema::dropIfExists('open_day_campaigns');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION validate_visit_offer_session()');
            DB::unprepared('DROP FUNCTION validate_visit_offer_group()');
        }

        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                $suffix = strtolower($operation);
                DB::unprepared(<<<SQL
                    CREATE TRIGGER enrollment_applications_version_positive_{$suffix}
                    BEFORE {$operation} ON enrollment_applications
                    WHEN NEW.version <= 0
                    BEGIN SELECT RAISE(ABORT, 'version must be positive'); END
                    SQL);
                DB::unprepared(<<<SQL
                    CREATE TRIGGER enrollment_applications_enum_valid_{$suffix}
                    BEFORE {$operation} ON enrollment_applications
                    WHEN NEW.scope NOT IN ('trial', 'single', 'full_period')
                        OR NEW.source NOT IN ('organization_catalog', 'direct_link', 'marketplace', 'staff')
                        OR NEW.status NOT IN ('draft', 'submitted', 'waitlisted', 'approved', 'rejected', 'withdrawn', 'expired')
                    BEGIN SELECT RAISE(ABORT, 'invalid enrollment application enum'); END
                    SQL);
            }
        }
    }

    private function addDatabaseGuards(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->addSqliteGuards();

            return;
        }

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE open_day_campaigns ADD CONSTRAINT open_day_campaigns_id_org_unique UNIQUE (id, organization_id);
            ALTER TABLE visit_offers ADD CONSTRAINT visit_offers_id_org_unique UNIQUE (id, organization_id);
            ALTER TABLE open_day_campaigns ADD CONSTRAINT open_day_campaigns_valid CHECK (
                version > 0 AND status IN ('draft', 'published', 'closed', 'cancelled')
                AND (starts_on IS NULL OR ends_on IS NULL OR ends_on >= starts_on)
            );
            ALTER TABLE visit_offers ADD CONSTRAINT visit_offers_valid CHECK (
                visit_limit > 0 AND amount_cents >= 0 AND version > 0
                AND trial_limit_per_academic_year > 0 AND single_visit_limit > 0
                AND single_visit_window_days > 0
                AND (no_show_trial_limit IS NULL OR no_show_trial_limit > 0)
                AND type IN ('trial', 'single', 'open_day')
                AND status IN ('draft', 'published', 'closed', 'cancelled')
                AND fee_type IN ('free', 'paid', 'from_price')
                AND cancellation_outcome IN ('refund', 'credit', 'non_refundable')
                AND ((fee_type = 'free' AND amount_cents = 0)
                    OR (fee_type IN ('paid', 'from_price') AND amount_cents > 0
                        AND NULLIF(BTRIM(fee_reason), '') IS NOT NULL))
            );
            ALTER TABLE visit_offers ADD CONSTRAINT visit_offers_activity_tenant_fk
                FOREIGN KEY (activity_id, organization_id) REFERENCES activities (id, organization_id) ON DELETE CASCADE;
            ALTER TABLE visit_offers ADD CONSTRAINT visit_offers_group_tenant_fk
                FOREIGN KEY (group_id, organization_id) REFERENCES activity_groups (id, organization_id) ON DELETE CASCADE;
            ALTER TABLE visit_offers ADD CONSTRAINT visit_offers_campaign_tenant_fk
                FOREIGN KEY (campaign_id, organization_id) REFERENCES open_day_campaigns (id, organization_id)
                ON DELETE SET NULL (campaign_id);
            ALTER TABLE visit_offer_sessions ADD CONSTRAINT visit_offer_sessions_offer_tenant_fk
                FOREIGN KEY (visit_offer_id, organization_id) REFERENCES visit_offers (id, organization_id) ON DELETE CASCADE;
            ALTER TABLE visit_offer_sessions ADD CONSTRAINT visit_offer_sessions_session_tenant_fk
                FOREIGN KEY (session_id, organization_id) REFERENCES activity_sessions (id, organization_id) ON DELETE CASCADE;
            ALTER TABLE enrollment_applications ADD CONSTRAINT enrollment_applications_visit_offer_tenant_fk
                FOREIGN KEY (visit_offer_id, organization_id) REFERENCES visit_offers (id, organization_id);
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION validate_visit_offer_group() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM activity_groups g JOIN offerings o ON o.id = g.offering_id
                    WHERE g.id = NEW.group_id AND g.organization_id = NEW.organization_id
                      AND o.organization_id = NEW.organization_id AND o.activity_id = NEW.activity_id
                ) THEN
                    RAISE EXCEPTION 'visit offer group does not belong to activity' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER visit_offers_group_guard BEFORE INSERT OR UPDATE OF organization_id, activity_id, group_id
                ON visit_offers FOR EACH ROW EXECUTE FUNCTION validate_visit_offer_group();

            CREATE FUNCTION validate_visit_offer_session() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM visit_offers v JOIN activity_sessions s ON s.id = NEW.session_id
                    WHERE v.id = NEW.visit_offer_id AND v.organization_id = NEW.organization_id
                      AND s.organization_id = NEW.organization_id AND s.group_id = v.group_id
                ) THEN
                    RAISE EXCEPTION 'visit offer session does not belong to group' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER visit_offer_sessions_group_guard BEFORE INSERT OR UPDATE OF organization_id, visit_offer_id, session_id
                ON visit_offer_sessions FOR EACH ROW EXECUTE FUNCTION validate_visit_offer_session();
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
            DB::unprepared("GRANT SELECT, INSERT, UPDATE, DELETE ON {$tables} TO {$role}");
        }
    }

    private function addSqliteGuards(): void
    {
        foreach (['INSERT', 'UPDATE'] as $operation) {
            $suffix = strtolower($operation);
            DB::unprepared(<<<SQL
                CREATE TRIGGER open_day_campaigns_valid_{$suffix} BEFORE {$operation} ON open_day_campaigns
                WHEN NEW.version <= 0 OR (NEW.starts_on IS NOT NULL AND NEW.ends_on IS NOT NULL AND NEW.ends_on < NEW.starts_on)
                BEGIN SELECT RAISE(ABORT, 'invalid campaign'); END
                SQL);
            DB::unprepared(<<<SQL
                CREATE TRIGGER visit_offers_valid_{$suffix} BEFORE {$operation} ON visit_offers
                WHEN NEW.visit_limit <= 0 OR NEW.amount_cents < 0 OR NEW.version <= 0
                    OR NEW.trial_limit_per_academic_year <= 0 OR NEW.single_visit_limit <= 0
                    OR NEW.single_visit_window_days <= 0
                    OR (NEW.no_show_trial_limit IS NOT NULL AND NEW.no_show_trial_limit <= 0)
                    OR (NEW.fee_type = 'free' AND NEW.amount_cents != 0)
                    OR (NEW.fee_type IN ('paid', 'from_price') AND
                        (NEW.amount_cents <= 0 OR NULLIF(TRIM(NEW.fee_reason), '') IS NULL))
                    OR NOT EXISTS (
                        SELECT 1 FROM activity_groups g JOIN offerings o ON o.id = g.offering_id
                        WHERE g.id = NEW.group_id AND g.organization_id = NEW.organization_id
                            AND o.organization_id = NEW.organization_id AND o.activity_id = NEW.activity_id
                    )
                    OR (NEW.campaign_id IS NOT NULL AND NOT EXISTS (
                        SELECT 1 FROM open_day_campaigns c
                        WHERE c.id = NEW.campaign_id AND c.organization_id = NEW.organization_id
                    ))
                BEGIN SELECT RAISE(ABORT, 'invalid visit offer'); END
                SQL);
            DB::unprepared(<<<SQL
                CREATE TRIGGER visit_offer_sessions_valid_{$suffix} BEFORE {$operation} ON visit_offer_sessions
                WHEN NOT EXISTS (
                    SELECT 1 FROM visit_offers v JOIN activity_sessions s ON s.id = NEW.session_id
                    WHERE v.id = NEW.visit_offer_id AND v.organization_id = NEW.organization_id
                        AND s.organization_id = NEW.organization_id AND s.group_id = v.group_id
                )
                BEGIN SELECT RAISE(ABORT, 'invalid visit offer session'); END
                SQL);
            DB::unprepared(<<<SQL
                CREATE TRIGGER enrollment_applications_visit_offer_{$suffix} BEFORE {$operation} ON enrollment_applications
                WHEN NEW.version <= 0
                    OR NEW.scope NOT IN ('trial', 'single', 'full_period')
                    OR NEW.source NOT IN ('organization_catalog', 'direct_link', 'marketplace', 'staff')
                    OR NEW.status NOT IN ('draft', 'submitted', 'waitlisted', 'approved', 'rejected', 'withdrawn', 'expired')
                    OR (NEW.visit_offer_id IS NOT NULL AND NOT EXISTS (
                        SELECT 1 FROM visit_offers v WHERE v.id = NEW.visit_offer_id AND v.organization_id = NEW.organization_id
                    ))
                BEGIN SELECT RAISE(ABORT, 'invalid enrollment application'); END
                SQL);
        }
    }
};
