<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->unsignedInteger('review_version')->default(1);
            $table->string('organization_type', 48)->nullable();
            $table->string('registration_identifier', 64)->nullable();
            $table->string('public_contact_email', 254)->nullable();
            $table->text('public_review_reason')->nullable();
            $table->text('next_action')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->uuid('reviewed_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn([
                'review_version',
                'organization_type',
                'registration_identifier',
                'public_contact_email',
                'public_review_reason',
                'next_action',
                'submitted_at',
                'reviewed_at',
                'reviewed_by',
            ]);
        });
    }
};
