<?php

namespace Tests\Unit\Enrollment;

use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\Session;
use App\Domain\Enrollment\Enums\EnrollmentScope;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Enrollment\Models\EnrollmentSessionException;
use App\Domain\Enrollment\Services\SessionApplicability;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionApplicabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_trial_and_single_apply_only_to_the_selected_session(): void
    {
        [$enrollment, $sessions] = $this->fixture(EnrollmentScope::Trial, '2026-11-02', '2026-11-30');
        $enrollment->update(['session_id' => $sessions[1]->id]);

        $applicable = app(SessionApplicability::class)->sessionsFor($enrollment->refresh());

        $this->assertSame([$sessions[1]->id], $applicable->pluck('id')->all());
    }

    public function test_full_period_uses_boundaries_and_excludes_cancelled_sessions(): void
    {
        [$enrollment, $sessions] = $this->fixture(EnrollmentScope::FullPeriod, '2026-11-09', '2026-11-23');
        $sessions[2]->update(['status' => 'cancelled']);

        $applicable = app(SessionApplicability::class)->sessionsFor($enrollment);

        $this->assertSame([$sessions[1]->id], $applicable->pluck('id')->all());
    }

    public function test_one_date_exception_removes_only_that_session(): void
    {
        [$enrollment, $sessions] = $this->fixture(EnrollmentScope::FullPeriod, '2026-11-02', null);
        EnrollmentSessionException::query()->create([
            'enrollment_id' => $enrollment->id,
            'session_id' => $sessions[1]->id,
            'kind' => 'excluded',
            'reason' => 'Отсъствие',
        ]);

        $applicable = app(SessionApplicability::class)->sessionsFor($enrollment);

        $this->assertSame([$sessions[0]->id, $sessions[2]->id], $applicable->pluck('id')->all());
    }

    public function test_future_end_keeps_past_applicability_and_removes_later_sessions(): void
    {
        [$enrollment, $sessions] = $this->fixture(EnrollmentScope::FullPeriod, '2026-11-02', '2026-11-16');

        $applicable = app(SessionApplicability::class)->sessionsFor($enrollment);

        $this->assertSame([$sessions[0]->id, $sessions[1]->id], $applicable->pluck('id')->all());
    }

    /** @return array{Enrollment, list<Session>} */
    private function fixture(EnrollmentScope $scope, string $startsOn, ?string $endsOn): array
    {
        $organization = Organization::query()->withoutGlobalScopes()->create([
            'name' => 'Организация', 'slug' => fake()->unique()->slug(), 'status' => 'active',
        ]);
        app(TenantContext::class)->select($organization);
        $parent = User::factory()->create();
        $child = $parent->children()->create(['first_name' => 'Ани', 'last_name' => 'Иванова']);
        $activity = Activity::query()->create([
            'slug' => fake()->unique()->slug(), 'title' => 'Шах', 'category' => 'Наука',
            'summary' => 'Описание', 'age_label' => '2.–4. клас', 'status' => 'published',
        ]);
        $offering = $activity->offerings()->create([]);
        $group = $offering->groups()->create([
            'name' => 'Група', 'capacity_max' => 12, 'status' => 'published',
        ]);
        $price = $offering->priceOptions()->create([
            'group_id' => $group->id, 'enrollment_mode' => $scope->value,
            'unit' => 'session', 'amount_cents' => 1000, 'currency' => 'EUR', 'is_available' => true,
        ]);
        $sessions = collect(['2026-11-02', '2026-11-16', '2026-11-30'])->map(
            fn (string $day): Session => $group->sessions()->create([
                'starts_at' => $day.'T14:00:00Z', 'ends_at' => $day.'T15:00:00Z',
                'capacity' => 12, 'status' => 'scheduled',
            ]),
        )->all();
        $application = EnrollmentApplication::query()->create([
            'parent_user_id' => $parent->id, 'child_id' => $child->id, 'group_id' => $group->id,
            'price_option_id' => $price->id, 'scope' => $scope, 'source' => 'organization_catalog',
            'status' => 'approved', 'idempotency_key' => fake()->uuid(), 'accepted_terms_version' => 'v1',
            'accepted_terms_at' => now(), 'amount_cents_snapshot' => 1000,
            'currency_snapshot' => 'EUR', 'unit_snapshot' => 'session',
        ]);
        $enrollment = Enrollment::query()->create([
            'application_id' => $application->id, 'child_id' => $child->id, 'group_id' => $group->id,
            'price_option_id' => $price->id, 'scope' => $scope, 'status' => 'active',
            'starts_on' => $startsOn, 'ends_on' => $endsOn,
        ]);

        return [$enrollment, $sessions];
    }
}
