<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Catalog\Models\Location;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CatalogResourcesTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{string, bool, bool, bool}> */
    public static function roles(): iterable
    {
        yield 'owner' => ['organization_owner', true, true, true];
        yield 'administrator' => ['organization_admin', true, true, true];
        yield 'coordinator' => ['coordinator', true, true, false];
        // The teacher's menu is "Начало" and their own groups (DECISIONS 31.10.2026).
        yield 'instructor' => ['instructor', false, false, false];
        yield 'viewer' => ['viewer', true, false, false];
        yield 'finance' => ['finance', false, false, false];
    }

    #[DataProvider('roles')]
    public function test_catalog_permissions_and_resource_routes_follow_membership_role(
        string $role,
        bool $canView,
        bool $canManage,
        bool $canPublish,
    ): void {
        [$user, $organization] = $this->member($role);
        app(TenantContext::class)->select($organization);

        $this->assertSame($canView, Gate::forUser($user)->allows('catalog.view', $organization));
        $this->assertSame($canManage, Gate::forUser($user)->allows('catalog.manage', $organization));
        $this->assertSame($canPublish, Gate::forUser($user)->allows('catalog.publish', $organization));
        $location = Location::query()->create(['name' => 'Existing studio', 'type' => 'online']);
        $instructor = InstructorProfile::query()->create(['public_name' => 'Existing teacher']);

        $this->actingAs($user)->withSession(['active_organization_id' => $organization->id]);
        $this->getJson('/api/v1/catalog/profile')->assertStatus($canView ? 200 : 403);
        $this->getJson('/api/v1/catalog/locations')->assertStatus($canView ? 200 : 403);
        $this->getJson('/api/v1/catalog/instructors')->assertStatus($canView ? 200 : 403);
        $this->postJson('/api/v1/catalog/locations', ['name' => 'Studio', 'type' => 'online'])
            ->assertStatus($canManage ? 201 : 403);
        $this->postJson('/api/v1/catalog/instructors', ['public_name' => 'Teacher'])
            ->assertStatus($canManage ? 201 : 403);
        $this->patchJson("/api/v1/catalog/locations/{$location->id}", ['name' => 'Renamed studio'])
            ->assertStatus($canManage ? 200 : 403);
        $this->patchJson("/api/v1/catalog/instructors/{$instructor->id}", ['role_title' => 'Art teacher'])
            ->assertStatus($canManage ? 200 : 403);
        $this->patchJson('/api/v1/catalog/profile', ['public_name' => 'School'])
            ->assertStatus($canManage ? 200 : 403);
        $this->patchJson('/api/v1/catalog/profile', ['public_name' => 'School', 'is_published' => true])
            ->assertStatus($canPublish ? 200 : 403);
    }

    public function test_catalog_permissions_do_not_cross_organization_context(): void
    {
        [$user, $foreign] = $this->member('organization_owner');
        $selected = $this->organization();
        app(TenantContext::class)->select($selected);

        foreach (['catalog.view', 'catalog.manage', 'catalog.publish'] as $ability) {
            $this->assertFalse(Gate::forUser($user)->allows($ability, $foreign));
            $this->assertFalse(Gate::forUser($user)->allows($ability, $selected));
        }
    }

    public function test_restricted_organization_can_read_but_cannot_write_catalog(): void
    {
        [$user, $organization] = $this->member('organization_owner', 'restricted');
        $this->actingAs($user)->withSession(['active_organization_id' => $organization->id]);

        $this->getJson('/api/v1/catalog/locations')->assertOk();
        $this->postJson('/api/v1/catalog/locations', ['name' => 'Studio', 'type' => 'online'])->assertForbidden();
        $this->patchJson('/api/v1/catalog/profile', ['public_name' => 'School'])->assertForbidden();
    }

    public function test_locations_validate_type_and_address_and_do_not_accept_tenant_override(): void
    {
        [$user, $organization] = $this->member('organization_owner');
        $foreign = $this->organization();
        $this->actingAs($user)->withSession(['active_organization_id' => $organization->id]);

        $this->postJson('/api/v1/catalog/locations', ['name' => 'Hall', 'type' => 'physical'])
            ->assertUnprocessable()->assertJsonValidationErrors('address');
        $this->postJson('/api/v1/catalog/locations', ['name' => 'Hall', 'type' => 'unknown'])
            ->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->postJson('/api/v1/catalog/locations', [
            'name' => 'Online studio', 'type' => 'online', 'organization_id' => $foreign->id,
        ])->assertCreated()->assertJsonPath('location.name', 'Online studio');

        $this->assertDatabaseHas('locations', ['name' => 'Online studio', 'organization_id' => $organization->id]);
        $this->assertDatabaseMissing('locations', ['name' => 'Online studio', 'organization_id' => $foreign->id]);
    }

    public function test_location_patch_validates_resulting_physical_address_and_foreign_uuid_is_hidden(): void
    {
        [$user, $organization] = $this->member('organization_owner');
        app(TenantContext::class)->select($organization);
        $location = Location::query()->create(['name' => 'Meeting', 'type' => 'online']);
        $foreign = $this->organization();
        app(TenantContext::class)->select($foreign);
        $foreignLocation = Location::query()->create(['name' => 'Foreign', 'type' => 'online']);

        $this->actingAs($user)->withSession(['active_organization_id' => $organization->id]);
        $this->patchJson("/api/v1/catalog/locations/{$location->id}", ['type' => 'physical'])
            ->assertUnprocessable()->assertJsonValidationErrors('address');
        $this->patchJson("/api/v1/catalog/locations/{$location->id}", ['type' => 'physical', 'address' => '1 Main Street'])
            ->assertOk()->assertJsonPath('location.type', 'physical');
        $this->patchJson("/api/v1/catalog/locations/{$location->id}", ['address' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('address');
        $this->getJson('/api/v1/catalog/locations')
            ->assertOk()->assertJsonPath('locations.0.name', 'Meeting');
        $this->patchJson("/api/v1/catalog/locations/{$foreignLocation->id}", ['name' => 'Changed'])->assertNotFound();
        $this->assertDatabaseHas('locations', ['id' => $foreignLocation->id, 'name' => 'Foreign']);
    }

    public function test_instructors_require_public_name_and_foreign_uuid_is_hidden(): void
    {
        [$user, $organization] = $this->member('organization_owner');
        $foreign = $this->organization();
        app(TenantContext::class)->select($foreign);
        $foreignInstructor = InstructorProfile::query()->create(['public_name' => 'Foreign teacher']);

        $this->actingAs($user)->withSession(['active_organization_id' => $organization->id]);
        $this->postJson('/api/v1/catalog/instructors', ['bio' => 'Teaches art'])
            ->assertUnprocessable()->assertJsonValidationErrors('public_name');
        $created = $this->postJson('/api/v1/catalog/instructors', ['public_name' => 'Local teacher', 'organization_id' => $foreign->id])
            ->assertCreated()->assertJsonPath('instructor.public_name', 'Local teacher');
        $this->patchJson('/api/v1/catalog/instructors/'.$created->json('instructor.id'), ['role_title' => 'Art teacher'])
            ->assertOk()->assertJsonPath('instructor.role_title', 'Art teacher');
        $this->getJson('/api/v1/catalog/instructors')
            ->assertOk()->assertJsonPath('instructors.0.public_name', 'Local teacher');
        $this->patchJson("/api/v1/catalog/instructors/{$foreignInstructor->id}", ['public_name' => 'Changed'])
            ->assertNotFound();
        $this->assertDatabaseHas('instructor_profiles', ['public_name' => 'Local teacher', 'organization_id' => $organization->id]);
        $this->assertDatabaseHas('instructor_profiles', ['id' => $foreignInstructor->id, 'public_name' => 'Foreign teacher']);
    }

    public function test_profile_validates_color_and_requires_name_to_publish(): void
    {
        [$user, $organization] = $this->member('organization_owner');
        $this->actingAs($user)->withSession(['active_organization_id' => $organization->id]);

        $this->getJson('/api/v1/catalog/profile')->assertOk()->assertJsonPath('profile', null);
        $this->patchJson('/api/v1/catalog/profile', ['accent_color' => 'blue'])
            ->assertUnprocessable()->assertJsonValidationErrors('accent_color');
        $this->patchJson('/api/v1/catalog/profile', ['is_published' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('public_name');
        $this->patchJson('/api/v1/catalog/profile', ['public_name' => 'Progressive School', 'accent_color' => '#A1b2C3'])
            ->assertOk()->assertJsonPath('profile.accent_color', '#A1b2C3');
        $this->patchJson('/api/v1/catalog/profile', ['is_published' => true])
            ->assertOk()->assertJsonPath('profile.is_published', true);
        $this->assertDatabaseHas('organization_catalog_profiles', ['organization_id' => $organization->id, 'public_name' => 'Progressive School']);
    }

    /** @return array{User, Organization} */
    private function member(string $role, string $status = 'active'): array
    {
        $user = User::factory()->create();
        $organization = $this->organization($status);
        OrganizationMembership::query()->withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
        ]);

        return [$user, $organization];
    }

    private function organization(string $status = 'active'): Organization
    {
        $slug = 'catalog-'.Str::random(12);

        return Organization::query()->withoutGlobalScopes()->create([
            'name' => 'Catalog organization',
            'slug' => $slug,
            'status' => $status,
        ]);
    }
}
