<?php

namespace Tests\Feature\Enrollment;

use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\PriceOption;
use App\Domain\Catalog\Models\Session;
use App\Domain\Enrollment\Models\Child;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ParentEnrollmentApplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->travelTo('2026-10-28 10:00:00');
    }

    public function test_parent_submits_full_period_application_with_server_derived_snapshots(): void
    {
        [$organization, $parent, $child, $group, , $price] = $this->fixture('monthly');

        $response = $this->actingAs($parent)
            ->withHeader('Idempotency-Key', 'apply-monthly-001')
            ->postJson('/api/v1/parent/enrollment-applications', [
                'child_id' => $child->id,
                'group_id' => $group->id,
                'price_option_id' => $price->id,
                'scope' => 'trial',
                'source' => 'staff',
                'organization_id' => Str::uuid()->toString(),
                'parent_user_id' => User::factory()->create()->id,
                'accepted_terms_version' => 'terms-2026-10',
            ]);

        $response->assertCreated()
            ->assertJsonPath('application.organization_id', $organization->id)
            ->assertJsonPath('application.parent_user_id', $parent->id)
            ->assertJsonPath('application.child_id', $child->id)
            ->assertJsonPath('application.scope', 'full_period')
            ->assertJsonPath('application.source', 'organization_catalog')
            ->assertJsonPath('application.status', 'submitted')
            ->assertJsonPath('application.session_id', null)
            ->assertJsonPath('application.price.amount_cents', 4500)
            ->assertJsonPath('application.price.currency', 'EUR')
            ->assertJsonPath('application.price.unit', 'month')
            ->assertJsonPath('application.accepted_terms_version', 'terms-2026-10');

        $this->assertDatabaseHas('enrollment_applications', [
            'organization_id' => $organization->id,
            'parent_user_id' => $parent->id,
            'child_id' => $child->id,
            'scope' => 'full_period',
            'source' => 'organization_catalog',
            'amount_cents_snapshot' => 4500,
            'currency_snapshot' => 'EUR',
            'unit_snapshot' => 'month',
            'accepted_terms_version' => 'terms-2026-10',
        ]);
        $this->assertNotNull(EnrollmentApplication::query()->withoutGlobalScopes()->firstOrFail()->accepted_terms_at);
    }

    public function test_trial_and_single_require_a_future_session_from_the_selected_group(): void
    {
        [, $parent, $child, $group, $session, $trialPrice] = $this->fixture('trial');

        $this->actingAs($parent)->withHeader('Idempotency-Key', 'trial-missing-session')
            ->postJson('/api/v1/parent/enrollment-applications', $this->payload($child->id, $group->id, $trialPrice->id))
            ->assertUnprocessable()->assertJsonValidationErrors('session_id');

        app(TenantContext::class)->select($group->organization_id);
        $foreignSession = $this->addSession($group, '2026-10-27T14:00:00Z');
        app(TenantContext::class)->clear();
        $this->actingAs($parent)->withHeader('Idempotency-Key', 'trial-past-session')
            ->postJson('/api/v1/parent/enrollment-applications', [
                ...$this->payload($child->id, $group->id, $trialPrice->id),
                'session_id' => $foreignSession->id,
            ])->assertUnprocessable()->assertJsonValidationErrors('session_id');

        $this->actingAs($parent)->withHeader('Idempotency-Key', 'trial-valid-session')
            ->postJson('/api/v1/parent/enrollment-applications', [
                ...$this->payload($child->id, $group->id, $trialPrice->id),
                'session_id' => $session->id,
            ])->assertCreated()
            ->assertJsonPath('application.scope', 'trial')
            ->assertJsonPath('application.session_id', $session->id);

        [, $singleParent, $singleChild, $singleGroup, $singleSession, $singlePrice] = $this->fixture('single');
        $this->actingAs($singleParent)->withHeader('Idempotency-Key', 'single-valid-session')
            ->postJson('/api/v1/parent/enrollment-applications', [
                ...$this->payload($singleChild->id, $singleGroup->id, $singlePrice->id),
                'session_id' => $singleSession->id,
            ])->assertCreated()->assertJsonPath('application.scope', 'single');
    }

    public function test_foreign_child_is_not_found_and_unavailable_catalog_resources_are_rejected(): void
    {
        [, $parent, , $group, , $price] = $this->fixture('monthly');
        $foreignChild = User::factory()->create()->children()->create([
            'first_name' => 'Чуждо', 'last_name' => 'Дете',
        ]);

        $this->actingAs($parent)->withHeader('Idempotency-Key', 'foreign-child')
            ->postJson('/api/v1/parent/enrollment-applications', $this->payload($foreignChild->id, $group->id, $price->id))
            ->assertNotFound();

        $group->update(['status' => 'draft']);
        $ownedChild = $parent->children()->create(['first_name' => 'Ани', 'last_name' => 'Иванова']);
        $this->actingAs($parent)->withHeader('Idempotency-Key', 'draft-group')
            ->postJson('/api/v1/parent/enrollment-applications', $this->payload($ownedChild->id, $group->id, $price->id))
            ->assertUnprocessable()->assertJsonValidationErrors('group_id');
    }

    public function test_repeated_idempotency_key_returns_the_original_application(): void
    {
        [, $parent, $child, $group, , $price] = $this->fixture('package');
        $payload = $this->payload($child->id, $group->id, $price->id);

        $first = $this->actingAs($parent)->withHeader('Idempotency-Key', 'same-request')
            ->postJson('/api/v1/parent/enrollment-applications', $payload)->assertCreated();
        $second = $this->actingAs($parent)->withHeader('Idempotency-Key', 'same-request')
            ->postJson('/api/v1/parent/enrollment-applications', $payload)->assertOk();

        $this->assertSame($first->json('application.id'), $second->json('application.id'));
        $this->assertDatabaseCount('enrollment_applications', 1);
    }

    public function test_parent_lists_safe_applications_and_withdraws_a_pending_one_once(): void
    {
        [, $parent, $child, $group, , $price] = $this->fixture('monthly');
        $application = $this->submit($parent, $child->id, $group->id, $price->id, 'withdraw-me');

        $this->actingAs($parent)->getJson('/api/v1/parent/enrollment-applications')
            ->assertOk()->assertJsonCount(1, 'applications')
            ->assertJsonPath('applications.0.id', $application->id)
            ->assertJsonMissingPath('applications.0.internal_note')
            ->assertJsonMissingPath('applications.0.idempotency_key');

        $this->actingAs($parent)->postJson('/api/v1/parent/enrollment-applications/'.$application->id.'/withdraw', [
            'version' => 1,
        ])->assertOk()
            ->assertJsonPath('application.status', 'withdrawn')
            ->assertJsonPath('application.version', 2);

        $this->assertDatabaseHas('enrollment_transitions', [
            'application_id' => $application->id,
            'actor_user_id' => $parent->id,
            'from_status' => 'submitted',
            'to_status' => 'withdrawn',
        ]);
        $this->assertDatabaseCount('enrollment_transitions', 1);

        $this->actingAs($parent)->postJson('/api/v1/parent/enrollment-applications/'.$application->id.'/withdraw', [
            'version' => 2,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('enrollment_transitions', 1);
    }

    /** @return array{Organization, User, Child, Group, Session, PriceOption} */
    private function fixture(string $enrollmentMode): array
    {
        $organization = Organization::query()->withoutGlobalScopes()->create([
            'name' => 'Демо организация', 'slug' => fake()->unique()->slug(), 'status' => 'active',
        ]);
        app(TenantContext::class)->select($organization);
        $parent = User::factory()->create();
        $child = $parent->children()->create(['first_name' => 'Ани', 'last_name' => 'Иванова']);
        $activity = Activity::query()->create([
            'slug' => fake()->unique()->slug(), 'title' => 'Шах', 'category' => 'Наука',
            'summary' => 'Занимания по шах.', 'age_label' => '2.–4. клас', 'status' => 'published',
        ]);
        $offering = $activity->offerings()->create([]);
        $group = $offering->groups()->create([
            'name' => 'Група 1', 'capacity_min' => 1, 'capacity_max' => 12, 'status' => 'published',
        ]);
        $session = $this->addSession($group, '2026-11-02T14:00:00Z');
        $price = $offering->priceOptions()->create([
            'group_id' => $group->id, 'enrollment_mode' => $enrollmentMode,
            'unit' => in_array($enrollmentMode, ['trial', 'single'], true) ? 'session' : 'month',
            'amount_cents' => 4500, 'currency' => 'EUR', 'is_available' => true,
        ]);
        app(TenantContext::class)->clear();

        return [$organization, $parent, $child, $group, $session, $price];
    }

    private function addSession(Group $group, string $startsAt): Session
    {
        return $group->sessions()->create([
            'starts_at' => $startsAt,
            'ends_at' => date('c', strtotime($startsAt.' +1 hour')),
            'capacity' => 12,
            'status' => 'scheduled',
        ]);
    }

    /** @return array<string, string> */
    private function payload(string $childId, string $groupId, string $priceId): array
    {
        return [
            'child_id' => $childId,
            'group_id' => $groupId,
            'price_option_id' => $priceId,
            'accepted_terms_version' => 'terms-v1',
        ];
    }

    private function submit(User $parent, string $childId, string $groupId, string $priceId, string $key): EnrollmentApplication
    {
        $response = $this->actingAs($parent)->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/parent/enrollment-applications', $this->payload($childId, $groupId, $priceId))
            ->assertCreated();

        return EnrollmentApplication::query()->withoutGlobalScopes()->findOrFail($response->json('application.id'));
    }
}
