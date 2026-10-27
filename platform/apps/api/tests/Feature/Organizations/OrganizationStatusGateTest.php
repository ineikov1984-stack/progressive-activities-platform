<?php

namespace Tests\Feature\Organizations;

use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Enums\OrganizationAccessMode;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('organization-onboarding')]
class OrganizationStatusGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);

        Route::middleware(['web', 'auth:sanctum', 'tenant', 'organization.access:onboarding'])
            ->get('/api/test/status/onboarding', fn () => response()->json(['allowed' => true]));
        Route::middleware(['web', 'auth:sanctum', 'tenant', 'organization.access:operational.read'])
            ->get('/api/test/status/read', fn () => response()->json(['allowed' => true]));
        Route::middleware(['web', 'auth:sanctum', 'tenant', 'organization.access:operational.write'])
            ->post('/api/test/status/write', fn () => response()->json(['allowed' => true]));
    }

    /** @return iterable<string, array{OrganizationStatus, OrganizationStatus}> */
    public static function allowedTransitions(): iterable
    {
        yield 'applicant submits' => [OrganizationStatus::Applicant, OrganizationStatus::Review];
        yield 'review requests changes' => [OrganizationStatus::Review, OrganizationStatus::Applicant];
        yield 'review approves' => [OrganizationStatus::Review, OrganizationStatus::Approved];
        yield 'approved activates' => [OrganizationStatus::Approved, OrganizationStatus::Active];
        yield 'active restricts' => [OrganizationStatus::Active, OrganizationStatus::Restricted];
        yield 'restricted restores' => [OrganizationStatus::Restricted, OrganizationStatus::Active];
        yield 'suspended reopens' => [OrganizationStatus::Suspended, OrganizationStatus::Review];
        yield 'applicant suspends' => [OrganizationStatus::Applicant, OrganizationStatus::Suspended];
        yield 'review suspends' => [OrganizationStatus::Review, OrganizationStatus::Suspended];
        yield 'approved suspends' => [OrganizationStatus::Approved, OrganizationStatus::Suspended];
        yield 'active suspends' => [OrganizationStatus::Active, OrganizationStatus::Suspended];
        yield 'restricted suspends' => [OrganizationStatus::Restricted, OrganizationStatus::Suspended];
    }

    #[DataProvider('allowedTransitions')]
    public function test_only_explicit_lifecycle_transitions_are_allowed(
        OrganizationStatus $from,
        OrganizationStatus $to,
    ): void {
        $this->assertTrue($from->canTransitionTo($to));
    }

    public function test_unspecified_and_same_status_transitions_are_rejected(): void
    {
        foreach (OrganizationStatus::cases() as $from) {
            foreach (OrganizationStatus::cases() as $to) {
                $isSpecified = collect(self::allowedTransitions())
                    ->contains(fn (array $pair): bool => $pair === [$from, $to]);

                $this->assertSame($isSpecified, $from->canTransitionTo($to), "Unexpected {$from->value} → {$to->value}");
            }
        }
    }

    /** @return iterable<string, array{OrganizationStatus, bool, bool, bool}> */
    public static function accessMatrix(): iterable
    {
        yield 'applicant' => [OrganizationStatus::Applicant, true, false, false];
        yield 'review' => [OrganizationStatus::Review, true, false, false];
        yield 'approved' => [OrganizationStatus::Approved, true, false, false];
        yield 'active' => [OrganizationStatus::Active, true, true, true];
        yield 'restricted' => [OrganizationStatus::Restricted, true, true, false];
        yield 'suspended' => [OrganizationStatus::Suspended, true, false, false];
    }

    #[DataProvider('accessMatrix')]
    public function test_status_gate_enforces_direct_api_access(
        OrganizationStatus $status,
        bool $onboarding,
        bool $read,
        bool $write,
    ): void {
        [$user, $organization] = $this->memberWithOrganization($status);
        $session = ['active_organization_id' => $organization->id];

        $this->actingAs($user)->withSession($session)
            ->getJson('/api/test/status/onboarding')
            ->assertStatus($onboarding ? 200 : 403);
        $this->withSession($session)
            ->getJson('/api/test/status/read')
            ->assertStatus($read ? 200 : 403);
        $this->withSession($session)
            ->postJson('/api/test/status/write')
            ->assertStatus($write ? 200 : 403);
    }

    public function test_access_modes_are_explicit_values(): void
    {
        $this->assertSame('onboarding', OrganizationAccessMode::Onboarding->value);
        $this->assertSame('operational.read', OrganizationAccessMode::OperationalRead->value);
        $this->assertSame('operational.write', OrganizationAccessMode::OperationalWrite->value);
    }

    /** @return array{User, Organization} */
    private function memberWithOrganization(OrganizationStatus $status): array
    {
        $user = User::factory()->create();
        $organization = Organization::query()->withoutGlobalScopes()->create([
            'name' => 'Status '.$status->value,
            'slug' => 'status-'.$status->value,
            'status' => $status->value,
        ]);
        OrganizationMembership::query()->withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => 'organization_owner',
            'status' => 'active',
        ]);

        return [$user, $organization];
    }
}
