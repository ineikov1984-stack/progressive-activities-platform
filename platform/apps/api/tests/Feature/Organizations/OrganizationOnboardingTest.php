<?php

namespace Tests\Feature\Organizations;

use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Models\OrganizationReview;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('organization-onboarding')]
class OrganizationOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    public function test_applicant_owner_reads_only_the_safe_application_state(): void
    {
        [$owner, $organization] = $this->applicant('organization_owner', [
            'public_review_reason' => 'Нужна е корекция на ЕИК.',
            'next_action' => 'Проверете регистрационния номер.',
        ]);
        OrganizationReview::query()->withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'operator_id' => User::factory()->create()->id,
            'from_status' => 'review',
            'to_status' => 'applicant',
            'review_version' => 2,
            'public_reason' => 'Нужна е корекция на ЕИК.',
            'next_action' => 'Проверете регистрационния номер.',
            'internal_note' => 'Private risk note',
        ]);

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->getJson('/api/v1/organization-application')
            ->assertOk()
            ->assertJsonPath('application.id', $organization->id)
            ->assertJsonPath('application.status', 'applicant')
            ->assertJsonPath('application.public_review_reason', 'Нужна е корекция на ЕИК.')
            ->assertJsonPath('application.next_action', 'Проверете регистрационния номер.')
            ->assertJsonMissingPath('application.internal_note')
            ->assertJsonMissingPath('application.reviewed_by')
            ->assertJsonMissing(['Private risk note']);
    }

    public function test_applicant_owner_updates_a_draft_with_optimistic_locking(): void
    {
        [$owner, $organization] = $this->applicant();
        $payload = [
            'review_version' => 1,
            'name' => 'Ателие Любознание',
            'organization_type' => 'club',
            'registration_identifier' => 'BG123456789',
            'public_contact_email' => 'office@example.test',
        ];

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->patchJson('/api/v1/organization-application', $payload)
            ->assertOk()
            ->assertJsonPath('application.review_version', 2)
            ->assertJsonPath('application.name', 'Ателие Любознание');

        $this->patchJson('/api/v1/organization-application', $payload)
            ->assertConflict()
            ->assertJsonPath('application.review_version', 2);
    }

    public function test_applicant_owner_submits_complete_application_once(): void
    {
        [$owner, $organization] = $this->applicant('organization_owner', $this->completeFields());

        $first = $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->postJson('/api/v1/organization-application/submit', ['review_version' => 1])
            ->assertOk()
            ->assertJsonPath('application.status', 'review')
            ->assertJsonPath('application.review_version', 2);

        $this->postJson('/api/v1/organization-application/submit', ['review_version' => 1])
            ->assertOk()
            ->assertJsonPath('application.id', $first->json('application.id'))
            ->assertJsonPath('application.review_version', 2);

        $this->assertSame(1, DB::table('audit_events')->where('action', 'organization.application_submitted')->count());
    }

    public function test_incomplete_application_cannot_be_submitted(): void
    {
        [$owner, $organization] = $this->applicant();

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->postJson('/api/v1/organization-application/submit', ['review_version' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['organization_type', 'registration_identifier', 'public_contact_email']);
    }

    public function test_only_owner_can_submit_and_active_organization_cannot_submit(): void
    {
        [$administrator, $applicant] = $this->applicant('organization_admin', $this->completeFields());
        $this->actingAs($administrator)
            ->withSession(['active_organization_id' => $applicant->id])
            ->postJson('/api/v1/organization-application/submit', ['review_version' => 1])
            ->assertForbidden();

        [$owner, $active] = $this->applicant('organization_owner', [
            ...$this->completeFields(),
            'status' => OrganizationStatus::Active->value,
        ]);
        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $active->id])
            ->postJson('/api/v1/organization-application/submit', ['review_version' => 1])
            ->assertConflict();
    }

    public function test_applicant_cannot_read_another_tenants_application(): void
    {
        [$owner, $organization] = $this->applicant();
        [, $foreign] = $this->applicant();

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->withHeader('X-Organization-Id', $foreign->id)
            ->getJson('/api/v1/organization-application')
            ->assertNotFound();
    }

    public function test_platform_operator_bootstraps_and_lists_an_applicant_without_exposing_token(): void
    {
        $operator = $this->platformOperator();

        $created = $this->actingAs($operator)->postJson('/api/v1/operator/organization-applications', [
            'name' => 'Ново ателие',
            'slug' => 'novo-atelie',
            'owner_email' => 'owner@example.test',
        ])->assertCreated()
            ->assertJsonPath('application.status', 'applicant')
            ->assertJsonPath('application.review_version', 1)
            ->assertJsonStructure(['owner_invitation_token'])
            ->json();

        $this->getJson('/api/v1/operator/organization-applications')
            ->assertOk()
            ->assertJsonFragment(['id' => $created['application']['id']])
            ->assertJsonMissingPath('applications.0.owner_invitation_token')
            ->assertJsonMissing(['token_hash']);
    }

    public function test_non_operator_cannot_use_operator_application_routes(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/operator/organization-applications')
            ->assertForbidden();
    }

    public function test_operator_reviews_application_atomically_and_records_private_history(): void
    {
        $operator = $this->platformOperator();
        [, $organization] = $this->applicant('organization_owner', [
            ...$this->completeFields(),
            'status' => OrganizationStatus::Review->value,
        ]);

        $payload = [
            'review_version' => 1,
            'to_status' => 'approved',
            'internal_note' => 'Verified registration document.',
        ];

        $this->actingAs($operator)
            ->postJson("/api/v1/operator/organization-applications/{$organization->id}/review", $payload)
            ->assertOk()
            ->assertJsonPath('application.status', 'approved')
            ->assertJsonPath('application.review_version', 2)
            ->assertJsonMissing(['Verified registration document.']);

        $this->postJson("/api/v1/operator/organization-applications/{$organization->id}/review", $payload)
            ->assertConflict();

        $this->assertSame(1, OrganizationReview::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'organization.application_reviewed')->count());
    }

    public function test_operator_cannot_self_review_and_change_request_needs_public_guidance(): void
    {
        $operator = $this->platformOperator();
        [, $organization] = $this->applicant('organization_owner', [
            ...$this->completeFields(),
            'status' => OrganizationStatus::Review->value,
        ]);
        OrganizationMembership::query()->withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'user_id' => $operator->id,
            'role' => 'viewer',
            'status' => 'active',
        ]);

        $this->actingAs($operator)
            ->postJson("/api/v1/operator/organization-applications/{$organization->id}/review", [
                'review_version' => 1,
                'to_status' => 'applicant',
            ])->assertForbidden();

        OrganizationMembership::query()->withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('user_id', $operator->id)
            ->delete();

        $this->postJson("/api/v1/operator/organization-applications/{$organization->id}/review", [
            'review_version' => 1,
            'to_status' => 'applicant',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['public_reason', 'next_action']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{User, Organization}
     */
    private function applicant(string $role = 'organization_owner', array $overrides = []): array
    {
        $user = User::factory()->create();
        $organization = Organization::query()->withoutGlobalScopes()->create([
            'name' => 'Кандидат организация',
            'slug' => 'applicant-'.Str::lower(Str::random(10)),
            'status' => OrganizationStatus::Applicant->value,
            ...$overrides,
        ]);
        OrganizationMembership::query()->withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
        ]);

        return [$user, $organization];
    }

    /** @return array<string, string> */
    private function completeFields(): array
    {
        return [
            'organization_type' => 'club',
            'registration_identifier' => 'BG123456789',
            'public_contact_email' => 'office@example.test',
        ];
    }

    private function platformOperator(): User
    {
        [$operator] = $this->applicant('platform_admin', [
            'status' => OrganizationStatus::Active->value,
        ]);

        return $operator;
    }
}
