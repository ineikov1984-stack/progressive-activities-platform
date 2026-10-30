<?php

namespace Tests\Feature\PaymentTerms;

use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\Offering;
use App\Domain\Catalog\Models\OrganizationCatalogProfile;
use App\Domain\Catalog\Models\PriceOption;
use App\Domain\Enrollment\Models\Child;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Enrollment\Models\EnrollmentTransition;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Domain\PaymentTerms\Enums\BillingKind;
use App\Domain\PaymentTerms\Enums\TermsPreset;
use App\Domain\PaymentTerms\Models\PaymentTerms;
use App\Domain\PaymentTerms\Rules\Presets;
use App\Domain\PaymentTerms\Services\AppliedTerms;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Tests\TestCase;

/**
 * Step 2d of docs/product/payment-policies.md (т. 7.2–7.3, т. 8): an activity uses the organization's terms or a
 * set chosen for it, the parent reads them next to the price, and the application keeps a copy that never changes.
 */
class PaymentTermsCopyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->travelTo('2026-10-28 10:00:00');
    }

    public function test_the_parent_reads_the_terms_that_apply_to_each_price(): void
    {
        $f = $this->fixture();

        // Nothing saved yet: the platform's "Стандартни" for a monthly fee.
        $this->publicPrice($f)
            ->assertJsonPath('payment_terms.version', AppliedTerms::standard(BillingKind::Monthly)->key())
            ->assertJsonPath('payment_terms.billing_kind', 'monthly')
            ->assertJsonPath('payment_terms.billing_kind_label', 'Месечна такса')
            ->assertJsonPath('payment_terms.preset_label', 'Стандартни')
            ->assertJsonPath('payment_terms.rules.end.cutoff_day', 15);

        // The organization's own default for monthly fees.
        $default = $this->saveTerms($f, TermsPreset::Flexible, isDefault: true);
        $this->publicPrice($f)
            ->assertJsonPath('payment_terms.version', $default['version'])
            ->assertJsonPath('payment_terms.preset', 'flexible')
            ->assertJsonPath('payment_terms.rules.end.mode', 'by_sessions');

        // A set chosen for the activity wins over the default; a set for another way of paying does not apply.
        $strict = $this->saveTerms($f, TermsPreset::Strict, name: 'Летен интензив');
        $this->inOrganization($f['organization'], fn () => $f['offering']->update(['payment_terms_id' => $strict['id']]));
        $this->publicPrice($f)->assertJsonPath('payment_terms.version', $strict['version'])
            ->assertJsonPath('payment_terms.name', 'Летен интензив');
        $visit = $this->saveTerms($f, TermsPreset::Strict, BillingKind::Visit);
        $this->inOrganization($f['organization'], fn () => $f['offering']->update(['payment_terms_id' => $visit['id']]));
        $this->publicPrice($f)->assertJsonPath('payment_terms.version', $default['version']);
    }

    public function test_the_parent_accepts_the_terms_they_read_and_the_copy_never_changes(): void
    {
        $f = $this->fixture();
        $terms = $this->saveTerms($f, TermsPreset::Standard, isDefault: true);

        $this->apply($f, 'old-version', 'stale')->assertUnprocessable()
            ->assertJsonPath('errors.accepted_terms_version.0', 'Условията са променени — прегледайте ги отново.');
        $id = $this->apply($f, $terms['version'], 'accepted', ['early_start' => true])->assertCreated()
            ->assertJsonPath('application.accepted_terms_version', $terms['version'])
            ->assertJsonPath('application.payment_terms.name', 'Условията на организацията')
            ->assertJsonPath('application.payment_terms.rules.pause.notice_days', 7)
            ->assertJsonPath('application.payment_terms.early_start', true)
            ->json('application.id');

        $copy = $this->inOrganization($f['organization'], fn () => EnrollmentApplication::query()->findOrFail($id));
        $this->assertSame($terms['version'], $copy->payment_terms_version_id);
        $this->assertSame(['via' => 'parent', 'at' => '2026-10-28T10:00:00+00:00'], $copy->payment_terms_snapshot['accepted']);
        $this->assertSame(Presets::canonical(Presets::rules(BillingKind::Monthly, TermsPreset::Standard)), Presets::canonical($copy->payment_terms_snapshot['rules']));

        // The organization changes its numbers: new parents read the new version, this copy stays.
        $this->as($f['owner'], $f['organization'])->patchJson("/api/v1/payment-terms/{$terms['id']}", [
            'version' => 1, 'rules' => Presets::rules(BillingKind::Monthly, TermsPreset::Strict),
        ])->assertOk();
        $newVersion = $this->publicPrice($f)->json('payment_terms.version');
        $this->assertNotSame($terms['version'], $newVersion);
        $this->apply($f, $terms['version'], 'after-change', child: $this->secondChild($f))->assertUnprocessable()
            ->assertJsonValidationErrors('accepted_terms_version');
        $this->as($f['owner'], $f['organization'])->postJson("/api/v1/enrollment/applications/{$id}/review", ['decision' => 'approve', 'version' => 1])
            ->assertOk();
        $approved = $this->inOrganization($f['organization'], fn () => Enrollment::query()->where('application_id', $id)->sole()->application);
        $this->assertSame($terms['version'], $approved->payment_terms_version_id);
        $this->assertSame(7, $approved->payment_terms_snapshot['rules']['pause']['notice_days']);

        // Not even a direct write changes what the parent accepted.
        $this->expectException(QueryException::class);
        DB::table('enrollment_applications')->where('id', $id)->update(['payment_terms_snapshot' => '{}']);
    }

    public function test_terms_outside_the_catalog_frame_close_the_price_for_new_applications(): void
    {
        $f = $this->fixture();
        // Saved before the organization joined the catalog; the screen already warns about them.
        $harsh = Presets::rules(BillingKind::Monthly, TermsPreset::Standard);
        $harsh['end']['notice_days'] = 60;
        $this->inOrganization($f['organization'], function () use ($harsh): void {
            $terms = PaymentTerms::query()->create([
                'billing_kind' => 'monthly', 'name' => 'Дълго предизвестие', 'preset' => 'custom', 'is_default' => true,
                'self_service' => Presets::selfService(TermsPreset::Standard), 'status' => 'active', 'version' => 1,
            ]);
            $terms->versions()->create(['number' => 1, 'schema_version' => 1, 'rules' => $harsh]);
        });

        $version = $this->publicPrice($f)->json('payment_terms.version');
        $this->apply($f, $version, 'harsh')->assertUnprocessable()->assertJsonValidationErrors('price_option_id');
    }

    public function test_a_move_keeps_the_copy_or_takes_the_new_terms_for_another_way_of_paying(): void
    {
        $f = $this->fixture();
        $monthly = $this->saveTerms($f, TermsPreset::Standard, isDefault: true);
        $package = $this->saveTerms($f, TermsPreset::Flexible, BillingKind::Package, isDefault: true);
        $id = $this->apply($f, $monthly['version'], 'move')->assertCreated()->json('application.id');
        $this->as($f['owner'], $f['organization'])->postJson("/api/v1/enrollment/applications/{$id}/review", ['decision' => 'approve', 'version' => 1])->assertOk();
        [$second, $samePrice, $third, $packagePrice] = $this->inOrganization($f['organization'], function () use ($f): array {
            $second = $this->group($f['offering'], 'Четвъртък');
            $third = $this->group($f['offering'], 'Събота');

            return [$second, $this->price($second, 'monthly'), $third, $this->price($third, 'package')];
        });
        $enrollment = fn () => $this->inOrganization($f['organization'], fn () => Enrollment::query()->with('application')->where('status', 'active')->sole());

        // The same way of paying: the parent's copy moves with the child.
        $this->as($f['owner'], $f['organization'])->postJson("/api/v1/enrollment/enrollments/{$enrollment()->id}/transfer", [
            'target_group_id' => $second->id, 'price_option_id' => $samePrice->id, 'effective_date' => '2026-11-12',
            'reason' => 'Семейството помоли за друг ден.', 'version' => 1,
        ])->assertOk();
        $moved = $enrollment()->application;
        $this->assertSame($monthly['version'], $moved->payment_terms_version_id);
        $this->assertSame('parent', $moved->payment_terms_snapshot['accepted']['via']);
        $this->assertSame('inherited', $this->transitionTerms($f, $moved));

        // Another way of paying: the team accepts the new group's terms for the parent.
        $this->as($f['owner'], $f['organization'])->postJson("/api/v1/enrollment/enrollments/{$enrollment()->id}/transfer", [
            'target_group_id' => $third->id, 'price_option_id' => $packagePrice->id, 'effective_date' => '2026-11-14',
            'reason' => 'Семейството избра карта с посещения.', 'version' => 1,
        ])->assertOk();
        $renewed = $enrollment()->application;
        $this->assertSame($package['version'], $renewed->payment_terms_version_id);
        $this->assertSame($package['version'], $renewed->accepted_terms_version);
        $this->assertSame('package', $renewed->payment_terms_snapshot['billing_kind']);
        $this->assertSame(['via' => 'team', 'by_user_id' => $f['owner']->id, 'at' => '2026-10-28T10:00:00+00:00'], $renewed->payment_terms_snapshot['accepted']);
        $this->assertSame('accepted_by_team', $this->transitionTerms($f, $renewed));
    }

    public function test_the_team_does_not_accept_terms_outside_the_catalog_frame_for_the_parent(): void
    {
        $f = $this->fixture();
        $id = $this->apply($f, AppliedTerms::standard(BillingKind::Monthly)->key(), 'frame')->assertCreated()->json('application.id');
        $this->as($f['owner'], $f['organization'])->postJson("/api/v1/enrollment/applications/{$id}/review", ['decision' => 'approve', 'version' => 1])->assertOk();
        // A whole-course set saved before the organization joined the catalog: nothing comes back, ever.
        $harsh = Presets::rules(BillingKind::FullPeriod, TermsPreset::Standard);
        $harsh['before_start']['tiers'] = array_map(fn (array $tier): array => [...$tier, 'kept' => 100], $harsh['before_start']['tiers']);
        [$group, $price, $enrollment] = $this->inOrganization($f['organization'], function () use ($f, $harsh): array {
            $terms = PaymentTerms::query()->create([
                'billing_kind' => 'full_period', 'name' => 'Отпреди каталога', 'preset' => 'custom', 'is_default' => true,
                'self_service' => Presets::selfService(TermsPreset::Standard), 'status' => 'active', 'version' => 1,
            ]);
            $terms->versions()->create(['number' => 1, 'schema_version' => 1, 'rules' => $harsh]);
            $group = $this->group($f['offering'], 'Събота');

            return [$group, $this->price($group, 'full_period'), Enrollment::query()->where('status', 'active')->sole()];
        });

        $this->as($f['owner'], $f['organization'])->postJson("/api/v1/enrollment/enrollments/{$enrollment->id}/transfer", [
            'target_group_id' => $group->id, 'price_option_id' => $price->id, 'effective_date' => '2026-11-14',
            'reason' => 'Семейството избра целия курс.', 'version' => 1,
        ])->assertUnprocessable()->assertJsonPath('errors.price_option_id.0',
            'Условията за тази цена не са в рамките на каталога на платформата. Променете ги в „Настройки → Условия за плащане“.');
    }

    #[TestGroup('tenant-isolation')]
    public function test_another_organizations_terms_never_apply(): void
    {
        $f = $this->fixture();
        $g = $this->fixture();
        $foreign = $this->saveTerms($g, TermsPreset::Strict, isDefault: true);

        // Even pointed at directly, another organization's set is not read: its own terms apply.
        DB::table('offerings')->where('id', $f['offering']->id)->update(['payment_terms_id' => $foreign['id']]);
        $this->publicPrice($f)->assertJsonPath('payment_terms.version', AppliedTerms::standard(BillingKind::Monthly)->key());
        $this->apply($f, $foreign['version'], 'foreign')->assertUnprocessable()->assertJsonValidationErrors('accepted_terms_version');
        $this->publicPrice($g)->assertJsonPath('payment_terms.version', $foreign['version']);
    }

    #[TestGroup('tenant-isolation')]
    public function test_the_editor_chooses_only_an_own_active_set_for_a_price_the_activity_has(): void
    {
        $f = $this->fixture();
        $own = $this->saveTerms($f, TermsPreset::Strict);
        $visit = $this->saveTerms($f, TermsPreset::Strict, BillingKind::Visit);
        $archived = $this->saveTerms($f, TermsPreset::Flexible, name: 'Стари');
        $this->as($f['owner'], $f['organization'])->patchJson("/api/v1/payment-terms/{$archived['id']}", ['version' => 1, 'status' => 'archived'])->assertOk();
        $foreignOrganization = $this->organization();
        $foreign = $this->inOrganization($foreignOrganization, fn () => PaymentTerms::query()->create([
            'billing_kind' => 'monthly', 'name' => 'Чужди', 'preset' => 'strict', 'is_default' => false,
            'self_service' => Presets::selfService(TermsPreset::Strict), 'status' => 'active', 'version' => 1,
        ])->id);

        $draft = fn (?string $terms) => $this->as($f['owner'], $f['organization'])->postJson('/api/v1/catalog/activities', [
            'title' => 'Рисуване', 'category' => 'Изкуство', 'summary' => 'Синтетично описание.', 'age_label' => '6–8 г.',
            'offering' => [
                'payment_terms_id' => $terms,
                'price_options' => [['enrollment_mode' => 'monthly', 'unit' => 'month', 'amount_cents' => 6000]],
            ],
        ]);
        $activityId = $draft($own['id'])->assertCreated()->assertJsonPath('activity.offering.payment_terms_id', $own['id'])->json('activity.id');
        foreach ([$foreign, $archived['id']] as $refused) {
            $draft($refused)->assertUnprocessable()->assertJsonPath('errors', fn (array $errors): bool => $errors['offering.payment_terms_id'] === ['Изберете условия от „Настройки → Условия за плащане“.']);
        }
        $draft($visit['id'])->assertUnprocessable()->assertJsonPath('errors', fn (array $errors): bool => $errors['offering.payment_terms_id']
            === ['Тези условия са за „Пробно или единично посещение“, а дейността няма такава цена.']);

        // Back to the organization's terms.
        $this->as($f['owner'], $f['organization'])->patchJson("/api/v1/catalog/activities/{$activityId}", ['version' => 1, 'offering' => ['payment_terms_id' => null]])
            ->assertOk()->assertJsonPath('activity.offering.payment_terms_id', null);
    }

    /** @return array{organization: Organization, owner: User, parent: User, child: Child, activity: Activity, offering: Offering, group: Group, price: PriceOption} */
    private function fixture(): array
    {
        $organization = $this->organization();
        $owner = $this->member($organization, 'organization_owner');

        return $this->inOrganization($organization, function () use ($organization, $owner): array {
            OrganizationCatalogProfile::query()->create(['public_name' => 'Школа', 'is_published' => true]);
            $parent = User::factory()->create(['phone' => '+359881234567']);
            $child = $parent->children()->create(['first_name' => 'Ния', 'last_name' => 'Синтетична']);
            $activity = Activity::query()->create([
                'slug' => 'chess-'.Str::lower(Str::random(6)), 'title' => 'Шахмат', 'category' => 'Наука',
                'summary' => 'Описание', 'age_label' => '7–9 г.', 'status' => 'published',
            ]);
            $offering = $activity->offerings()->create([]);
            $group = $this->group($offering, 'Вторник');

            return compact('organization', 'owner', 'parent', 'child', 'activity', 'offering', 'group') + ['price' => $this->price($group, 'monthly')];
        });
    }

    private function group(Offering $offering, string $name): Group
    {
        $group = $offering->groups()->create(['name' => $name, 'capacity_max' => 10, 'status' => 'published', 'waitlist_enabled' => true, 'enrollment_approval_mode' => 'manual']);
        foreach (['2026-11-03', '2026-11-10', '2026-11-17', '2026-11-24'] as $date) {
            $day = date('Y-m-d', strtotime($date.' +'.($name === 'Вторник' ? 0 : ($name === 'Четвъртък' ? 2 : 4)).' days'));
            $group->sessions()->create(['starts_at' => "{$day}T14:00:00Z", 'ends_at' => "{$day}T15:00:00Z", 'status' => 'scheduled']);
        }

        return $group;
    }

    private function price(Group $group, string $mode): PriceOption
    {
        return $group->offering->priceOptions()->create([
            'group_id' => $group->id, 'enrollment_mode' => $mode,
            'unit' => match ($mode) {
                'package' => 'package', 'full_period' => 'period', default => 'month'
            },
            'amount_cents' => 6000, 'currency' => 'EUR', 'is_available' => true,
        ]);
    }

    /** @return array{id: string, version: string} */
    private function saveTerms(array $f, TermsPreset $preset, BillingKind $kind = BillingKind::Monthly, bool $isDefault = false, ?string $name = null): array
    {
        $response = $this->as($f['owner'], $f['organization'])->postJson('/api/v1/payment-terms', [
            'billing_kind' => $kind->value,
            'name' => $name ?? ($isDefault ? 'Условията на организацията' : $preset->label()),
            'is_default' => $isDefault,
            'rules' => Presets::rules($kind, $preset),
            'self_service' => Presets::selfService($preset),
        ])->assertCreated();

        return ['id' => $response->json('payment_terms.id'), 'version' => $response->json('payment_terms.current_version.id')];
    }

    /** The first price of the group on the activity's public page. */
    private function publicPrice(array $f): TestResponse
    {
        $response = $this->getJson("/api/v1/public/organizations/{$f['organization']->slug}/activities/{$f['activity']->slug}")->assertOk();
        $price = collect($response->json('activity.groups.0.prices'))->firstWhere('id', $f['price']->id);
        $this->assertNotNull($price);

        return TestResponse::fromBaseResponse(response()->json($price));
    }

    /** @param array<string, mixed> $extra */
    private function apply(array $f, string $version, string $key, array $extra = [], ?Child $child = null): TestResponse
    {
        return $this->actingAs($f['parent'])->withHeader('Idempotency-Key', $key)->postJson('/api/v1/parent/enrollment-applications', [
            'child_id' => ($child ?? $f['child'])->id, 'group_id' => $f['group']->id, 'price_option_id' => $f['price']->id,
            'accepted_terms_version' => $version, ...$extra,
        ]);
    }

    private function secondChild(array $f): Child
    {
        return $f['parent']->children()->create(['first_name' => 'Боби', 'last_name' => 'Синтетичен']);
    }

    private function transitionTerms(array $f, EnrollmentApplication $application): ?string
    {
        return $this->inOrganization($f['organization'], fn () => EnrollmentTransition::query()
            ->where('application_id', $application->id)->sole()->metadata['payment_terms'] ?? null);
    }

    private function as(User $user, Organization $organization): static
    {
        return $this->actingAs($user)->withSession(['active_organization_id' => $organization->id]);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function inOrganization(Organization $organization, callable $callback): mixed
    {
        $context = app(TenantContext::class);
        $context->select($organization);
        try {
            return $callback();
        } finally {
            $context->clear();
        }
    }

    private function member(Organization $organization, string $role): User
    {
        $user = User::factory()->create();
        OrganizationMembership::query()->withoutGlobalScopes()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active',
        ]);

        return $user;
    }

    private function organization(): Organization
    {
        return Organization::query()->withoutGlobalScopes()->create([
            'name' => 'Школа '.Str::random(6), 'slug' => 'copy-'.Str::lower(Str::random(12)), 'status' => 'active',
        ]);
    }
}
