<?php

namespace Tests\Feature\Identity;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Models\StaffGrant;
use App\Domain\Organizations\Models\StaffMember;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Tests\TestCase;

/**
 * "Моят профил" (DECISIONS 31.10.2026 „Моят профил и заместник“): a person of a team changes their own name and phone,
 * and their record of the team in the active organization follows — never another organization's.
 */
class OwnStaffContactTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    public function test_the_teacher_changes_the_name_and_phone_and_the_team_of_the_active_organization_sees_them(): void
    {
        $teacher = User::factory()->create(['name' => 'Иван Петров', 'phone' => '+359888000111']);
        $school = $this->organization('Школа');
        $club = $this->organization('Клуб');
        $here = $this->person($school, $teacher, 'Иван Петров', '+359888000111');
        $there = $this->person($club, $teacher, 'Иван П.', '+359888000999');

        $this->actingAs($teacher)->withHeader('X-Organization-Id', $school->id)
            ->patchJson('/api/v1/me/contact', ['name' => 'Иван Петров-Колев', 'phone' => '0887 123 456'])
            ->assertOk()->assertJsonPath('user.name', 'Иван Петров-Колев')->assertJsonPath('user.phone', '+359887123456');

        $this->assertSame(['full_name' => 'Иван Петров-Колев', 'phone' => '+359887123456', 'version' => 2], $this->row($here));
        // The other organization keeps its own record.
        $this->assertSame(['full_name' => 'Иван П.', 'phone' => '+359888000999', 'version' => 1], $this->row($there));

        // The audit of the team's record: this organization, the names of the fields only.
        $event = AuditEvent::query()->withoutGlobalScopes()->where('action', 'team.member_updated')->sole();
        $this->assertSame($school->id, $event->organization_id);
        $this->assertSame($here->id, $event->subject_id);
        $this->assertSame(['fields' => ['full_name', 'phone']], $event->metadata);
        $this->assertStringNotContainsString('887123456', (string) json_encode($event->getAttributes()));
        $this->assertStringNotContainsString('Колев', (string) json_encode($event->getAttributes()));
    }

    #[TestGroup('tenant-isolation')]
    public function test_another_organization_in_the_header_is_not_found_and_nothing_changes(): void
    {
        $teacher = User::factory()->create(['name' => 'Иван Петров', 'phone' => '+359888000111']);
        $school = $this->organization('Школа');
        $foreign = $this->organization('Чужда');
        $here = $this->person($school, $teacher, 'Иван Петров', '+359888000111');
        $stranger = $this->person($foreign, null, 'Друг човек', '+359888000222');

        $this->actingAs($teacher)->withHeader('X-Organization-Id', $foreign->id)
            ->patchJson('/api/v1/me/contact', ['phone' => '0887 123 456'])
            ->assertNotFound();
        $this->actingAs($teacher)->withHeader('X-Organization-Id', 'not-a-uuid')
            ->patchJson('/api/v1/me/contact', ['phone' => '0887 123 456'])
            ->assertNotFound();

        $this->assertSame('+359888000111', $teacher->fresh()?->phone);
        $this->assertSame(['full_name' => 'Иван Петров', 'phone' => '+359888000111', 'version' => 1], $this->row($here));
        $this->assertSame(['full_name' => 'Друг човек', 'phone' => '+359888000222', 'version' => 1], $this->row($stranger));
        $this->assertSame(0, AuditEvent::query()->withoutGlobalScopes()->count());
    }

    public function test_a_parent_without_a_team_an_archived_record_and_an_emptied_phone_leave_the_team_as_it_is(): void
    {
        // A parent: no organization at all — only the profile changes.
        $parent = User::factory()->create(['phone' => null]);
        $this->actingAs($parent)->patchJson('/api/v1/me/contact', ['phone' => '0881 234 567'])->assertOk();
        $this->assertSame('+359881234567', $parent->fresh()?->phone);

        $teacher = User::factory()->create(['name' => 'Иван Петров', 'phone' => '+359888000111']);
        $school = $this->organization('Школа');
        $here = $this->person($school, $teacher, 'Иван Петров', '+359888000111');

        // An emptied phone clears the profile's, but the team keeps a phone to call.
        $this->actingAs($teacher)->withHeader('X-Organization-Id', $school->id)
            ->patchJson('/api/v1/me/contact', ['phone' => ''])->assertOk()->assertJsonPath('user.phone', null);
        $this->assertSame(['full_name' => 'Иван Петров', 'phone' => '+359888000111', 'version' => 1], $this->row($here));

        // The organization selected in the session counts as the active one when no header is sent.
        $this->flushHeaders();
        $this->actingAs($teacher)->withSession(['active_organization_id' => $school->id])
            ->patchJson('/api/v1/me/contact', ['name' => 'Иван Колев'])->assertOk();
        $this->assertSame('Иван Колев', $this->row($here)['full_name']);

        // An archived record is history: it is not changed.
        DB::table('staff_members')->where('id', $here->id)->update(['archived_at' => now()]);
        $this->actingAs($teacher)->withHeader('X-Organization-Id', $school->id)
            ->patchJson('/api/v1/me/contact', ['name' => 'Иван Друг'])->assertOk();
        $this->assertSame('Иван Колев', $this->row($here)['full_name']);
    }

    public function test_the_context_says_the_users_own_added_rights_with_their_last_day(): void
    {
        $teacher = User::factory()->create(['name' => 'Иван Петров']);
        $school = $this->organization('Школа');
        $here = $this->person($school, $teacher, 'Иван Петров', '+359888000111');
        $colleague = User::factory()->create(['name' => 'Мария Колева']);
        $other = $this->person($school, $colleague, 'Мария Колева', '+359888000333', 'viewer');
        app(TenantContext::class)->select($school);
        StaffGrant::query()->create(['staff_member_id' => $here->id, 'ability' => 'contacts.view', 'granted_by' => $teacher->id, 'granted_at' => now(), 'expires_at' => '2027-06-30 21:00:00']);
        StaffGrant::query()->create(['staff_member_id' => $other->id, 'ability' => 'enrollment.enroll', 'granted_by' => $teacher->id, 'granted_at' => now()]);
        app(TenantContext::class)->clear();

        $this->actingAs($teacher)->withHeader('X-Organization-Id', $school->id)
            ->getJson('/api/v1/context/organization')->assertOk()
            ->assertJsonPath('access.role', 'instructor')
            ->assertJsonPath('access.grants', [['ability' => 'contacts.view', 'expires_on' => '2027-06-30']]);
    }

    private function organization(string $name): Organization
    {
        return Organization::query()->withoutGlobalScopes()->create(['name' => $name, 'slug' => Str::slug($name).'-'.Str::random(6), 'status' => 'active']);
    }

    /** A person of the team; with a user, an active membership with the role too. */
    private function person(Organization $organization, ?User $user, string $name, string $phone, string $role = 'instructor'): StaffMember
    {
        if ($user !== null) {
            OrganizationMembership::query()->withoutGlobalScopes()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active']);
        }
        app(TenantContext::class)->select($organization);
        $person = StaffMember::query()->create(['full_name' => $name, 'phone' => $phone, 'version' => 1]);
        if ($user !== null) {
            $person->forceFill(['user_id' => $user->id])->save();
        }
        app(TenantContext::class)->clear();

        return $person;
    }

    /** @return array{full_name: string, phone: string|null, version: int} */
    private function row(StaffMember $person): array
    {
        $row = DB::table('staff_members')->where('id', $person->id)->first(['full_name', 'phone', 'version']);
        $this->assertNotNull($row);

        return ['full_name' => (string) $row->full_name, 'phone' => $row->phone, 'version' => (int) $row->version];
    }
}
