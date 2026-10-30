<?php

namespace Tests\Feature\PaymentTerms;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Catalog\Models\OrganizationCatalogProfile;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Domain\PaymentTerms\Enums\BillingKind;
use App\Domain\PaymentTerms\Enums\TermsPreset;
use App\Domain\PaymentTerms\Models\PaymentTerms;
use App\Domain\PaymentTerms\Models\PaymentTermsVersion;
use App\Domain\PaymentTerms\Rules\Presets;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Tests\TestCase;

/** Step 2b of docs/product/payment-policies.md: the organization's sets of payment terms and their versions. */
class PaymentTermsApiTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{string, bool}> */
    public static function roles(): iterable
    {
        yield 'owner' => ['organization_owner', true];
        yield 'administrator' => ['organization_admin', true];
        yield 'coordinator' => ['coordinator', false];
        yield 'instructor' => ['instructor', false];
        yield 'viewer' => ['viewer', false];
        yield 'finance' => ['finance', false];
    }

    #[DataProvider('roles')]
    public function test_the_team_reads_and_only_the_owner_and_the_administrator_change(string $role, bool $canManage): void
    {
        $organization = $this->organization();
        $owner = $this->member($organization, 'organization_owner');
        $member = $this->member($organization, $role);
        $id = $this->as($owner, $organization)->postJson('/api/v1/payment-terms', $this->body(BillingKind::Monthly))
            ->assertCreated()->json('payment_terms.id');

        $this->as($member, $organization)->getJson('/api/v1/payment-terms')
            ->assertOk()
            ->assertJsonPath('can_manage', $canManage)
            ->assertJsonPath('payment_terms.0.id', $id)
            ->assertJsonPath('billing_kinds.0.value', 'monthly')
            ->assertJsonPath('billing_kinds.0.label', 'Месечна такса')
            ->assertJsonPath('billing_kinds.0.presets.standard.label', 'Стандартни');
        $this->as($member, $organization)->postJson('/api/v1/payment-terms', $this->body(BillingKind::Visit))
            ->assertStatus($canManage ? 201 : 403);
        $this->as($member, $organization)->patchJson("/api/v1/payment-terms/{$id}", ['version' => 1, 'name' => 'Нови'])
            ->assertStatus($canManage ? 200 : 403);
    }

    public function test_a_restricted_organization_reads_but_does_not_change(): void
    {
        $organization = $this->organization('restricted');
        $owner = $this->member($organization, 'organization_owner');

        $this->as($owner, $organization)->getJson('/api/v1/payment-terms')->assertOk()->assertJsonPath('payment_terms', []);
        $this->as($owner, $organization)->postJson('/api/v1/payment-terms', $this->body(BillingKind::Monthly))->assertForbidden();
    }

    public function test_a_set_keeps_its_numbers_its_label_and_a_trace_without_the_numbers(): void
    {
        $organization = $this->organization();
        $owner = $this->member($organization, 'organization_owner');
        $foreign = $this->organization();

        $response = $this->as($owner, $organization)->postJson('/api/v1/payment-terms', [
            ...$this->body(BillingKind::Monthly, isDefault: true),
            'organization_id' => $foreign->id,
            'preset' => 'strict',
        ])->assertCreated()
            ->assertJsonPath('payment_terms.billing_kind', 'monthly')
            ->assertJsonPath('payment_terms.preset', 'standard')
            ->assertJsonPath('payment_terms.preset_label', 'Стандартни')
            ->assertJsonPath('payment_terms.is_default', true)
            ->assertJsonPath('payment_terms.version', 1)
            ->assertJsonPath('payment_terms.current_version.number', 1)
            ->assertJsonPath('payment_terms.current_version.schema_version', 1)
            ->assertJsonPath('payment_terms.current_version.rules.end.cutoff_day', 15)
            ->assertJsonPath('payment_terms.marketplace_violations', []);

        $id = $response->json('payment_terms.id');
        $this->assertDatabaseHas('payment_terms', ['id' => $id, 'organization_id' => $organization->id, 'created_by' => $owner->id]);
        $this->assertDatabaseMissing('payment_terms', ['organization_id' => $foreign->id]);

        // One number away from the ready-made set — the server calls it "Собствени".
        $rules = Presets::rules(BillingKind::Visit, TermsPreset::Standard);
        $rules['no_show_kept'] = 50;
        $this->as($owner, $organization)->postJson('/api/v1/payment-terms', [...$this->body(BillingKind::Visit), 'rules' => $rules])
            ->assertCreated()->assertJsonPath('payment_terms.preset', 'custom');

        $audit = AuditEvent::query()->where('action', 'payment_terms.created')->where('subject_id', $id)->sole();
        $this->assertSame($organization->id, $audit->organization_id);
        $this->assertSame(['billing_kind', 'preset', 'is_default', 'version', 'terms_version_id'], array_keys($audit->metadata));
        $this->assertStringNotContainsString('cutoff_day', json_encode($audit->metadata, JSON_THROW_ON_ERROR));
    }

    public function test_wrong_numbers_are_refused_in_bulgarian_under_their_field(): void
    {
        $organization = $this->organization();
        $owner = $this->member($organization, 'organization_owner');
        $rules = Presets::rules(BillingKind::Monthly, TermsPreset::Standard);
        $rules['end']['cutoff_day'] = 31;
        // A number written as text is not a number.
        $rules['pause']['notice_days'] = '7';

        $this->as($owner, $organization)->postJson('/api/v1/payment-terms', [...$this->body(BillingKind::Monthly), 'rules' => $rules])
            ->assertUnprocessable()
            ->assertJsonPath('errors', fn (array $errors): bool => $errors['rules.end.cutoff_day'] === ['„ден от месеца“ трябва да е от 1 до 28.']
                && $errors['rules.pause.notice_days'] === ['„предизвестие за пауза“ трябва да е цяло число.']);
        $this->as($owner, $organization)->postJson('/api/v1/payment-terms', [...$this->body(BillingKind::Monthly), 'self_service' => ['absence' => true]])
            ->assertUnprocessable()->assertJsonValidationErrors(['self_service.pause_weeks', 'self_service.end', 'self_service.cancel_visit']);
        $this->as($owner, $organization)->postJson('/api/v1/payment-terms', [...$this->body(BillingKind::Monthly), 'billing_kind' => 'lottery', 'name' => ' '])
            ->assertUnprocessable()
            ->assertJsonPath('errors.billing_kind.0', 'Изберете начин на плащане от списъка.')
            ->assertJsonPath('errors.name.0', 'Въведете име на набора.');

        $this->as($owner, $organization)->postJson('/api/v1/payment-terms', $this->body(BillingKind::Monthly, name: 'Летни'))->assertCreated();
        $this->as($owner, $organization)->postJson('/api/v1/payment-terms', $this->body(BillingKind::Monthly, name: 'ЛЕТНИ '))
            ->assertUnprocessable()->assertJsonPath('errors.name.0', 'Вече има набор с това име за този начин на плащане.');
        // The same name is fine for another way of paying.
        $this->as($owner, $organization)->postJson('/api/v1/payment-terms', $this->body(BillingKind::Visit, name: 'Летни'))->assertCreated();
        $this->assertSame(2, $this->inOrganization($organization, fn (): int => PaymentTerms::query()->count()));
    }

    public function test_a_change_of_numbers_is_a_new_version_and_the_old_one_stays(): void
    {
        $organization = $this->organization();
        $owner = $this->member($organization, 'organization_owner');
        $created = $this->as($owner, $organization)->postJson('/api/v1/payment-terms', $this->body(BillingKind::Visit))->assertCreated();
        $id = $created->json('payment_terms.id');
        $first = $created->json('payment_terms.current_version.id');

        // A new name keeps the numbers: no new version.
        $this->as($owner, $organization)->patchJson("/api/v1/payment-terms/{$id}", ['version' => 1, 'name' => 'Пробни'])
            ->assertOk()
            ->assertJsonPath('payment_terms.version', 2)
            ->assertJsonPath('payment_terms.current_version.id', $first);
        // The same numbers in another key order are the same numbers.
        $rules = array_reverse(Presets::rules(BillingKind::Visit, TermsPreset::Standard), true);
        $this->as($owner, $organization)->patchJson("/api/v1/payment-terms/{$id}", ['version' => 2, 'rules' => $rules])
            ->assertOk()->assertJsonPath('payment_terms.version', 2)->assertJsonPath('payment_terms.current_version.number', 1);

        $rules = Presets::rules(BillingKind::Visit, TermsPreset::Strict);
        $this->as($owner, $organization)->patchJson("/api/v1/payment-terms/{$id}", [
            'version' => 2, 'rules' => $rules, 'self_service' => Presets::selfService(TermsPreset::Strict),
        ])->assertOk()
            ->assertJsonPath('payment_terms.version', 3)
            ->assertJsonPath('payment_terms.preset', 'strict')
            ->assertJsonPath('payment_terms.current_version.number', 2)
            ->assertJsonPath('payment_terms.current_version.rules.cancellation.tiers.0.min_hours', 48);

        // Someone else saved in between.
        $this->as($owner, $organization)->patchJson("/api/v1/payment-terms/{$id}", ['version' => 2, 'name' => 'Стари'])
            ->assertConflict()->assertJsonPath('message', 'Условията са променени междувременно. Презаредете страницата и опитайте отново.');

        $this->inOrganization($organization, function () use ($first): void {
            $old = PaymentTermsVersion::query()->findOrFail($first);
            $this->assertSame(1, $old->number);
            $this->assertSame(24, $old->rules['cancellation']['tiers'][0]['min_hours']);
            $this->assertSame(2, PaymentTermsVersion::query()->count());
        });
        $this->assertSame(2, AuditEvent::query()->where('action', 'payment_terms.updated')->where('subject_id', $id)->count());
    }

    public function test_one_default_per_way_of_paying(): void
    {
        $organization = $this->organization();
        $owner = $this->member($organization, 'organization_owner');
        $first = $this->as($owner, $organization)->postJson('/api/v1/payment-terms', $this->body(BillingKind::Monthly, isDefault: true, name: 'Основни'))
            ->json('payment_terms.id');
        $visit = $this->as($owner, $organization)->postJson('/api/v1/payment-terms', $this->body(BillingKind::Visit, isDefault: true))
            ->json('payment_terms.id');
        $second = $this->as($owner, $organization)->postJson('/api/v1/payment-terms', $this->body(BillingKind::Monthly, name: 'Летни'))
            ->json('payment_terms.id');

        // The default is not switched off by itself, nor archived: another set takes its place.
        $this->as($owner, $organization)->patchJson("/api/v1/payment-terms/{$first}", ['version' => 1, 'is_default' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('is_default');
        $this->as($owner, $organization)->patchJson("/api/v1/payment-terms/{$first}", ['version' => 1, 'status' => 'archived'])
            ->assertUnprocessable()->assertJsonPath('errors.status.0', 'Наборът по подразбиране не може да се архивира. Първо изберете друг.');
        $this->as($owner, $organization)->patchJson("/api/v1/payment-terms/{$second}", ['version' => 1, 'is_default' => true])
            ->assertOk()->assertJsonPath('payment_terms.is_default', true);

        $this->inOrganization($organization, function () use ($first, $second, $visit): void {
            $this->assertFalse(PaymentTerms::query()->findOrFail($first)->is_default);
            // The old default changed too, so a screen holding it reloads before saving.
            $this->assertSame(2, PaymentTerms::query()->findOrFail($first)->version);
            $this->assertTrue(PaymentTerms::query()->findOrFail($second)->is_default);
            $this->assertTrue(PaymentTerms::query()->findOrFail($visit)->is_default, 'Another way of paying keeps its default.');
        });
        $this->assertFalse(AuditEvent::query()->where('action', 'payment_terms.updated')->where('subject_id', $first)->sole()->metadata['is_default']);

        // A set that is not the default is archived, is not edited while archived, and comes back.
        $this->as($owner, $organization)->patchJson("/api/v1/payment-terms/{$first}", ['version' => 2, 'status' => 'archived'])
            ->assertOk()->assertJsonPath('payment_terms.status', 'archived');
        $this->as($owner, $organization)->patchJson("/api/v1/payment-terms/{$first}", ['version' => 3, 'name' => 'Друго'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->as($owner, $organization)->postJson('/api/v1/payment-terms', $this->body(BillingKind::Monthly, name: 'Основни'))->assertCreated();
        $this->as($owner, $organization)->patchJson("/api/v1/payment-terms/{$first}", ['version' => 3, 'status' => 'active'])
            ->assertUnprocessable()->assertJsonPath('errors.name.0', 'Вече има набор с това име за този начин на плащане.');
        $this->as($owner, $organization)->patchJson("/api/v1/payment-terms/{$first}", ['version' => 3, 'status' => 'active', 'name' => 'Основни 2025'])
            ->assertOk()->assertJsonPath('payment_terms.status', 'active');
    }

    public function test_an_organization_in_the_public_catalog_stays_inside_its_frame(): void
    {
        $organization = $this->organization();
        $owner = $this->member($organization, 'organization_owner');
        $harsh = Presets::rules(BillingKind::Visit, TermsPreset::Strict);
        $harsh['cancellation']['tiers'] = [['min_hours' => 48, 'kept' => 100], ['min_hours' => 0, 'kept' => 100]];

        // Before the catalog the organization may keep everything; the screen is told what will not fit.
        $id = $this->as($owner, $organization)->postJson('/api/v1/payment-terms', [...$this->body(BillingKind::Visit), 'rules' => $harsh])
            ->assertCreated()->assertJsonPath('payment_terms.preset', 'custom')->json('payment_terms.id');
        $this->inOrganization($organization, fn () => OrganizationCatalogProfile::query()->create(['public_name' => 'Школа', 'is_published' => true]));

        $this->as($owner, $organization)->getJson('/api/v1/payment-terms')
            ->assertOk()
            ->assertJsonPath('in_marketplace', true)
            ->assertJsonPath('payment_terms.0.marketplace_violations', fn (array $violations): bool => $violations === ['cancellation.tiers' => 'В каталога на платформата поне при ранен отказ трябва да се връща нещо.']);
        $this->as($owner, $organization)->postJson('/api/v1/payment-terms', [...$this->body(BillingKind::Visit, name: 'Още'), 'rules' => $harsh])
            ->assertUnprocessable()
            ->assertJsonPath('errors', fn (array $errors): bool => $errors['rules.cancellation.tiers'] === ['В каталога на платформата поне при ранен отказ трябва да се връща нещо.']);
        $this->as($owner, $organization)->patchJson("/api/v1/payment-terms/{$id}", ['version' => 1, 'name' => 'Строги'])
            ->assertUnprocessable()->assertJsonValidationErrors('rules.cancellation.tiers');
        // Archiving is always possible; fixing the numbers clears the warning.
        $this->as($owner, $organization)->patchJson("/api/v1/payment-terms/{$id}", ['version' => 1, 'rules' => Presets::rules(BillingKind::Visit, TermsPreset::Strict)])
            ->assertOk()->assertJsonPath('payment_terms.marketplace_violations', [])
            // "Строги" numbers with "Стандартни" self-service are the organization's own mix.
            ->assertJsonPath('payment_terms.preset', 'custom');
    }

    #[TestGroup('tenant-isolation')]
    public function test_another_organizations_sets_are_not_seen_or_changed(): void
    {
        $organization = $this->organization();
        $owner = $this->member($organization, 'organization_owner');
        $foreign = $this->organization();
        $foreignOwner = $this->member($foreign, 'organization_owner');
        $foreignId = $this->as($foreignOwner, $foreign)->postJson('/api/v1/payment-terms', $this->body(BillingKind::Monthly, isDefault: true))
            ->assertCreated()->json('payment_terms.id');

        $this->as($owner, $organization)->getJson('/api/v1/payment-terms')->assertOk()->assertJsonPath('payment_terms', []);
        $this->as($owner, $organization)->patchJson("/api/v1/payment-terms/{$foreignId}", ['version' => 1, 'name' => 'Чужди'])->assertNotFound();
        // A default here does not touch the other organization's default.
        $this->as($owner, $organization)->postJson('/api/v1/payment-terms', $this->body(BillingKind::Monthly, isDefault: true))->assertCreated();

        $this->inOrganization($foreign, function () use ($foreignId): void {
            $set = PaymentTerms::query()->findOrFail($foreignId);
            $this->assertSame('Стандартни', $set->name);
            $this->assertTrue($set->is_default);
            $this->assertSame(1, $set->version);
        });
    }

    public function test_a_version_never_changes(): void
    {
        $organization = $this->organization();
        $owner = $this->member($organization, 'organization_owner');
        $versionId = $this->as($owner, $organization)->postJson('/api/v1/payment-terms', $this->body(BillingKind::Package))
            ->json('payment_terms.current_version.id');

        $this->inOrganization($organization, function () use ($versionId): void {
            $version = PaymentTermsVersion::query()->findOrFail($versionId);
            try {
                $version->update(['number' => 5]);
                $this->fail('A version must not be changed through the model.');
            } catch (LogicException) {
            }
            $this->expectException(QueryException::class);
            DB::table('payment_terms_versions')->where('id', $versionId)->update(['rules' => '{}']);
        });
    }

    /** @return array{billing_kind: string, name: string, is_default: bool, rules: array<string, mixed>, self_service: array<string, mixed>} */
    private function body(BillingKind $kind, bool $isDefault = false, string $name = 'Стандартни'): array
    {
        return [
            'billing_kind' => $kind->value,
            'name' => $name,
            'is_default' => $isDefault,
            'rules' => Presets::rules($kind, TermsPreset::Standard),
            'self_service' => Presets::selfService(TermsPreset::Standard),
        ];
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
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
        ]);

        return $user;
    }

    private function organization(string $status = 'active'): Organization
    {
        return Organization::query()->withoutGlobalScopes()->create([
            'name' => 'Школа '.Str::random(6),
            'slug' => 'terms-'.Str::lower(Str::random(12)),
            'status' => $status,
        ]);
    }
}
