<?php

namespace Tests\Feature\Enrollment;

use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\PriceOption;
use App\Domain\Catalog\Models\Session;
use App\Domain\Enrollment\Enums\EnrollmentApplicationStatus;
use App\Domain\Enrollment\Enums\EnrollmentScope;
use App\Domain\Enrollment\Enums\EnrollmentSource;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Child;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Enrollment\Models\EnrollmentEvent;
use App\Domain\Enrollment\Models\EnrollmentSessionException;
use App\Domain\Enrollment\Models\EnrollmentTransition;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnrollmentSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrollment_enums_expose_only_the_approved_values(): void
    {
        $this->assertSame(
            ['draft', 'submitted', 'waitlisted', 'approved', 'rejected', 'withdrawn', 'expired'],
            array_column(EnrollmentApplicationStatus::cases(), 'value'),
        );
        $this->assertSame(['active', 'paused', 'ended', 'cancelled'], array_column(EnrollmentStatus::cases(), 'value'));
        $this->assertSame(['trial', 'single', 'full_period'], array_column(EnrollmentScope::cases(), 'value'));
        $this->assertSame(
            ['organization_catalog', 'direct_link', 'marketplace', 'staff'],
            array_column(EnrollmentSource::cases(), 'value'),
        );
    }

    public function test_parent_owned_child_has_no_organization_column_and_relationship_round_trips(): void
    {
        $parent = User::factory()->create();
        $child = $parent->children()->create([
            'first_name' => 'Ани',
            'last_name' => 'Иванова',
            'birth_date' => '2018-04-12',
            'school_name' => 'ОПУЗ',
            'class_label' => '2. клас',
            'institution_group' => null,
        ]);

        $this->assertInstanceOf(Child::class, $child);
        $this->assertSame($parent->id, $child->owner->id);
        $this->assertSame(1, $child->version);
        $this->assertSame('active', $child->status);
        $this->assertFalse(Schema::hasColumn('children', 'organization_id'));
    }

    public function test_group_enrollment_settings_and_organization_scoped_relationships_have_safe_defaults(): void
    {
        [$organization, $parent, $child, $group, $session, $price] = $this->catalogFixture();

        $application = EnrollmentApplication::query()->create([
            'parent_user_id' => $parent->id,
            'child_id' => $child->id,
            'group_id' => $group->id,
            'price_option_id' => $price->id,
            'session_id' => null,
            'scope' => EnrollmentScope::FullPeriod,
            'source' => EnrollmentSource::OrganizationCatalog,
            'status' => EnrollmentApplicationStatus::Submitted,
            'idempotency_key' => 'submit-001',
            'accepted_terms_version' => 'terms-v1',
            'accepted_terms_at' => now(),
            'amount_cents_snapshot' => 3000,
            'currency_snapshot' => 'EUR',
            'unit_snapshot' => 'month',
        ]);
        $enrollment = Enrollment::query()->create([
            'application_id' => $application->id,
            'child_id' => $child->id,
            'group_id' => $group->id,
            'price_option_id' => $price->id,
            'scope' => EnrollmentScope::FullPeriod,
            'status' => EnrollmentStatus::Active,
            'starts_on' => '2026-10-28',
        ]);
        $transition = EnrollmentTransition::query()->create([
            'application_id' => $application->id,
            'enrollment_id' => $enrollment->id,
            'actor_user_id' => $parent->id,
            'from_status' => EnrollmentApplicationStatus::Submitted->value,
            'to_status' => EnrollmentApplicationStatus::Approved->value,
            'public_reason' => 'Мястото е потвърдено.',
        ]);
        $exception = EnrollmentSessionException::query()->create([
            'enrollment_id' => $enrollment->id,
            'session_id' => $session->id,
            'kind' => 'excluded',
            'reason' => 'Отсъствие само за тази дата',
            'created_by' => $parent->id,
        ]);
        $event = EnrollmentEvent::query()->create([
            'type' => 'enrollment.approved',
            'application_id' => $application->id,
            'enrollment_id' => $enrollment->id,
            'payload' => ['status' => 'approved'],
            'occurred_at' => now(),
        ]);

        $group->refresh();
        $this->assertSame('manual', $group->enrollment_approval_mode);
        $this->assertTrue($group->waitlist_enabled);
        $this->assertSame($organization->id, $application->organization_id);
        $this->assertSame($organization->id, $enrollment->organization_id);
        $this->assertSame($organization->id, $transition->organization_id);
        $this->assertSame($organization->id, $exception->organization_id);
        $this->assertSame($organization->id, $event->organization_id);
        $this->assertSame(1, $application->version);
        $this->assertSame(1, $enrollment->version);
        $this->assertSame(0, $event->attempts);
        $this->assertNull($event->delivered_at);
        $this->assertSame($child->id, $application->child->id);
        $this->assertSame($group->id, $application->group->id);
        $this->assertSame($application->id, $enrollment->application->id);
        $this->assertSame($session->id, $exception->session->id);
        $this->assertCount(1, $enrollment->transitions);
        $this->assertCount(1, $enrollment->sessionExceptions);
        $this->assertCount(1, $group->enrollments);
        $this->assertCount(1, $session->enrollmentExceptions);
    }

    public function test_database_rejects_duplicate_idempotency_and_session_exception_keys(): void
    {
        [, $parent, $child, $group, $session, $price] = $this->catalogFixture();
        $payload = [
            'parent_user_id' => $parent->id,
            'child_id' => $child->id,
            'group_id' => $group->id,
            'price_option_id' => $price->id,
            'scope' => 'trial',
            'source' => 'organization_catalog',
            'status' => 'submitted',
            'idempotency_key' => 'same-attempt',
            'accepted_terms_version' => 'v1',
            'accepted_terms_at' => now(),
            'amount_cents_snapshot' => 1000,
            'currency_snapshot' => 'EUR',
            'unit_snapshot' => 'session',
        ];
        $first = EnrollmentApplication::query()->create([...$payload, 'session_id' => $session->id]);

        try {
            EnrollmentApplication::query()->create([...$payload, 'session_id' => $session->id]);
            $this->fail('Duplicate idempotency keys must be rejected.');
        } catch (QueryException) {
            $this->assertDatabaseCount('enrollment_applications', 1);
        }

        $enrollment = Enrollment::query()->create([
            'application_id' => $first->id,
            'child_id' => $child->id,
            'group_id' => $group->id,
            'price_option_id' => $price->id,
            'session_id' => $session->id,
            'scope' => 'trial',
            'status' => 'active',
            'starts_on' => '2026-10-28',
            'ends_on' => '2026-10-28',
        ]);
        EnrollmentSessionException::query()->create([
            'enrollment_id' => $enrollment->id,
            'session_id' => $session->id,
            'kind' => 'excluded',
            'created_by' => $parent->id,
        ]);

        $this->expectException(QueryException::class);
        EnrollmentSessionException::query()->create([
            'enrollment_id' => $enrollment->id,
            'session_id' => $session->id,
            'kind' => 'excluded',
            'created_by' => $parent->id,
        ]);
    }

    public function test_database_rejects_invalid_status_and_non_positive_versions(): void
    {
        [$organization, $parent, $child, $group, , $price] = $this->catalogFixture();

        $payload = [
            'id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'parent_user_id' => $parent->id,
            'child_id' => $child->id,
            'group_id' => $group->id,
            'price_option_id' => $price->id,
            'scope' => 'full_period',
            'source' => 'organization_catalog',
            'idempotency_key' => 'invalid-status',
            'accepted_terms_version' => 'v1',
            'accepted_terms_at' => now(),
            'amount_cents_snapshot' => 3000,
            'currency_snapshot' => 'EUR',
            'unit_snapshot' => 'month',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        try {
            DB::table('enrollment_applications')->insert([...$payload, 'status' => 'invented', 'version' => 1]);
            $this->fail('Invalid application statuses must be rejected by the database.');
        } catch (QueryException) {
            $this->assertDatabaseCount('enrollment_applications', 0);
        }

        $this->expectException(QueryException::class);
        DB::table('enrollment_applications')->insert([
            ...$payload,
            'id' => (string) Str::uuid(),
            'status' => 'submitted',
            'idempotency_key' => 'invalid-version',
            'version' => 0,
        ]);
    }

    /** @return array{Organization, User, Child, Group, Session, PriceOption} */
    private function catalogFixture(): array
    {
        $organization = Organization::query()->withoutGlobalScopes()->create([
            'name' => 'Демо организация',
            'slug' => fake()->unique()->slug(),
            'status' => 'active',
        ]);
        app(TenantContext::class)->select($organization);
        $parent = User::factory()->create();
        $child = Child::query()->create([
            'owner_user_id' => $parent->id,
            'first_name' => 'Ани',
            'last_name' => 'Иванова',
        ]);
        $activity = Activity::query()->create([
            'slug' => fake()->unique()->slug(),
            'title' => 'Шах',
            'category' => 'Наука и технологии',
            'summary' => 'Занимания по шах.',
            'age_label' => '2.–4. клас',
            'status' => 'published',
        ]);
        $offering = $activity->offerings()->create([]);
        $group = $offering->groups()->create([
            'name' => 'Група 1',
            'capacity_min' => 1,
            'capacity_max' => 12,
            'status' => 'published',
        ]);
        $session = $group->sessions()->create([
            'starts_at' => '2026-10-28 15:00:00+00',
            'ends_at' => '2026-10-28 16:00:00+00',
            'capacity' => 12,
        ]);
        $price = $offering->priceOptions()->create([
            'group_id' => $group->id,
            'enrollment_mode' => 'monthly',
            'unit' => 'month',
            'amount_cents' => 3000,
            'currency' => 'EUR',
        ]);

        return [$organization, $parent, $child, $group, $session, $price];
    }
}
