<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Visits from a price and a date (docs/product/visits.md): a group may keep visitors to N children in a
 * session (null — every free place), and a new visit without an offer always carries the copy of its terms.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_groups', function (Blueprint $table): void {
            $table->smallInteger('visit_places')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE activity_groups ADD CONSTRAINT activity_groups_visit_places_range
                    CHECK (visit_places IS NULL OR visit_places BETWEEN 1 AND 500);

                -- On new rows only: applications written before this step keep working (withdraw, review, outcome).
                CREATE FUNCTION refuse_visit_without_terms_copy() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    RAISE EXCEPTION 'a visit without an offer needs a copy of its terms';
                END $$;
                CREATE TRIGGER enrollment_applications_visit_terms_copy
                    BEFORE INSERT ON enrollment_applications FOR EACH ROW
                    WHEN (NEW.scope <> 'full_period' AND NEW.visit_offer_id IS NULL AND NEW.payment_terms_snapshot IS NULL)
                    EXECUTE FUNCTION refuse_visit_without_terms_copy();
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS enrollment_applications_visit_terms_copy ON enrollment_applications;
                DROP FUNCTION IF EXISTS refuse_visit_without_terms_copy();
                ALTER TABLE activity_groups DROP CONSTRAINT IF EXISTS activity_groups_visit_places_range;
                SQL);
        }
        Schema::table('activity_groups', function (Blueprint $table): void {
            $table->dropColumn('visit_places');
        });
    }
};
