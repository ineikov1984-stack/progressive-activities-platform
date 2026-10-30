<?php

namespace Tests\Feature\Organizations;

use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('authorization')]
class InvitationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    public function test_owner_can_invite_a_member_and_only_a_hash_is_stored(): void
    {
        [$owner, $organization] = $this->memberWithOrganization('organization_owner');

        $response = $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->postJson('/api/v1/invitations', [
                'email' => 'Teacher@Example.test ',
                'role' => 'instructor',
            ])
            ->assertCreated()
            ->assertJsonPath('invitation.email', 'teacher@example.test');

        $token = $response->json('token');
        $this->assertIsString($token);
        $this->assertNotSame('', $token);

        $stored = DB::table('invitations')->first();
        $this->assertSame(hash('sha256', $token), $stored->token_hash);
        $this->assertStringNotContainsString($token, json_encode($stored, JSON_THROW_ON_ERROR));
    }

    public function test_duplicate_pending_invitation_is_rejected(): void
    {
        [$owner, $organization] = $this->memberWithOrganization('organization_owner');
        $payload = ['email' => 'teacher@example.test', 'role' => 'instructor'];

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->postJson('/api/v1/invitations', $payload)
            ->assertCreated();

        $this->postJson('/api/v1/invitations', $payload)
            ->assertConflict();

        $this->assertDatabaseCount('invitations', 1);
    }

    public function test_expired_invitation_cannot_be_accepted(): void
    {
        [$owner, $organization] = $this->memberWithOrganization('organization_owner');
        $token = $this->issueInvitation($owner, $organization, 'teacher@example.test');
        DB::table('invitations')->update(['expires_at' => now()->subSecond()]);
        $teacher = User::factory()->create(['email' => 'teacher@example.test']);

        $this->actingAs($teacher)
            ->withSession(['active_organization_id' => $organization->id])
            ->postJson('/api/v1/invitations/accept', ['token' => $token])
            ->assertGone();

        $this->assertDatabaseMissing('organization_memberships', [
            'organization_id' => $organization->id,
            'user_id' => $teacher->id,
        ]);
    }

    public function test_replaying_an_accepted_invitation_is_idempotent(): void
    {
        [$owner, $organization] = $this->memberWithOrganization('organization_owner');
        $token = $this->issueInvitation($owner, $organization, 'teacher@example.test');
        $teacher = User::factory()->create(['email' => 'teacher@example.test']);

        $firstMembership = $this->actingAs($teacher)
            ->withSession(['active_organization_id' => $organization->id])
            ->postJson('/api/v1/invitations/accept', ['token' => $token])
            ->assertOk()
            ->json('membership.id');

        $this->postJson('/api/v1/invitations/accept', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('membership.id', $firstMembership);

        $this->assertDatabaseCount('organization_memberships', 2);
        // The person of the team for the login, once (docs/product/team.md, т. 3).
        $this->assertSame(1, DB::table('staff_members')->where('organization_id', $organization->id)->where('user_id', $teacher->id)->count());
    }

    public function test_invitation_cannot_be_accepted_by_a_different_email(): void
    {
        [$owner, $organization] = $this->memberWithOrganization('organization_owner');
        $token = $this->issueInvitation($owner, $organization, 'teacher@example.test');
        $differentUser = User::factory()->create(['email' => 'other@example.test']);

        $this->actingAs($differentUser)
            ->withSession(['active_organization_id' => $organization->id])
            ->postJson('/api/v1/invitations/accept', ['token' => $token])
            ->assertForbidden();
    }

    public function test_invitation_cannot_be_used_in_another_tenant(): void
    {
        [$owner, $organization] = $this->memberWithOrganization('organization_owner');
        $token = $this->issueInvitation($owner, $organization, 'teacher@example.test');
        $teacher = User::factory()->create(['email' => 'teacher@example.test']);
        $foreign = Organization::query()->withoutGlobalScopes()->create([
            'name' => 'Foreign School',
            'slug' => 'foreign-school',
            'status' => 'active',
        ]);

        $this->actingAs($teacher)
            ->withSession(['active_organization_id' => $foreign->id])
            ->postJson('/api/v1/invitations/accept', ['token' => $token])
            ->assertNotFound();
    }

    public function test_organization_admin_cannot_invite_a_platform_administrator(): void
    {
        [$administrator, $organization] = $this->memberWithOrganization('organization_admin');

        $this->actingAs($administrator)
            ->withSession(['active_organization_id' => $organization->id])
            ->postJson('/api/v1/invitations', [
                'email' => 'admin@example.test',
                'role' => 'platform_admin',
            ])
            ->assertForbidden();
    }

    /** @return array{User, Organization} */
    private function memberWithOrganization(string $role): array
    {
        $user = User::factory()->create();
        $organization = Organization::query()->withoutGlobalScopes()->create([
            'name' => 'Progressive School',
            'slug' => 'progressive-school-'.str_replace('_', '-', $role),
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

    private function issueInvitation(User $owner, Organization $organization, string $email): string
    {
        return $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->postJson('/api/v1/invitations', ['email' => $email, 'role' => 'instructor'])
            ->assertCreated()
            ->json('token');
    }
}
