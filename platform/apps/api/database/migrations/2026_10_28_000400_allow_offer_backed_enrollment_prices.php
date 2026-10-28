<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->changePrices(true);
    }

    public function down(): void
    {
        foreach (['enrollment_applications', 'enrollments'] as $table) {
            if (DB::table($table)->whereNull('price_option_id')->exists()) {
                throw new RuntimeException('Cannot roll back while offer-backed records have no price option.');
            }
        }
        $this->changePrices(false);
    }

    private function changePrices(bool $nullable): void
    {
        foreach (['enrollment_applications', 'enrollments'] as $table) {
            // SQLite rebuilds the table for column changes; preserve its existing guards.
            $triggers = DB::getDriverName() === 'sqlite'
                ? DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND tbl_name = ?", [$table]) : [];
            Schema::table($table, function (Blueprint $blueprint) use ($nullable): void {
                $blueprint->uuid('price_option_id')->nullable($nullable)->change();
            });
            foreach ($triggers as $trigger) {
                if (! DB::selectOne("SELECT name FROM sqlite_master WHERE type = 'trigger' AND name = ?", [$trigger->name])) {
                    DB::unprepared($trigger->sql);
                }
            }
        }
    }
};
