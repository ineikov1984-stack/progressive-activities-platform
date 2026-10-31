<?php

namespace Tests\Feature\Organizations;

use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Models\StaffMember;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Tests\TestCase;

/** The people of a team, with or without a login (docs/product/team.md, т. 3, 4, 6, 9 — PR 2а). */
class TeamMembersTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_adds_a_teacher_without_a_login_and_the_team_sees_them_without_contacts(): void
    {
        [$owner, $organization] = $this->team();
        $this->withHeader('X-Organization-Id', $organization->id);

        $added = $this->actingAs($owner)->withHeader('Idempotency-Key', 'add-1')->postJson('/api/v1/team/members', [
            'full_name' => 'Петър Стоянов', 'phone' => '0888 123 456', 'email' => 'Petar@Example.test', 'teaches' => true,
        ])->assertCreated()
            ->assertJsonPath('member.full_name', 'Петър Стоянов')
            ->assertJsonPath('member.state', 'profile_only')
            ->assertJsonPath('member.phone', '+359888123456')
            ->assertJsonPath('member.email', 'petar@example.test')
            ->json('member');
        $this->assertNotNull($added['instructor_profile_id']);
        $this->assertDatabaseHas('instructor_profiles', ['id' => $added['instructor_profile_id'], 'staff_member_id' => $added['id'], 'public_name' => 'Петър Стоянов', 'is_public' => false]);
        // The same key does not add the person twice.
        $this->actingAs($owner)->withHeader('Idempotency-Key', 'add-1')->postJson('/api/v1/team/members', [
            'full_name' => 'Петър Стоянов', 'phone' => '0888 123 456', 'teaches' => true,
        ])->assertOk()->assertJsonPath('member.id', $added['id']);
        $this->assertSame(1, DB::table('staff_members')->where('full_name', 'Петър Стоянов')->count());

        $this->actingAs($owner)->getJson('/api/v1/team')->assertOk()
            ->assertJsonPath('can_manage', true)
            ->assertJsonFragment(['full_name' => 'Петър Стоянов', 'phone' => '+359888123456']);

        // A coordinator sees the team, but not the phones and e-mails, and does not add people.
        $coordinator = $this->member($organization, 'coordinator', 'Координатор');
        $list = $this->actingAs($coordinator)->getJson('/api/v1/team')->assertOk()->assertJsonPath('can_manage', false);
        $this->assertStringNotContainsString('+359888123456', $list->getContent());
        $this->assertStringNotContainsString('petar@example.test', $list->getContent());
        $this->actingAs($coordinator)->getJson('/api/v1/team/members/'.$added['id'])->assertOk()
            ->assertJsonMissingPath('member.phone')->assertJsonMissingPath('history');
        $this->actingAs($coordinator)->postJson('/api/v1/team/members', ['full_name' => 'Х', 'phone' => '0888000000'])->assertForbidden();

        // A teacher has no team page.
        $teacher = $this->member($organization, 'instructor', 'Учител');
        $this->actingAs($teacher)->getJson('/api/v1/team')->assertForbidden();

        // The audit keeps which fields, never the phone or the e-mail.
        $audit = DB::table('audit_events')->where('action', 'team.member_added')->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('888123456', (string) $audit->metadata);
        $this->assertStringNotContainsString('example.test', (string) $audit->metadata);
    }

    public function test_details_are_changed_with_a_version_and_a_phone_is_required(): void
    {
        [$owner, $organization] = $this->team();
        $this->withHeader('X-Organization-Id', $organization->id);
        $this->actingAs($owner)->postJson('/api/v1/team/members', ['full_name' => 'Без телефон'])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->actingAs($owner)->postJson('/api/v1/team/members', ['full_name' => 'Грешен', 'phone' => '12'])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        $member = $this->actingAs($owner)->postJson('/api/v1/team/members', ['full_name' => 'Мария', 'phone' => '0888111222', 'email' => 'maria@example.test'])->assertCreated()->json('member');

        $this->actingAs($owner)->patchJson('/api/v1/team/members/'.$member['id'], ['version' => 9, 'full_name' => 'Мария Колева'])->assertConflict();
        $this->actingAs($owner)->patchJson('/api/v1/team/members/'.$member['id'], ['version' => 1, 'full_name' => 'Мария Колева', 'email' => null])
            ->assertOk()->assertJsonPath('member.full_name', 'Мария Колева')->assertJsonPath('member.version', 2);
        $this->assertSame(['full_name', 'email'], json_decode((string) DB::table('audit_events')->where('action', 'team.member_updated')->value('metadata'), true)['fields']);
    }

    public function test_a_person_is_archived_only_without_open_assignments_and_their_login_stops(): void
    {
        $this->travelTo('2026-11-01 10:00:00');
        [$owner, $organization] = $this->team();
        $this->withHeader('X-Organization-Id', $organization->id);
        $teacher = $this->member($organization, 'instructor', 'Иван Петров');
        $person = $this->person($organization, $teacher);
        $profile = $this->actingAs($owner)->postJson('/api/v1/team/members/'.$person->id.'/instructor-profile')
            ->assertOk()->json('member.instructor_profile_id');
        $group = $this->groupWith($organization, $profile);

        $this->actingAs($owner)->postJson('/api/v1/team/members/'.$person->id.'/archive', ['version' => 1])
            ->assertUnprocessable()->assertJsonPath('errors.member.0', 'Първо приключете назначението в „Шахмат · Вторник“.');
        // The profile gives each assignment its version, which ending it asks for.
        $this->actingAs($owner)->getJson('/api/v1/team/members/'.$person->id)->assertOk()
            ->assertJsonPath('member.groups.0.group_id', $group)->assertJsonPath('member.groups.0.version', 1)
            // The activity and the group apart, for the list sorted by activity; the label stays.
            ->assertJsonPath('member.groups.0.activity', 'Шахмат')->assertJsonPath('member.groups.0.group', 'Вторник')
            ->assertJsonPath('member.groups.0.label', 'Шахмат · Вторник');
        $this->actingAs($owner)->getJson('/api/v1/team')->assertOk()
            ->assertJsonFragment(['group_id' => $group, 'activity' => 'Шахмат', 'group' => 'Вторник']);
        $this->actingAs($owner)->postJson('/api/v1/catalog/groups/'.$group.'/assignments/'.$profile.'/end', ['ends_on' => '2026-10-31', 'version' => 1])->assertOk();
        $this->actingAs($owner)->getJson('/api/v1/team/members/'.$person->id)->assertOk()
            ->assertJsonPath('member.groups.0.ends_on', '2026-10-31')->assertJsonPath('member.groups.0.version', 2);
        $this->actingAs($owner)->postJson('/api/v1/team/members/'.$person->id.'/archive', ['version' => 1])
            ->assertOk()->assertJsonPath('member.state', 'archived');
        $this->assertDatabaseHas('organization_memberships', ['user_id' => $teacher->id, 'status' => 'suspended']);
        $this->actingAs($owner)->getJson('/api/v1/team')->assertOk()->assertJsonMissing(['full_name' => 'Иван Петров']);
        $this->actingAs($owner)->getJson('/api/v1/team?archived=1')->assertOk()->assertJsonFragment(['full_name' => 'Иван Петров']);
        $this->actingAs($owner)->postJson('/api/v1/team/members/'.$person->id.'/instructor-profile')->assertUnprocessable();

        // Nobody archives themselves, and the last owner stays.
        $self = StaffMember::query()->withoutGlobalScopes()->where('user_id', $owner->id)->firstOrFail();
        $this->actingAs($owner)->postJson('/api/v1/team/members/'.$self->id.'/archive', ['version' => 1])
            ->assertUnprocessable()->assertJsonPath('errors.member.0', 'Не можете да архивирате себе си.');
        // An administrator archives neither the owner nor another administrator.
        $admin = $this->member($organization, 'organization_admin', 'Администратор');
        $this->actingAs($admin)->postJson('/api/v1/team/members/'.$self->id.'/archive', ['version' => 1])->assertForbidden();
        $otherAdmin = $this->person($organization, $this->member($organization, 'organization_admin', 'Втори администратор'));
        $this->actingAs($admin)->postJson('/api/v1/team/members/'.$otherAdmin->id.'/archive', ['version' => 1])->assertForbidden();
        $this->actingAs($owner)->postJson('/api/v1/team/members/'.$otherAdmin->id.'/archive', ['version' => 1])->assertOk();
    }

    public function test_the_lead_of_an_offering_and_the_teacher_of_a_coming_session_are_not_archived(): void
    {
        $this->travelTo('2026-11-01 10:00:00');
        [$owner, $organization] = $this->team();
        $this->withHeader('X-Organization-Id', $organization->id);
        $person = $this->person($organization, null, 'Анна');
        $profile = $this->actingAs($owner)->postJson('/api/v1/team/members/'.$person->id.'/instructor-profile')->assertOk()->json('member.instructor_profile_id');

        app(TenantContext::class)->select($organization);
        $activity = Activity::query()->create(['title' => 'Рисуване', 'slug' => 'risuvane', 'category' => 'Изкуство', 'summary' => 'Рисуване', 'age_label' => '6+']);
        $offering = $activity->offerings()->create(['title' => 'Следобед', 'lead_instructor_profile_id' => $profile]);
        $group = $offering->groups()->create(['name' => 'Петък']);
        $session = $group->sessions()->create(['starts_at' => '2026-11-06 15:00:00', 'ends_at' => '2026-11-06 16:00:00', 'status' => 'scheduled']);
        $session->instructors()->attach($profile, ['role' => 'lead']);
        app(TenantContext::class)->clear();

        $this->actingAs($owner)->postJson('/api/v1/team/members/'.$person->id.'/archive', ['version' => 1])
            ->assertUnprocessable()->assertJsonPath('errors.member.0', 'Първо сменете водещия на „Рисуване · Следобед“.');
        app(TenantContext::class)->select($organization);
        $offering->forceFill(['lead_instructor_profile_id' => null])->save();
        app(TenantContext::class)->clear();
        $this->actingAs($owner)->postJson('/api/v1/team/members/'.$person->id.'/archive', ['version' => 1])
            ->assertUnprocessable()->assertJsonPath('errors.member.0', 'Първо сменете преподавателя на заниманието на 06.11.2026 г. в „Рисуване · Петък“.');
    }

    public function test_an_existing_teacher_profile_is_linked_once(): void
    {
        [$owner, $organization] = $this->team();
        $this->withHeader('X-Organization-Id', $organization->id);
        app(TenantContext::class)->select($organization);
        $free = InstructorProfile::query()->create(['public_name' => 'Анна И.']);
        app(TenantContext::class)->clear();
        $first = $this->person($organization, null, 'Анна Иванова');
        $second = $this->person($organization, null, 'Борис');

        $this->actingAs($owner)->postJson('/api/v1/team/members/'.$first->id.'/instructor-profile', ['instructor_profile_id' => $free->id])
            ->assertOk()->assertJsonPath('member.instructor_profile_id', $free->id);
        $this->actingAs($owner)->postJson('/api/v1/team/members/'.$second->id.'/instructor-profile', ['instructor_profile_id' => $free->id])
            ->assertUnprocessable()->assertJsonPath('errors.instructor_profile_id.0', 'Профилът е свързан с друг човек.');
        $this->assertSame(1, DB::table('audit_events')->where('action', 'team.profile_linked')->count());
    }

    #[TestGroup('tenant-isolation')]
    public function test_another_organizations_people_and_profiles_are_not_found(): void
    {
        [$owner, $organization] = $this->team();
        [, $other] = $this->team('Друго училище');
        $foreign = $this->person($other, null, 'Чужд човек');
        app(TenantContext::class)->select($other);
        $foreignProfile = InstructorProfile::query()->create(['public_name' => 'Чужд профил']);
        $mine = $this->person($organization, null, 'Мой човек');
        app(TenantContext::class)->clear();
        $this->withHeader('X-Organization-Id', $organization->id);

        $this->actingAs($owner)->getJson('/api/v1/team')->assertOk()->assertJsonMissing(['full_name' => 'Чужд човек']);
        $this->actingAs($owner)->getJson('/api/v1/team/members/'.$foreign->id)->assertNotFound();
        $this->actingAs($owner)->patchJson('/api/v1/team/members/'.$foreign->id, ['version' => 1, 'full_name' => 'Х'])->assertNotFound();
        $this->actingAs($owner)->postJson('/api/v1/team/members/'.$foreign->id.'/archive', ['version' => 1])->assertNotFound();
        $this->actingAs($owner)->postJson('/api/v1/team/members/'.$mine->id.'/instructor-profile', ['instructor_profile_id' => $foreignProfile->id])
            ->assertUnprocessable()->assertJsonPath('errors.instructor_profile_id.0', 'Профилът не е намерен.');
        $this->assertSame('Чужд човек', DB::table('staff_members')->where('id', $foreign->id)->value('full_name'));
    }

    /** @return array{User, Organization} an owner with a person of the team, as the backfill and accepting an invitation give */
    private function team(string $name = 'Школа'): array
    {
        $owner = User::factory()->create(['name' => 'Собственик '.$name]);
        $organization = Organization::query()->withoutGlobalScopes()->create(['name' => $name, 'slug' => Str::slug($name).'-'.Str::random(6), 'status' => 'active']);
        OrganizationMembership::query()->withoutGlobalScopes()->create(['organization_id' => $organization->id, 'user_id' => $owner->id, 'role' => 'organization_owner', 'status' => 'active']);
        $this->person($organization, $owner);

        return [$owner, $organization];
    }

    private function member(Organization $organization, string $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        OrganizationMembership::query()->withoutGlobalScopes()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active']);

        return $user;
    }

    private function person(Organization $organization, ?User $user, ?string $name = null): StaffMember
    {
        app(TenantContext::class)->select($organization);
        $person = StaffMember::query()->create(['full_name' => $name ?? $user?->name ?? 'Човек']);
        if ($user !== null) {
            $person->forceFill(['user_id' => $user->id])->save();
        }
        app(TenantContext::class)->clear();

        return $person;
    }

    /** A group of "Шахмат" named "Вторник" with the teacher as lead; returns the group's id. */
    private function groupWith(Organization $organization, string $profile): string
    {
        app(TenantContext::class)->select($organization);
        $activity = Activity::query()->create(['title' => 'Шахмат', 'slug' => 'shah-'.Str::random(6), 'category' => 'Игри', 'summary' => 'Шах', 'age_label' => '7+']);
        $group = $activity->offerings()->create([])->groups()->create(['name' => 'Вторник']);
        $group->assignments()->attach($profile, ['role' => 'lead', 'version' => 1]);
        app(TenantContext::class)->clear();

        return $group->id;
    }
}
