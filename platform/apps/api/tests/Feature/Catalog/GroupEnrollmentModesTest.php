<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\OrganizationCatalogProfile;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Domain\PaymentTerms\Enums\BillingKind;
use App\Domain\PaymentTerms\Services\AppliedTerms;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Each group takes some of its activity's ways of enrolling (the owner's review of 30.10.2026): a beginners' group
 * a monthly fee and single visits, an advanced group only the whole period. Parents see and use only those.
 */
class GroupEnrollmentModesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->travelTo('2026-10-28 10:00:00');
    }

    public function test_a_group_takes_only_its_ways_of_enrolling(): void
    {
        $organization = Organization::query()->withoutGlobalScopes()->create([
            'name' => 'Школа', 'slug' => 'modes-'.Str::lower(Str::random(10)), 'status' => 'active', 'public_contact_email' => 'school@example.test',
        ]);
        $owner = User::factory()->create();
        OrganizationMembership::query()->withoutGlobalScopes()->create([
            'organization_id' => $organization->id, 'user_id' => $owner->id, 'role' => 'organization_owner', 'status' => 'active',
        ]);
        app(TenantContext::class)->select($organization);
        OrganizationCatalogProfile::query()->create(['public_name' => 'Школа', 'is_published' => true]);
        app(TenantContext::class)->clear();
        $team = fn () => $this->actingAs($owner)->withSession(['active_organization_id' => $organization->id]);

        $activity = $team()->postJson('/api/v1/catalog/activities', [
            'title' => 'Шахмат', 'category' => 'Наука', 'summary' => 'Синтетично описание.', 'age_label' => '7–10 г.',
            'offering' => ['price_options' => [
                ['enrollment_mode' => 'monthly', 'unit' => 'month', 'amount_cents' => 6000],
                ['enrollment_mode' => 'full_period', 'unit' => 'period', 'amount_cents' => 30000],
                ['enrollment_mode' => 'single', 'unit' => 'session', 'amount_cents' => 1500],
            ]],
        ])->assertCreated()->json('activity');
        $prices = collect($activity['offering']['price_options'])->pluck('id', 'enrollment_mode');
        $group = fn (string $name, ?array $modes) => $team()->postJson("/api/v1/catalog/activities/{$activity['id']}/groups", [
            'name' => $name, 'capacity_max' => 10, 'enrollment_modes' => $modes,
            'schedule_pattern' => ['start_date' => '2026-11-02', 'end_date' => '2026-11-30', 'frequency' => 'weekly',
                'slots' => [['weekday' => $name === 'Начинаещи' ? 1 : 3, 'starts_at' => '16:00', 'duration_minutes' => 60]]],
        ]);

        $beginners = $group('Начинаещи', null)->assertCreated()->assertJsonPath('group.enrollment_modes', null)->json('group.id');
        $advanced = $group('Напреднали', ['full_period'])->assertCreated()->assertJsonPath('group.enrollment_modes', ['full_period'])->json('group.id');
        // Only a way of enrolling the activity has a price for.
        $group('Карта', ['package'])->assertUnprocessable()
            ->assertJsonPath('errors', fn (array $errors): bool => $errors['enrollment_modes.0'] === ['Дейността няма такъв начин на записване. Добавете го в стъпка 6 „Цени и записване“.']);
        $version = $team()->getJson("/api/v1/catalog/activities/{$activity['id']}")->json('activity.version');
        $team()->postJson("/api/v1/catalog/activities/{$activity['id']}/publish", ['version' => $version])->assertOk();

        // Parents see each group's own ways of enrolling.
        $public = $this->getJson("/api/v1/public/organizations/{$organization->slug}/activities/{$activity['slug']}")->assertOk();
        $modes = fn (string $id): array => collect($public->json('activity.groups'))->firstWhere('id', $id)['prices'] ?? [];
        $this->assertSame(['monthly', 'full_period', 'single'], array_column($modes($beginners), 'enrollment_mode'));
        $this->assertSame(['full_period'], array_column($modes($advanced), 'enrollment_mode'));

        // …and apply only with them; the activity's prices are used by every group that takes them.
        $parent = User::factory()->create(['phone' => '+359881234567']);
        $apply = function (string $groupId, string $mode, string $key) use ($parent, $prices): TestResponse {
            $child = $parent->children()->create(['first_name' => 'Ния', 'last_name' => 'Синтетична']);

            return $this->actingAs($parent)->withHeader('Idempotency-Key', $key)->postJson('/api/v1/parent/enrollment-applications', [
                'child_id' => $child->id, 'group_id' => $groupId, 'price_option_id' => $prices[$mode],
                'accepted_terms_version' => AppliedTerms::standard(BillingKind::fromEnrollmentMode($mode) ?? BillingKind::Monthly)->key(),
            ]);
        };
        $apply($beginners, 'monthly', 'beginners-monthly')->assertCreated()->assertJsonPath('application.price.amount_cents', 6000);
        $apply($advanced, 'monthly', 'advanced-monthly')->assertUnprocessable()->assertJsonValidationErrors('price_option_id');
        $apply($advanced, 'full_period', 'advanced-period')->assertCreated()->assertJsonPath('application.price.unit', 'period');
    }
}
