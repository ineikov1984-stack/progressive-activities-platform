<?php

namespace Tests\Feature\Audit;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('audit')]
class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    public function test_successful_login_records_actor_request_and_no_credentials(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.test', 'password' => 'Never-store-this-password']);
        $requestId = '01JBAUDITLOGIN000000000001';

        $this->withHeader('X-Request-Id', $requestId)
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Never-store-this-password'])
            ->assertOk()
            ->assertHeader('X-Request-Id', $requestId);

        $event = DB::table('audit_events')->sole();
        $this->assertSame($user->id, $event->actor_id);
        $this->assertNull($event->organization_id);
        $this->assertSame('identity.login_succeeded', $event->action);
        $this->assertSame('user', $event->subject_type);
        $this->assertSame($user->id, $event->subject_id);
        $this->assertSame($requestId, $event->correlation_id);
        $this->assertNotNull($event->created_at);
        $serialized = json_encode($event, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Never-store-this-password', $serialized);
        $this->assertStringNotContainsString('password', strtolower($serialized));
    }

    public function test_tenant_switch_and_invitation_are_recorded_in_the_business_context(): void
    {
        [$owner, $organization] = $this->memberWithOrganization('organization_owner');

        $this->actingAs($owner)
            ->withHeader('X-Request-Id', '01JBAUDITSWITCH00000000001')
            ->postJson('/api/v1/context/organization', ['organization_id' => $organization->id])
            ->assertOk();

        $this->withSession(['active_organization_id' => $organization->id])
            ->withHeader('X-Request-Id', '01JBAUDITINVITE00000000001')
            ->postJson('/api/v1/invitations', ['email' => 'teacher@example.test', 'role' => 'instructor'])
            ->assertCreated();

        $switch = DB::table('audit_events')->where('action', 'organization.context_selected')->sole();
        $this->assertSame($owner->id, $switch->actor_id);
        $this->assertSame($organization->id, $switch->organization_id);
        $this->assertSame('organization', $switch->subject_type);
        $this->assertSame($organization->id, $switch->subject_id);

        $invite = DB::table('audit_events')->where('action', 'organization.invitation_created')->sole();
        $this->assertSame($organization->id, $invite->organization_id);
        $this->assertSame('invitation', $invite->subject_type);
        $this->assertSame('01JBAUDITINVITE00000000001', $invite->correlation_id);
        $metadata = json_decode($invite->metadata, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('instructor', $metadata['role']);
        $this->assertArrayNotHasKey('token', $metadata);
        $this->assertArrayNotHasKey('token_hash', $metadata);
    }

    public function test_recorder_redacts_sensitive_metadata_and_supports_future_actions(): void
    {
        [$owner, $organization] = $this->memberWithOrganization('organization_owner');
        $this->actingAs($owner);
        app(TenantContext::class)->select($organization);

        app(AuditRecorder::class)->record(
            AuditAction::CatalogActivitySubmitted,
            new AuditableReference('activity', 'activity-123'),
            ['version' => 2, 'password' => 'secret', 'nested' => ['access_token' => 'secret-token', 'safe' => 'kept']],
        );

        $metadata = json_decode(DB::table('audit_events')->sole()->metadata, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(2, $metadata['version']);
        $this->assertSame('kept', $metadata['nested']['safe']);
        $this->assertArrayNotHasKey('password', $metadata);
        $this->assertArrayNotHasKey('access_token', $metadata['nested']);
    }

    public function test_accepting_an_invitation_records_the_new_role_only_once(): void
    {
        [$owner, $organization] = $this->memberWithOrganization('organization_owner');
        $token = $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->postJson('/api/v1/invitations', ['email' => 'teacher@example.test', 'role' => 'instructor'])
            ->assertCreated()
            ->json('token');
        $teacher = User::factory()->create(['email' => 'teacher@example.test']);

        $this->actingAs($teacher)
            ->withSession(['active_organization_id' => $organization->id])
            ->postJson('/api/v1/invitations/accept', ['token' => $token])
            ->assertOk();
        $this->postJson('/api/v1/invitations/accept', ['token' => $token])->assertOk();

        $events = DB::table('audit_events')->where('action', 'organization.role_changed')->get();
        $this->assertCount(1, $events);
        $metadata = json_decode($events->first()->metadata, true, flags: JSON_THROW_ON_ERROR);
        $this->assertNull($metadata['from']);
        $this->assertSame('instructor', $metadata['to']);
    }

    public function test_audit_rows_cannot_be_updated_or_deleted(): void
    {
        [$owner, $organization] = $this->memberWithOrganization('organization_owner');
        $this->actingAs($owner);
        app(TenantContext::class)->select($organization);
        app(AuditRecorder::class)->record(
            AuditAction::OrganizationRoleChanged,
            new AuditableReference('membership', 'membership-123'),
            ['from' => 'viewer', 'to' => 'instructor'],
        );
        $id = DB::table('audit_events')->value('id');

        $updateRejected = false;
        try {
            DB::table('audit_events')->where('id', $id)->update(['action' => 'tampered']);
        } catch (\Throwable) {
            $updateRejected = true;
        }
        $this->assertTrue($updateRejected);

        $deleteRejected = false;
        try {
            DB::table('audit_events')->where('id', $id)->delete();
        } catch (\Throwable) {
            $deleteRejected = true;
        }
        $this->assertTrue($deleteRejected);
        $this->assertDatabaseHas('audit_events', ['id' => $id, 'action' => 'organization.role_changed']);
    }

    public function test_authorized_member_can_list_only_the_active_tenant_events(): void
    {
        [$owner, $organization] = $this->memberWithOrganization('organization_owner');
        [, $foreignOrganization] = $this->memberWithOrganization('organization_owner');
        $this->actingAs($owner);

        $context = app(TenantContext::class);
        $context->select($organization);
        app(AuditRecorder::class)->record(
            AuditAction::OrganizationRoleChanged,
            new AuditableReference('membership', 'own-membership'),
        );
        $context->select($foreignOrganization);
        app(AuditRecorder::class)->record(
            AuditAction::OrganizationRoleChanged,
            new AuditableReference('membership', 'foreign-membership'),
        );
        $context->clear();

        $this->withSession(['active_organization_id' => $organization->id])
            ->getJson('/api/v1/audit-events')
            ->assertOk()
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.subject_id', 'own-membership');
    }

    /** @return array{User, Organization} */
    private function memberWithOrganization(string $role): array
    {
        $user = User::factory()->create();
        $organization = Organization::query()->withoutGlobalScopes()->create([
            'name' => 'Progressive School',
            'slug' => 'audit-'.str_replace('_', '-', $role).'-'.Str::lower(Str::random(8)),
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
