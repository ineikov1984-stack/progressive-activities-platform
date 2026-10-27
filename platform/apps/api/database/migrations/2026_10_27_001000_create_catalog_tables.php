<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_catalog_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->string('public_name');
            $table->text('intro')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('accent_color', 7)->nullable();
            $table->boolean('is_published')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('locations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 16);
            $table->string('space_usage', 16)->default('shared');
            $table->text('address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('map_url')->nullable();
            $table->text('public_directions')->nullable();
            $table->boolean('is_public')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['organization_id', 'type']);
        });

        Schema::create('instructor_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('public_name');
            $table->string('role_title')->nullable();
            $table->text('bio')->nullable();
            $table->json('qualifications')->nullable();
            $table->string('photo_path')->nullable();
            $table->boolean('is_public')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['organization_id', 'public_name']);
        });

        Schema::create('activities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('title');
            $table->string('category', 100);
            $table->text('summary');
            $table->string('age_label', 100);
            $table->string('hero_image_path')->nullable();
            $table->text('about')->nullable();
            $table->json('benefits')->nullable();
            $table->text('methodology')->nullable();
            $table->text('participation_terms')->nullable();
            $table->json('faq')->nullable();
            $table->json('gallery')->nullable();
            $table->text('instructor_intro')->nullable();
            $table->string('status', 16)->default('draft');
            $table->timestampTz('published_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['organization_id', 'slug']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('offerings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('lead_instructor_profile_id')->nullable()->constrained('instructor_profiles')->nullOnDelete();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->text('terms')->nullable();
            $table->text('cancellation_policy')->nullable();
            $table->string('contact_email', 254)->nullable();
            $table->text('enrollment_instructions')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['organization_id', 'activity_id']);
        });

        Schema::create('activity_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('offering_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('age_label', 100)->nullable();
            $table->unsignedInteger('capacity_min')->default(1);
            $table->unsignedInteger('capacity_max')->nullable();
            $table->json('schedule_pattern')->nullable();
            $table->string('status', 16)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['organization_id', 'offering_id']);
        });

        Schema::create('activity_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('group_id')->constrained('activity_groups')->cascadeOnDelete();
            $table->foreignUuid('location_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->unsignedInteger('capacity')->nullable();
            $table->string('status', 16)->default('scheduled');
            $table->text('change_reason')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['organization_id', 'starts_at']);
            $table->index(['group_id', 'starts_at']);
        });

        Schema::create('price_options', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('offering_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('group_id')->nullable()->constrained('activity_groups')->cascadeOnDelete();
            $table->string('enrollment_mode', 24);
            $table->string('unit', 16);
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('EUR');
            $table->text('terms')->nullable();
            $table->boolean('is_available')->default(true);
            $table->text('cancellation_policy')->nullable();
            $table->string('payment_due')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['organization_id', 'offering_id']);
        });

        Schema::create('activity_group_instructors', function (Blueprint $table): void {
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('group_id')->constrained('activity_groups')->cascadeOnDelete();
            $table->foreignUuid('instructor_profile_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['group_id', 'instructor_profile_id']);
            $table->index('organization_id');
        });

        Schema::create('activity_session_instructors', function (Blueprint $table): void {
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('session_id')->constrained('activity_sessions')->cascadeOnDelete();
            $table->foreignUuid('instructor_profile_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['session_id', 'instructor_profile_id']);
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_session_instructors');
        Schema::dropIfExists('activity_group_instructors');
        Schema::dropIfExists('price_options');
        Schema::dropIfExists('activity_sessions');
        Schema::dropIfExists('activity_groups');
        Schema::dropIfExists('offerings');
        Schema::dropIfExists('activities');
        Schema::dropIfExists('instructor_profiles');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('organization_catalog_profiles');
    }
};
