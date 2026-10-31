<?php

namespace Tests\Feature\Organizations;

use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Models\StaffMember;
use App\Domain\Organizations\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Tests\TestCase;

/**
 * "Последен вход" and "Последна активност" in "Екип" (DECISIONS 31.10.2026 „Екип — последен вход и филтър по роли“):
 * written by the system on sign-in, on selecting the organization and at most once in five minutes of use; seen only by
 * those who manage the team.
 */
class TeamLastLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    public function test_signing_in_records_the_last_login_in_every_team_of_the_person_only(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-31T08:00:00Z'));
        $teacher = User::factory()->create(['email' => 'teacher@example.test', 'password' => 'Correct-horse-42']);
        $school = $this->organization('Школа');
        $club = $this->organization('Клуб');
        $here = $this->membership($school, $teacher, 'instructor');
        $there = $this->membership($club, $teacher, 'viewer');
        $other = $this->membership($school, User::factory()->create(), 'coordinator');
        $before = DB::table('organization_memberships')->where('id', $here)->value('updated_at');
        $this->travelTo(CarbonImmutable::parse('2026-10-31T12:09:00Z'));

        $this->postJson('/api/v1/auth/login', ['email' => 'teacher@example.test', 'password' => 'Correct-horse-42'])->assertOk();

        $this->assertSame('2026-10-31 12:09:00', $this->moment($here, 'last_login_at'));
        $this->assertSame('2026-10-31 12:09:00', $this->moment($there, 'last_login_at'));
        // Someone else's membership is not touched, and no row's "updated_at" changes.
        $this->assertNull($this->moment($other, 'last_login_at'));
        $this->assertSame($before, DB::table('organization_memberships')->where('id', $here)->value('updated_at'));
        // Not the user's action: no audit of it.
        $this->assertSame(0, DB::table('audit_events')->where('subject_type', 'organization_membership')->count());
    }

    public function test_selecting_the_organization_records_the_last_login_there(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-31T12:09:00Z'));
        $owner = User::factory()->create();
        $school = $this->organization('Школа');
        $club = $this->organization('Клуб');
        $here = $this->membership($school, $owner, 'organization_owner');
        $there = $this->membership($club, $owner, 'viewer');

        $this->actingAs($owner)->postJson('/api/v1/context/organization', ['organization_id' => $school->id])->assertOk();

        $this->assertSame('2026-10-31 12:09:00', $this->moment($here, 'last_login_at'));
        $this->assertNull($this->moment($there, 'last_login_at'));
    }

    #[TestGroup('tenant-isolation')]
    public function test_activity_is_written_at_most_once_in_five_minutes_and_only_for_the_membership_in_use(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-31T12:00:00Z'));
        $owner = User::factory()->create();
        $school = $this->organization('Школа');
        $club = $this->organization('Клуб');
        $foreign = $this->organization('Чужда');
        $here = $this->membership($school, $owner, 'organization_owner');
        $there = $this->membership($club, $owner, 'organization_owner');
        $colleague = $this->membership($school, User::factory()->create(), 'coordinator');
        $stranger = $this->membership($foreign, User::factory()->create(), 'organization_owner');
        $this->person($school, $owner);
        $before = DB::table('organization_memberships')->where('id', $here)->value('updated_at');
        $team = fn (Organization $organization) => $this->actingAs($owner)->withHeader('X-Organization-Id', $organization->id)->getJson('/api/v1/team');

        $team($school)->assertOk();
        $this->assertSame('2026-10-31 12:00:00', $this->moment($here, 'last_seen_at'));

        // Within five minutes nothing is written again.
        $this->travelTo(CarbonImmutable::parse('2026-10-31T12:04:59Z'));
        $team($school)->assertOk();
        $this->assertSame('2026-10-31 12:00:00', $this->moment($here, 'last_seen_at'));

        // After them, once more.
        $this->travelTo(CarbonImmutable::parse('2026-10-31T12:05:01Z'));
        $team($school)->assertOk();
        $this->assertSame('2026-10-31 12:05:01', $this->moment($here, 'last_seen_at'));
        $this->assertSame($before, DB::table('organization_memberships')->where('id', $here)->value('updated_at'));

        // Another organization of the same person, a colleague and another organization: untouched.
        $this->assertNull($this->moment($there, 'last_seen_at'));
        $this->assertNull($this->moment($colleague, 'last_seen_at'));
        $team($foreign)->assertNotFound();
        $this->assertNull($this->moment($stranger, 'last_seen_at'));
    }

    public function test_only_those_who_manage_the_team_see_when_a_person_signed_in(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-31T12:09:00Z'));
        $owner = User::factory()->create();
        $coordinator = User::factory()->create();
        $school = $this->organization('Школа');
        $this->membership($school, $owner, 'organization_owner');
        $this->membership($school, $coordinator, 'coordinator');
        $this->person($school, $owner);
        $person = $this->person($school, $coordinator);
        DB::table('organization_memberships')->where('user_id', $coordinator->id)
            ->update(['last_login_at' => '2026-10-29 08:00:00', 'last_seen_at' => '2026-10-30 18:30:00']);

        $list = $this->actingAs($owner)->withHeader('X-Organization-Id', $school->id)->getJson('/api/v1/team')->assertOk()->json('members');
        $row = collect($list)->firstWhere('id', $person->id);
        $this->assertSame('2026-10-29T08:00:00+00:00', $row['last_login_at']);
        $this->assertSame('2026-10-30T18:30:00+00:00', $row['last_seen_at']);
        $this->getJson("/api/v1/team/members/{$person->id}")->assertOk()->assertJsonPath('member.last_login_at', '2026-10-29T08:00:00+00:00');

        $list = $this->actingAs($coordinator)->getJson('/api/v1/team')->assertOk()->json('members');
        foreach ($list as $row) {
            $this->assertArrayNotHasKey('last_login_at', $row);
            $this->assertArrayNotHasKey('last_seen_at', $row);
        }
        $this->getJson("/api/v1/team/members/{$person->id}")->assertOk()->assertJsonMissingPath('member.last_login_at')->assertJsonMissingPath('member.last_seen_at');
    }

    private function organization(string $name): Organization
    {
        return Organization::query()->withoutGlobalScopes()->create(['name' => $name, 'slug' => Str::slug($name).'-'.Str::random(6), 'status' => 'active']);
    }

    private function membership(Organization $organization, User $user, string $role): string
    {
        return (string) OrganizationMembership::query()->withoutGlobalScopes()
            ->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active'])->id;
    }

    private function person(Organization $organization, User $user): StaffMember
    {
        app(TenantContext::class)->select($organization);
        $person = StaffMember::query()->create(['full_name' => $user->name, 'phone' => '+359888000111']);
        $person->forceFill(['user_id' => $user->id])->save();
        app(TenantContext::class)->clear();

        return $person;
    }

    /** The stored moment as "Y-m-d H:i:s" in UTC, or null. */
    private function moment(string $membership, string $column): ?string
    {
        $value = DB::table('organization_memberships')->where('id', $membership)->value($column);

        return $value === null ? null : CarbonImmutable::parse((string) $value)->utc()->format('Y-m-d H:i:s');
    }
}
