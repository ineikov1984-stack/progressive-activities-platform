<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Visits on chosen dates take the activity's visit terms too (docs/product/visits.md, т. 8a.11): every new trial,
 * single visit or open day keeps a copy of its terms, with an offer or without. Older rows stay as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refuse_visit_without_terms_copy() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'a visit needs a copy of its terms';
            END $$;
            DROP TRIGGER IF EXISTS enrollment_applications_visit_terms_copy ON enrollment_applications;
            CREATE TRIGGER enrollment_applications_visit_terms_copy
                BEFORE INSERT ON enrollment_applications FOR EACH ROW
                WHEN (NEW.scope <> 'full_period' AND NEW.payment_terms_snapshot IS NULL)
                EXECUTE FUNCTION refuse_visit_without_terms_copy();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refuse_visit_without_terms_copy() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'a visit without an offer needs a copy of its terms';
            END $$;
            DROP TRIGGER IF EXISTS enrollment_applications_visit_terms_copy ON enrollment_applications;
            CREATE TRIGGER enrollment_applications_visit_terms_copy
                BEFORE INSERT ON enrollment_applications FOR EACH ROW
                WHEN (NEW.scope <> 'full_period' AND NEW.visit_offer_id IS NULL AND NEW.payment_terms_snapshot IS NULL)
                EXECUTE FUNCTION refuse_visit_without_terms_copy();
            SQL);
    }
};
