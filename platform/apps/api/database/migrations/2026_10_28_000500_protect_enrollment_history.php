<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER enrollment_transitions_no_update BEFORE UPDATE ON enrollment_transitions
                BEGIN SELECT RAISE(ABORT, 'enrollment transitions are immutable'); END;
                CREATE TRIGGER enrollment_transitions_no_delete BEFORE DELETE ON enrollment_transitions
                WHEN EXISTS (SELECT 1 FROM organizations WHERE id = OLD.organization_id)
                BEGIN SELECT RAISE(ABORT, 'enrollment transitions are immutable'); END;
                CREATE TRIGGER enrollment_events_content_no_update
                BEFORE UPDATE OF organization_id, type, application_id, enrollment_id, payload, occurred_at ON enrollment_events
                BEGIN SELECT RAISE(ABORT, 'enrollment event content is immutable'); END;
                CREATE TRIGGER enrollment_events_no_delete BEFORE DELETE ON enrollment_events
                WHEN EXISTS (SELECT 1 FROM organizations WHERE id = OLD.organization_id)
                BEGIN SELECT RAISE(ABORT, 'enrollment event content is immutable'); END;
                SQL);
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION protect_enrollment_history() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    IF TG_OP = 'DELETE' AND pg_trigger_depth() > 1 THEN RETURN OLD; END IF;
                    RAISE EXCEPTION 'enrollment history is immutable';
                END $$;
                CREATE TRIGGER enrollment_transitions_no_update BEFORE UPDATE ON enrollment_transitions
                FOR EACH ROW EXECUTE FUNCTION protect_enrollment_history();
                CREATE TRIGGER enrollment_transitions_no_delete BEFORE DELETE ON enrollment_transitions
                FOR EACH ROW EXECUTE FUNCTION protect_enrollment_history();
                CREATE TRIGGER enrollment_events_content_no_update
                BEFORE UPDATE OF organization_id, type, application_id, enrollment_id, payload, occurred_at ON enrollment_events
                FOR EACH ROW EXECUTE FUNCTION protect_enrollment_history();
                CREATE TRIGGER enrollment_events_no_delete BEFORE DELETE ON enrollment_events
                FOR EACH ROW EXECUTE FUNCTION protect_enrollment_history();
                SQL);
        }
    }

    public function down(): void
    {
        foreach (['enrollment_transitions_no_update', 'enrollment_transitions_no_delete', 'enrollment_events_content_no_update', 'enrollment_events_no_delete'] as $trigger) {
            $table = str_starts_with($trigger, 'enrollment_transitions') ? 'enrollment_transitions' : 'enrollment_events';
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger.(DB::getDriverName() === 'pgsql' ? ' ON '.$table : ''));
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS protect_enrollment_history()');
        }
    }
};
