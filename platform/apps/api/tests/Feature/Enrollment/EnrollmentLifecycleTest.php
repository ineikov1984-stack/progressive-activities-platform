<?php

namespace Tests\Feature\Enrollment;

use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\Group;
use App\Domain\Enrollment\Actions\ChangeEnrollment;
use App\Domain\Enrollment\Actions\ReviewEnrollmentApplication;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EnrollmentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-28 10:00:00');
    }

    public function test_approval_creates_one_active_enrollment_transition_and_undelivered_event(): void
    {
        [$application, $actor] = $this->fixture();

        $reviewed = app(ReviewEnrollmentApplication::class)->handle(
            $actor, $application, 'approve', 1, null, null,
        );

        $this->assertSame('approved', $reviewed->status->value);
        $this->assertDatabaseHas('enrollments', [
            'application_id' => $application->id, 'status' => 'active', 'version' => 1,
        ]);
        $this->assertDatabaseHas('enrollment_transitions', [
            'application_id' => $application->id, 'from_status' => 'submitted', 'to_status' => 'approved',
        ]);
        $this->assertDatabaseHas('enrollment_events', [
            'application_id' => $application->id, 'type' => 'enrollment.application_approved',
            'delivered_at' => null, 'attempts' => 0,
        ]);
        $this->assertDatabaseCount('enrollments', 1);
        $this->assertDatabaseCount('enrollment_transitions', 1);
        $this->assertDatabaseCount('enrollment_events', 1);
    }

    public function test_rejection_requires_public_reason_and_never_creates_enrollment(): void
    {
        [$application, $actor] = $this->fixture();

        try {
            app(ReviewEnrollmentApplication::class)->handle($actor, $application, 'reject', 1, null, 'Бележка');
            $this->fail('Expected public reason validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('public_reason', $exception->errors());
        }

        $reviewed = app(ReviewEnrollmentApplication::class)->handle(
            $actor, $application->refresh(), 'reject', 1, 'Групата няма подходящо ниво.', 'Бележка',
        );
        $this->assertSame('rejected', $reviewed->status->value);
        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_stale_or_repeated_review_does_not_duplicate_state(): void
    {
        [$application, $actor] = $this->fixture();
        app(ReviewEnrollmentApplication::class)->handle($actor, $application, 'approve', 1, null, null);

        $this->expectException(ValidationException::class);
        app(ReviewEnrollmentApplication::class)->handle($actor, $application->refresh(), 'approve', 1, null, null);
    }

    public function test_full_group_waitlists_when_enabled_and_rejects_when_disabled(): void
    {
        [$first, $actor] = $this->fixture(capacity: 1);
        app(ReviewEnrollmentApplication::class)->handle($actor, $first, 'approve', 1, null, null);
        $second = $this->anotherApplication($first);

        $waitlisted = app(ReviewEnrollmentApplication::class)->handle($actor, $second, 'approve', 1, null, null);
        $this->assertSame('waitlisted', $waitlisted->status->value);
        $this->assertDatabaseCount('enrollments', 1);

        $first->group()->update(['waitlist_enabled' => false]);
        $third = $this->anotherApplication($first);
        $this->expectException(ValidationException::class);
        app(ReviewEnrollmentApplication::class)->handle($actor, $third, 'approve', 1, null, null);
    }

    public function test_future_end_one_date_exclusion_and_transfer_preserve_history(): void
    {
        [$application, $actor, $targetGroup] = $this->fixture(withTargetGroup: true);
        app(ReviewEnrollmentApplication::class)->handle($actor, $application, 'approve', 1, null, null);
        $enrollment = Enrollment::query()->where('application_id', $application->id)->firstOrFail();
        $session = $application->group->sessions()->firstOrFail();

        app(ChangeEnrollment::class)->excludeSession($actor, $enrollment, $session, 'Семейно отсъствие');
        $this->assertDatabaseHas('enrollment_session_exceptions', [
            'enrollment_id' => $enrollment->id, 'session_id' => $session->id, 'kind' => 'excluded',
        ]);

        app(ChangeEnrollment::class)->endFrom($actor, $enrollment->refresh(), '2026-11-20', 'Промяна в графика');
        $ended = $enrollment->refresh();
        $this->assertSame('ended', $ended->status->value);
        $this->assertSame('2026-11-20', $ended->ends_on?->format('Y-m-d'));

        $transferred = app(ChangeEnrollment::class)->transfer(
            $actor, $ended, $targetGroup, '2026-11-20', 'Преместване по желание',
        );
        $this->assertSame($enrollment->id, $transferred->transferred_from_id);
        $this->assertSame('2026-11-20', $enrollment->refresh()->ends_on?->format('Y-m-d'));
        $this->assertDatabaseCount('enrollment_events', 4);
    }

    /** @return array{EnrollmentApplication, User, Group|null} */
    private function fixture(int $capacity = 12, bool $withTargetGroup = false): array
    {
        $organization = Organization::query()->withoutGlobalScopes()->create([
            'name' => 'Организация', 'slug' => fake()->unique()->slug(), 'status' => 'active',
        ]);
        app(TenantContext::class)->select($organization);
        $actor = User::factory()->create();
        $parent = User::factory()->create();
        $child = $parent->children()->create(['first_name' => 'Ани', 'last_name' => 'Иванова']);
        $activity = Activity::query()->create([
            'slug' => fake()->unique()->slug(), 'title' => 'Шах', 'category' => 'Наука',
            'summary' => 'Описание', 'age_label' => '2.–4. клас', 'status' => 'published',
        ]);
        $offering = $activity->offerings()->create([]);
        $group = $offering->groups()->create([
            'name' => 'Група 1', 'capacity_max' => $capacity, 'status' => 'published',
            'waitlist_enabled' => true, 'enrollment_approval_mode' => 'manual',
        ]);
        $group->sessions()->create([
            'starts_at' => '2026-11-02T14:00:00Z', 'ends_at' => '2026-11-02T15:00:00Z',
            'capacity' => $capacity, 'status' => 'scheduled',
        ]);
        $targetGroup = $withTargetGroup ? $offering->groups()->create([
            'name' => 'Група 2', 'capacity_max' => 12, 'status' => 'published',
        ]) : null;
        $price = $offering->priceOptions()->create([
            'group_id' => $group->id, 'enrollment_mode' => 'monthly', 'unit' => 'month',
            'amount_cents' => 4500, 'currency' => 'EUR', 'is_available' => true,
        ]);
        $application = EnrollmentApplication::query()->create([
            'parent_user_id' => $parent->id, 'child_id' => $child->id, 'group_id' => $group->id,
            'price_option_id' => $price->id, 'scope' => 'full_period', 'source' => 'organization_catalog',
            'status' => 'submitted', 'idempotency_key' => fake()->uuid(), 'accepted_terms_version' => 'v1',
            'accepted_terms_at' => now(), 'amount_cents_snapshot' => 4500,
            'currency_snapshot' => 'EUR', 'unit_snapshot' => 'month',
        ]);

        return [$application, $actor, $targetGroup];
    }

    private function anotherApplication(EnrollmentApplication $source): EnrollmentApplication
    {
        $parent = User::factory()->create();
        $child = $parent->children()->create(['first_name' => 'Боби', 'last_name' => 'Петров']);

        return EnrollmentApplication::query()->create([
            'parent_user_id' => $parent->id, 'child_id' => $child->id,
            'group_id' => $source->group_id, 'price_option_id' => $source->price_option_id,
            'scope' => $source->scope, 'source' => 'organization_catalog', 'status' => 'submitted',
            'idempotency_key' => fake()->uuid(), 'accepted_terms_version' => 'v1',
            'accepted_terms_at' => now(), 'amount_cents_snapshot' => $source->amount_cents_snapshot,
            'currency_snapshot' => 'EUR', 'unit_snapshot' => $source->unit_snapshot,
        ]);
    }
}
