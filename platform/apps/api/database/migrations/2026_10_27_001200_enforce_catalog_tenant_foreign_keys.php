<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE activities ADD CONSTRAINT activities_id_organization_unique UNIQUE (id, organization_id);
            ALTER TABLE locations ADD CONSTRAINT locations_id_organization_unique UNIQUE (id, organization_id);
            ALTER TABLE instructor_profiles ADD CONSTRAINT instructor_profiles_id_organization_unique UNIQUE (id, organization_id);
            ALTER TABLE offerings ADD CONSTRAINT offerings_id_organization_unique UNIQUE (id, organization_id);
            ALTER TABLE activity_groups ADD CONSTRAINT activity_groups_id_organization_unique UNIQUE (id, organization_id);
            ALTER TABLE activity_sessions ADD CONSTRAINT activity_sessions_id_organization_unique UNIQUE (id, organization_id);

            ALTER TABLE offerings ADD CONSTRAINT offerings_activity_tenant_fk
                FOREIGN KEY (activity_id, organization_id) REFERENCES activities (id, organization_id) ON DELETE CASCADE;
            ALTER TABLE offerings ADD CONSTRAINT offerings_location_tenant_fk
                FOREIGN KEY (location_id, organization_id) REFERENCES locations (id, organization_id);
            ALTER TABLE offerings ADD CONSTRAINT offerings_lead_instructor_tenant_fk
                FOREIGN KEY (lead_instructor_profile_id, organization_id) REFERENCES instructor_profiles (id, organization_id);

            ALTER TABLE activity_groups ADD CONSTRAINT activity_groups_offering_tenant_fk
                FOREIGN KEY (offering_id, organization_id) REFERENCES offerings (id, organization_id) ON DELETE CASCADE;
            ALTER TABLE activity_groups ADD CONSTRAINT activity_groups_location_tenant_fk
                FOREIGN KEY (location_id, organization_id) REFERENCES locations (id, organization_id);

            ALTER TABLE activity_sessions ADD CONSTRAINT activity_sessions_group_tenant_fk
                FOREIGN KEY (group_id, organization_id) REFERENCES activity_groups (id, organization_id) ON DELETE CASCADE;
            ALTER TABLE activity_sessions ADD CONSTRAINT activity_sessions_location_tenant_fk
                FOREIGN KEY (location_id, organization_id) REFERENCES locations (id, organization_id);

            ALTER TABLE price_options ADD CONSTRAINT price_options_offering_tenant_fk
                FOREIGN KEY (offering_id, organization_id) REFERENCES offerings (id, organization_id) ON DELETE CASCADE;
            ALTER TABLE price_options ADD CONSTRAINT price_options_group_tenant_fk
                FOREIGN KEY (group_id, organization_id) REFERENCES activity_groups (id, organization_id) ON DELETE CASCADE;

            ALTER TABLE activity_group_instructors ADD CONSTRAINT activity_group_instructors_group_tenant_fk
                FOREIGN KEY (group_id, organization_id) REFERENCES activity_groups (id, organization_id) ON DELETE CASCADE;
            ALTER TABLE activity_group_instructors ADD CONSTRAINT activity_group_instructors_instructor_tenant_fk
                FOREIGN KEY (instructor_profile_id, organization_id) REFERENCES instructor_profiles (id, organization_id) ON DELETE CASCADE;

            ALTER TABLE activity_session_instructors ADD CONSTRAINT activity_session_instructors_session_tenant_fk
                FOREIGN KEY (session_id, organization_id) REFERENCES activity_sessions (id, organization_id) ON DELETE CASCADE;
            ALTER TABLE activity_session_instructors ADD CONSTRAINT activity_session_instructors_instructor_tenant_fk
                FOREIGN KEY (instructor_profile_id, organization_id) REFERENCES instructor_profiles (id, organization_id) ON DELETE CASCADE;
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE activity_session_instructors DROP CONSTRAINT activity_session_instructors_instructor_tenant_fk;
            ALTER TABLE activity_session_instructors DROP CONSTRAINT activity_session_instructors_session_tenant_fk;
            ALTER TABLE activity_group_instructors DROP CONSTRAINT activity_group_instructors_instructor_tenant_fk;
            ALTER TABLE activity_group_instructors DROP CONSTRAINT activity_group_instructors_group_tenant_fk;
            ALTER TABLE price_options DROP CONSTRAINT price_options_group_tenant_fk;
            ALTER TABLE price_options DROP CONSTRAINT price_options_offering_tenant_fk;
            ALTER TABLE activity_sessions DROP CONSTRAINT activity_sessions_location_tenant_fk;
            ALTER TABLE activity_sessions DROP CONSTRAINT activity_sessions_group_tenant_fk;
            ALTER TABLE activity_groups DROP CONSTRAINT activity_groups_location_tenant_fk;
            ALTER TABLE activity_groups DROP CONSTRAINT activity_groups_offering_tenant_fk;
            ALTER TABLE offerings DROP CONSTRAINT offerings_lead_instructor_tenant_fk;
            ALTER TABLE offerings DROP CONSTRAINT offerings_location_tenant_fk;
            ALTER TABLE offerings DROP CONSTRAINT offerings_activity_tenant_fk;

            ALTER TABLE activity_sessions DROP CONSTRAINT activity_sessions_id_organization_unique;
            ALTER TABLE activity_groups DROP CONSTRAINT activity_groups_id_organization_unique;
            ALTER TABLE offerings DROP CONSTRAINT offerings_id_organization_unique;
            ALTER TABLE instructor_profiles DROP CONSTRAINT instructor_profiles_id_organization_unique;
            ALTER TABLE locations DROP CONSTRAINT locations_id_organization_unique;
            ALTER TABLE activities DROP CONSTRAINT activities_id_organization_unique;
            SQL);
    }
};
