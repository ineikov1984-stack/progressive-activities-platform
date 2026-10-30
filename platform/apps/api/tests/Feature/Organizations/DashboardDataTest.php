<?php

namespace Tests\Feature\Organizations;

use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Invitation;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('demo-dashboard')]
class DashboardDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    public function test_organization_list_includes_role_and_effective_permissions(): void
    {
        [$owner, $organization] = $this->memberWithOrganization('organization_owner');

        $this->actingAs($owner)
            ->getJson('/api/v1/organizations')
            ->assertOk()
            ->assertJsonPath('organizations.0.id', $organization->id)
            ->assertJsonPath('organizations.0.role', 'organization_owner')
            ->assertJsonPath('organizations.0.permissions', [
                'create', 'update', 'invite', 'finance', 'view',
                'catalog.view', 'catalog.manage', 'catalog.publish',
                'enrollment.view', 'enrollment.manage', 'enrollment.contacts', 'terms.manage',
                'team.view', 'team.manage',
            ]);
    }

    public function test_owner_lists_only_current_tenant_invitations_without_secret_fields(): void
    {
        [$owner, $organization] = $this->memberWithOrganization('organization_owner');
        $foreign = Organization::query()->withoutGlobalScopes()->create([
            'name' => 'Foreign School',
            'slug' => 'foreign-school-dashboard',
            'status' => 'active',
        ]);

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->postJson('/api/v1/invitations', ['email' => 'teacher@example.test', 'role' => 'instructor'])
            ->assertCreated();

        Invitation::query()->withoutGlobalScopes()->create([
            'organization_id' => $foreign->id,
            'email' => 'foreign@example.test',
            'role' => 'viewer',
            'token_hash' => str_repeat('a', 64),
            'active_key' => str_repeat('b', 64),
            'expires_at' => now()->addDay(),
            'invited_by' => $owner->id,
        ]);

        $response = $this->getJson('/api/v1/invitations')
            ->assertOk()
            ->assertJsonCount(1, 'invitations')
            ->assertJsonPath('invitations.0.email', 'teacher@example.test')
            ->assertJsonMissing(['email' => 'foreign@example.test']);

        $payload = $response->json();
        $this->assertArrayNotHasKey('token_hash', $payload['invitations'][0]);
        $this->assertArrayNotHasKey('active_key', $payload['invitations'][0]);
    }

    /** @return array{User, Organization} */
    private function memberWithOrganization(string $role): array
    {
        $user = User::factory()->create();
        $organization = Organization::query()->withoutGlobalScopes()->create([
            'name' => 'Progressive Academy Demo',
            'slug' => 'progressive-academy-demo-'.$role,
            'status' => 'active',
        ]);

        OrganizationMembership::query()->withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
        ]);

        return [$user, $organization];
    }
}
