<?php

namespace Tests\Feature\Organizations;

use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('authorization')]
class AuthorizationMatrixTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{string, bool, bool, bool, bool, bool}> */
    public static function roleMatrix(): iterable
    {
        yield 'platform administrator' => ['platform_admin', true, true, true, true, true];
        yield 'organization owner' => ['organization_owner', true, true, true, true, true];
        yield 'organization administrator' => ['organization_admin', true, true, true, false, true];
        yield 'coordinator' => ['coordinator', true, true, false, false, true];
        yield 'instructor' => ['instructor', false, false, false, false, true];
        yield 'finance' => ['finance', false, false, false, true, true];
        yield 'viewer' => ['viewer', false, false, false, false, true];
    }

    #[DataProvider('roleMatrix')]
    public function test_each_role_receives_only_its_authorized_capabilities(
        string $role,
        bool $create,
        bool $update,
        bool $invite,
        bool $finance,
        bool $view,
    ): void {
        $user = User::factory()->create();
        $organization = Organization::query()->withoutGlobalScopes()->create([
            'name' => 'Progressive School',
            'slug' => 'progressive-school-'.$role,
            'status' => 'active',
        ]);

        OrganizationMembership::query()->withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
        ]);

        app(TenantContext::class)->select($organization);
        $gate = Gate::forUser($user);

        $this->assertSame($create, $gate->allows('create', $organization));
        $this->assertSame($update, $gate->allows('update', $organization));
        $this->assertSame($invite, $gate->allows('invite', $organization));
        $this->assertSame($finance, $gate->allows('finance', $organization));
        $this->assertSame($view, $gate->allows('view', $organization));
    }

    public function test_membership_in_another_tenant_grants_no_permissions(): void
    {
        $user = User::factory()->create();
        $selected = Organization::query()->withoutGlobalScopes()->create([
            'name' => 'Selected School',
            'slug' => 'selected-school',
            'status' => 'active',
        ]);
        $foreign = Organization::query()->withoutGlobalScopes()->create([
            'name' => 'Foreign School',
            'slug' => 'foreign-school',
            'status' => 'active',
        ]);

        OrganizationMembership::query()->withoutGlobalScopes()->create([
            'organization_id' => $foreign->id,
            'user_id' => $user->id,
            'role' => 'organization_owner',
            'status' => 'active',
        ]);

        app(TenantContext::class)->select($selected);

        foreach (['create', 'update', 'invite', 'finance', 'view'] as $ability) {
            $this->assertFalse(Gate::forUser($user)->allows($ability, $selected));
        }
    }
}
