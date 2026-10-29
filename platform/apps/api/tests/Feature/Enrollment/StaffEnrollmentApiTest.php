<?php

namespace Tests\Feature\Enrollment;

use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\Location;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Enrollment\Models\EnrollmentEvent;
use App\Domain\Enrollment\Models\EnrollmentTransition;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Tests\TestCase;

class StaffEnrollmentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->travelTo('2026-10-28 10:00:00');
    }

    public function test_team_sees_incoming_applications_without_parent_contact_details(): void
    {
        [$organization, $group, $application] = $this->organizationWithApplication();
        $owner = $this->member($organization, 'organization_owner');

        $this->actAs($owner, $organization)
            ->getJson('/api/v1/enrollment/applications')
            ->assertOk()
            ->assertJsonCount(1, 'applications')
            ->assertJsonPath('applications.0.id', $application->id)
            ->assertJsonPath('applications.0.child.name', 'Ани Иванова')
            ->assertJsonPath('applications.0.activity.title', 'Шах')
            ->assertJsonPath('applications.0.group.name', $group->name)
            ->assertJsonPath('applications.0.price.amount_cents', 4500)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('has_more', false)
            ->assertJsonMissingPath('applications.0.parent_user_id')
            ->assertJsonMissingPath('applications.0.internal_note');
        // Only what the team needs about the child; never birth date, school or parent data.
        $child = $this->getJson('/api/v1/enrollment/applications')->json('applications.0.child');
        $this->assertSame(['id', 'name', 'class_label'], array_keys($child));
        $this->getJson('/api/v1/enrollment/applications?status=approved')->assertUnprocessable();
    }

    public function test_coordinator_approves_and_the_child_appears_in_the_group(): void
    {
        [$organization, $group, $application] = $this->organizationWithApplication();
        $coordinator = $this->member($organization, 'coordinator');

        $this->actAs($coordinator, $organization)
            ->postJson("/api/v1/enrollment/applications/{$application->id}/review", ['decision' => 'approve', 'version' => 1])
            ->assertOk()
            ->assertJsonPath('application.status', 'approved');

        $this->getJson("/api/v1/enrollment/groups/{$group->id}/roster")
            ->assertOk()
            ->assertJsonCount(1, 'enrollments')
            ->assertJsonPath('enrollments.0.child.name', 'Ани Иванова')
            ->assertJsonPath('enrollments.0.status', 'active')
            ->assertJsonPath('group.remaining', 1)
            ->assertJsonCount(0, 'waitlist');
        $this->assertDatabaseHas('enrollment_transitions', [
            'application_id' => $application->id, 'actor_user_id' => $coordinator->id, 'to_status' => 'approved',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'enrollment.application_reviewed', 'subject_id' => $application->id, 'actor_id' => $coordinator->id,
        ]);
        // The event points at its enrollment and at the transition that holds what the parent is told.
        $event = EnrollmentEvent::query()->withoutGlobalScopes()->where('type', 'enrollment.application_approved')->sole();
        $transition = EnrollmentTransition::query()->withoutGlobalScopes()->where('application_id', $application->id)->sole();
        $this->assertSame(['status' => 'approved', 'transition_id' => $transition->id], $event->payload);
        $this->assertSame(Enrollment::query()->withoutGlobalScopes()->where('application_id', $application->id)->value('id'), $event->enrollment_id);
    }

    public function test_waitlisted_application_is_approved_when_a_place_frees_up(): void
    {
        [$organization, $group, $application] = $this->organizationWithApplication(capacity: 1);
        $owner = $this->member($organization, 'organization_owner');
        $this->actAs($owner, $organization);

        $this->postJson("/api/v1/enrollment/applications/{$application->id}/review", [
            'decision' => 'waitlist', 'version' => 1, 'public_reason' => 'Ще ви се обадим при свободно място.',
        ])->assertOk()->assertJsonPath('application.status', 'waitlisted');

        $this->getJson('/api/v1/enrollment/applications?status=waitlisted')
            ->assertOk()->assertJsonPath('applications.0.id', $application->id);
        $this->getJson("/api/v1/enrollment/groups/{$group->id}/roster")
            ->assertOk()->assertJsonPath('waitlist.0.id', $application->id);

        $this->postJson("/api/v1/enrollment/applications/{$application->id}/review", ['decision' => 'approve', 'version' => 2])
            ->assertOk()->assertJsonPath('application.status', 'approved');
    }

    public function test_waitlisted_application_cannot_be_approved_while_the_group_is_full(): void
    {
        [$organization, $group, $first] = $this->organizationWithApplication(capacity: 1);
        $owner = $this->member($organization, 'organization_owner');
        $second = $this->anotherApplication($organization, $first);
        $this->actAs($owner, $organization);

        $this->postJson("/api/v1/enrollment/applications/{$second->id}/review", ['decision' => 'waitlist', 'version' => 1])->assertOk();
        $this->postJson("/api/v1/enrollment/applications/{$first->id}/review", ['decision' => 'approve', 'version' => 1])->assertOk();

        $this->postJson("/api/v1/enrollment/applications/{$second->id}/review", ['decision' => 'approve', 'version' => 2])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('capacity');
        $this->assertSame('waitlisted', $second->refresh()->status->value);
        $this->getJson("/api/v1/enrollment/groups/{$group->id}/roster")->assertJsonPath('group.remaining', 0);
    }

    public function test_rejection_needs_an_explanation_and_stale_versions_are_refused(): void
    {
        [$organization, , $application] = $this->organizationWithApplication();
        $owner = $this->member($organization, 'organization_owner');
        $this->actAs($owner, $organization);

        $this->postJson("/api/v1/enrollment/applications/{$application->id}/review", ['decision' => 'reject', 'version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('public_reason');
        $this->postJson("/api/v1/enrollment/applications/{$application->id}/review", ['decision' => 'approve', 'version' => 7])
            ->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->postJson("/api/v1/enrollment/applications/{$application->id}/review", ['decision' => 'maybe', 'version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_waitlist_rules_and_rejecting_a_waiting_application(): void
    {
        [$organization, $group, $application] = $this->organizationWithApplication();
        $owner = $this->member($organization, 'organization_owner');
        $this->actAs($owner, $organization);

        $this->postJson("/api/v1/enrollment/applications/{$application->id}/review", ['decision' => 'waitlist', 'version' => 1])->assertOk();
        $this->postJson("/api/v1/enrollment/applications/{$application->id}/review", ['decision' => 'waitlist', 'version' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->postJson("/api/v1/enrollment/applications/{$application->id}/review", [
            'decision' => 'reject', 'version' => 2, 'public_reason' => 'Групата се закрива.',
        ])->assertOk()->assertJsonPath('application.status', 'rejected');
        $this->assertDatabaseHas('enrollment_transitions', [
            'application_id' => $application->id, 'from_status' => 'waitlisted', 'to_status' => 'rejected',
        ]);

        $second = $this->anotherApplication($organization, $application);
        app(TenantContext::class)->select($organization);
        Group::query()->whereKey($group->id)->update(['waitlist_enabled' => false]);
        app(TenantContext::class)->clear();
        $this->postJson("/api/v1/enrollment/applications/{$second->id}/review", ['decision' => 'waitlist', 'version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->assertSame('submitted', $second->refresh()->status->value);
    }

    /** @return iterable<string, array{string, int, int}> */
    public function test_applications_can_be_narrowed_by_activity_group_and_child_name(): void
    {
        [$organization, $group, $application] = $this->organizationWithApplication();
        $this->travel(1)->minutes();
        $second = $this->anotherApplication($organization, $application);
        [$otherGroup, $painting, $paintingActivityId] = $this->paintingApplication($organization);
        $this->actAs($this->member($organization, 'coordinator'), $organization);
        $ids = fn (string $query): array => array_column($this->getJson("/api/v1/enrollment/applications{$query}")->assertOk()->json('applications'), 'id');

        $this->assertSame([$application->id, $second->id, $painting->id], $ids(''));
        // Each row says how full its group is today, so a full group is seen before deciding.
        $this->getJson('/api/v1/enrollment/applications')
            ->assertJsonPath('applications.0.group', ['id' => $group->id, 'name' => 'Група 1', 'capacity_max' => 2, 'places_taken' => 0])
            ->assertJsonPath('applications.2.group.capacity_max', 8);
        $this->assertSame([$second->id], $ids('?q='.urlencode('Боби')));
        // Part of the first or the last name.
        $this->assertSame([$application->id, $second->id], $ids('?q='.urlencode('ов')));
        // Wildcards are searched literally.
        $this->assertSame([], $ids('?q='.urlencode('%')));
        $this->assertSame([$painting->id], $ids("?group_id={$otherGroup->id}"));
        $activityId = $this->getJson('/api/v1/enrollment/applications')->json('applications.0.activity.id');
        $this->assertSame([$application->id, $second->id], $ids("?activity_id={$activityId}&q=".urlencode('  ')));

        // The choices for the filters do not depend on the filter applied.
        $this->getJson("/api/v1/enrollment/applications?group_id={$otherGroup->id}")
            ->assertJsonPath('total', 1)
            ->assertJsonPath('facets.activities', [
                ['id' => $paintingActivityId, 'title' => 'Рисуване', 'count' => 1],
                ['id' => $activityId, 'title' => 'Шах', 'count' => 2],
            ])
            ->assertJsonPath('facets.groups.1', ['id' => $group->id, 'name' => 'Група 1', 'activity_id' => $activityId, 'count' => 2]);
        $this->getJson('/api/v1/enrollment/applications?group_id=not-a-uuid')->assertUnprocessable();
        $this->getJson('/api/v1/enrollment/applications?q='.str_repeat('а', 101))->assertUnprocessable();
    }

    public function test_group_list_shows_the_activity_days_and_place(): void
    {
        [$organization, $group] = $this->organizationWithApplication();
        app(TenantContext::class)->select($organization);
        $location = Location::query()->create(['name' => 'Спортна зала', 'type' => 'physical', 'space_usage' => 'shared', 'address' => 'София']);
        $group->update([
            'location_id' => $location->id,
            'schedule_pattern' => ['timezone' => 'Europe/Sofia', 'frequency' => 'weekly', 'start_date' => '2026-11-01', 'excluded_dates' => ['2026-12-25'],
                'slots' => [['weekday' => 5, 'starts_at' => '16:00', 'duration_minutes' => 60]]],
        ]);
        app(TenantContext::class)->clear();

        $this->actAs($this->member($organization, 'viewer'), $organization)
            ->getJson('/api/v1/enrollment/groups')
            ->assertOk()
            ->assertJsonPath('groups.0.activity_title', 'Шах')
            ->assertJsonPath('groups.0.activity_category', 'Наука')
            ->assertJsonPath('groups.0.location_name', 'Спортна зала')
            // Only the weekly days and times, not the dates and exceptions of the pattern.
            ->assertJsonPath('groups.0.schedule', ['frequency' => 'weekly', 'slots' => [['weekday' => 5, 'starts_at' => '16:00', 'duration_minutes' => 60]]]);
    }

    public function test_team_turns_on_automatic_approval_for_a_group(): void
    {
        [$organization, $group] = $this->organizationWithApplication();
        [, $foreignGroup] = $this->organizationWithApplication();
        $coordinator = $this->member($organization, 'coordinator');
        $url = "/api/v1/enrollment/groups/{$group->id}/approval";

        $this->actAs($coordinator, $organization)
            ->patchJson($url, ['enrollment_approval_mode' => 'automatic'])
            ->assertOk()
            ->assertExactJson(['group' => ['id' => $group->id, 'enrollment_approval_mode' => 'automatic']]);
        $this->getJson('/api/v1/enrollment/groups')->assertJsonPath('groups.0.enrollment_approval_mode', 'automatic');
        $this->getJson("/api/v1/enrollment/groups/{$group->id}/roster")->assertJsonPath('group.enrollment_approval_mode', 'automatic');
        // Identifiers and the new mode only.
        $this->assertDatabaseHas('audit_events', [
            'action' => 'enrollment.group_approval_changed', 'subject_id' => $group->id, 'actor_id' => $coordinator->id,
        ]);

        $this->patchJson($url, ['enrollment_approval_mode' => 'sometimes'])->assertUnprocessable()->assertJsonValidationErrors('enrollment_approval_mode');
        $this->patchJson("/api/v1/enrollment/groups/{$foreignGroup->id}/approval", ['enrollment_approval_mode' => 'automatic'])->assertNotFound();
        $this->assertSame('manual', $foreignGroup->refresh()->enrollment_approval_mode);
        foreach (['finance', 'viewer', 'instructor'] as $role) {
            $this->actAs($this->member($organization, $role), $organization)
                ->patchJson($url, ['enrollment_approval_mode' => 'manual'])->assertForbidden();
        }
        $this->assertSame('automatic', $group->refresh()->enrollment_approval_mode);
    }

    public static function roles(): iterable
    {
        yield 'platform administrator' => ['platform_admin', 200, 200];
        yield 'administrator' => ['organization_admin', 200, 200];
        yield 'finance' => ['finance', 200, 403];
        yield 'viewer' => ['viewer', 200, 403];
        yield 'instructor' => ['instructor', 403, 403];
    }

    #[DataProvider('roles')]
    public function test_roles_decide_who_may_see_and_who_may_decide(string $role, int $listStatus, int $reviewStatus): void
    {
        [$organization, $group, $application] = $this->organizationWithApplication();
        $member = $this->member($organization, $role);

        $this->actAs($member, $organization)->getJson('/api/v1/enrollment/applications')->assertStatus($listStatus);
        $this->getJson("/api/v1/enrollment/groups/{$group->id}/roster")->assertStatus($listStatus);
        $this->postJson("/api/v1/enrollment/applications/{$application->id}/review", ['decision' => 'approve', 'version' => 1])
            ->assertStatus($reviewStatus);
    }

    #[TestGroup('tenant-isolation')]
    public function test_another_organizations_applications_and_groups_are_not_reachable(): void
    {
        [$organization] = $this->organizationWithApplication();
        [$foreign, $foreignGroup, $foreignApplication] = $this->organizationWithApplication();
        $owner = $this->member($organization, 'organization_owner');
        $this->actAs($owner, $organization);

        $this->getJson('/api/v1/enrollment/applications')
            ->assertOk()->assertJsonCount(1, 'applications')->assertJsonMissing(['id' => $foreignApplication->id]);
        $this->postJson("/api/v1/enrollment/applications/{$foreignApplication->id}/review", ['decision' => 'approve', 'version' => 1])
            ->assertNotFound();
        $this->getJson("/api/v1/enrollment/groups/{$foreignGroup->id}/roster")->assertNotFound();
        $this->withSession(['active_organization_id' => $foreign->id])
            ->getJson('/api/v1/enrollment/applications')->assertNotFound();
        $this->assertSame('submitted', $foreignApplication->refresh()->status->value);
    }

    private function actAs(User $user, Organization $organization): static
    {
        return $this->actingAs($user)->withSession(['active_organization_id' => $organization->id]);
    }

    private function member(Organization $organization, string $role): User
    {
        $user = User::factory()->create();
        OrganizationMembership::query()->withoutGlobalScopes()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active',
        ]);

        return $user;
    }

    /** @return array{Organization, Group, EnrollmentApplication} */
    private function organizationWithApplication(int $capacity = 2): array
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
            'name' => 'Група 1', 'capacity_max' => $capacity, 'status' => 'published',
            'waitlist_enabled' => true, 'enrollment_approval_mode' => 'manual',
        ]);
        $group->sessions()->create([
            'starts_at' => '2026-11-02T14:00:00Z', 'ends_at' => '2026-11-02T15:00:00Z',
            'capacity' => $capacity, 'status' => 'scheduled',
        ]);
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
            'internal_note' => null,
        ]);
        app(TenantContext::class)->clear();

        return [$organization, $group, $application];
    }

    private function anotherApplication(Organization $organization, EnrollmentApplication $source): EnrollmentApplication
    {
        app(TenantContext::class)->select($organization);
        $parent = User::factory()->create();
        $child = $parent->children()->create(['first_name' => 'Боби', 'last_name' => 'Петров']);
        $application = EnrollmentApplication::query()->create([
            'parent_user_id' => $parent->id, 'child_id' => $child->id,
            'group_id' => $source->group_id, 'price_option_id' => $source->price_option_id,
            'scope' => $source->scope, 'source' => 'organization_catalog', 'status' => 'submitted',
            'idempotency_key' => fake()->uuid(), 'accepted_terms_version' => 'v1',
            'accepted_terms_at' => now(), 'amount_cents_snapshot' => $source->amount_cents_snapshot,
            'currency_snapshot' => 'EUR', 'unit_snapshot' => $source->unit_snapshot,
        ]);
        app(TenantContext::class)->clear();

        return $application;
    }

    /** @return array{Group, EnrollmentApplication, string} */
    private function paintingApplication(Organization $organization): array
    {
        app(TenantContext::class)->select($organization);
        $parent = User::factory()->create();
        $child = $parent->children()->create(['first_name' => 'Цвети', 'last_name' => 'Цанева']);
        $activity = Activity::query()->create([
            'slug' => fake()->unique()->slug(), 'title' => 'Рисуване', 'category' => 'Изкуство',
            'summary' => 'Описание', 'age_label' => '1.–4. клас', 'status' => 'published',
        ]);
        $offering = $activity->offerings()->create([]);
        $group = $offering->groups()->create(['name' => 'Ателие', 'capacity_max' => 8, 'status' => 'published', 'enrollment_approval_mode' => 'manual']);
        $price = $offering->priceOptions()->create([
            'group_id' => $group->id, 'enrollment_mode' => 'monthly', 'unit' => 'month', 'amount_cents' => 3000, 'currency' => 'EUR', 'is_available' => true,
        ]);
        $this->travel(1)->minutes();
        $application = EnrollmentApplication::query()->create([
            'parent_user_id' => $parent->id, 'child_id' => $child->id, 'group_id' => $group->id,
            'price_option_id' => $price->id, 'scope' => 'full_period', 'source' => 'organization_catalog', 'status' => 'submitted',
            'idempotency_key' => fake()->uuid(), 'accepted_terms_version' => 'v1', 'accepted_terms_at' => now(),
            'amount_cents_snapshot' => 3000, 'currency_snapshot' => 'EUR', 'unit_snapshot' => 'month',
        ]);
        app(TenantContext::class)->clear();

        return [$group, $application, $activity->id];
    }
}
