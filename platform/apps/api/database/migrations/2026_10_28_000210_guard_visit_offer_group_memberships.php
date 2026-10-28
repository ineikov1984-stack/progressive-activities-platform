<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER visit_offers_membership_group_update
                BEFORE UPDATE OF group_id, organization_id ON visit_offers
                WHEN EXISTS (
                    SELECT 1 FROM visit_offer_sessions membership
                    JOIN activity_sessions session ON session.id = membership.session_id
                    WHERE membership.visit_offer_id = NEW.id
                        AND (session.group_id != NEW.group_id OR session.organization_id != NEW.organization_id)
                )
                BEGIN SELECT RAISE(ABORT, 'visit offer group conflicts with attached sessions'); END
                SQL);

            return;
        }

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION validate_visit_offer_membership_group() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM visit_offer_sessions membership
                    JOIN activity_sessions session ON session.id = membership.session_id
                    WHERE membership.visit_offer_id = NEW.id
                        AND (session.group_id IS DISTINCT FROM NEW.group_id
                            OR session.organization_id IS DISTINCT FROM NEW.organization_id)
                ) THEN
                    RAISE EXCEPTION 'visit offer group conflicts with attached sessions' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER visit_offers_membership_group_guard
                BEFORE UPDATE OF group_id, organization_id ON visit_offers
                FOR EACH ROW EXECUTE FUNCTION validate_visit_offer_membership_group();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS visit_offers_membership_group_update');

            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS visit_offers_membership_group_guard ON visit_offers');
            DB::unprepared('DROP FUNCTION IF EXISTS validate_visit_offer_membership_group()');
        }
    }
};
