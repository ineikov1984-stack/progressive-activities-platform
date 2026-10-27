<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->uuid('operator_id')->index();
            $table->string('from_status', 32);
            $table->string('to_status', 32);
            $table->unsignedInteger('review_version');
            $table->text('public_reason')->nullable();
            $table->text('next_action')->nullable();
            $table->text('internal_note')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['organization_id', 'created_at']);
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER organization_reviews_no_update
                BEFORE UPDATE ON organization_reviews BEGIN
                    SELECT RAISE(ABORT, 'organization reviews are immutable');
                END;
                CREATE TRIGGER organization_reviews_no_delete
                BEFORE DELETE ON organization_reviews BEGIN
                    SELECT RAISE(ABORT, 'organization reviews are immutable');
                END;
                SQL);
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION prevent_organization_review_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'organization reviews are immutable';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER organization_reviews_no_update
                    BEFORE UPDATE ON organization_reviews FOR EACH ROW EXECUTE FUNCTION prevent_organization_review_mutation();
                CREATE TRIGGER organization_reviews_no_delete
                    BEFORE DELETE ON organization_reviews FOR EACH ROW EXECUTE FUNCTION prevent_organization_review_mutation();
                ALTER TABLE organization_reviews ENABLE ROW LEVEL SECURITY;
                ALTER TABLE organization_reviews FORCE ROW LEVEL SECURITY;
                CREATE POLICY organization_reviews_tenant_policy ON organization_reviews
                    USING (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid)
                    WITH CHECK (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid);
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_organization_review_mutation() CASCADE');
        }

        Schema::dropIfExists('organization_reviews');
    }
};
