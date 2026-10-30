<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which of the activity's ways of enrolling each group takes (the owner's review of 30.10.2026): a beginners'
 * group may take a monthly fee and single visits, an advanced one only the whole period. Null — all of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_groups', function (Blueprint $table): void {
            $table->jsonb('enrollment_modes')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE activity_groups ADD CONSTRAINT activity_groups_enrollment_modes_list
                    CHECK (enrollment_modes IS NULL OR (jsonb_typeof(enrollment_modes) = 'array' AND jsonb_array_length(enrollment_modes) > 0));
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('ALTER TABLE activity_groups DROP CONSTRAINT IF EXISTS activity_groups_enrollment_modes_list');
        }
        Schema::table('activity_groups', function (Blueprint $table): void {
            $table->dropColumn('enrollment_modes');
        });
    }
};
