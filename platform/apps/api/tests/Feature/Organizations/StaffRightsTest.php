<?php

namespace Tests\Feature\Organizations;

use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Services\AccessScope;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Enums\StaffScope;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Models\StaffGrant;
use App\Domain\Organizations\Models\StaffMember;
use App\Domain\Organizations\Models\StaffScopeGroup;
use App\Domain\Organizations\Services\StaffRights;
use App\Domain\Organizations\ValueObjects\StaffAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Support\TeamRightsFixture;
use Tests\TestCase;

/**
 * What a person has, and where (docs/product/team-rights.md, т. 4.4): the role with the added rights and what they
 * bring along; an expired or revoked right, an archived person or a stopped membership give nothing; the scope "own",
 * "groups" or "all", the person's own groups always in; an added right in one's own groups not before the first day
 * there; a right for the whole organization only falls away with a narrower scope, even put straight into the database.
 */
class StaffRightsTest extends TestCase
{
    use RefreshDatabase;
    use TeamRightsFixture;

    protected function setUp(): void
    {
        parent::setUp();
        // Monday 2 November 2026, 12:00 in Sofia.
        $this->travelTo('2026-11-02 10:00:00');
    }

    public function test_the_role_and_the_added_rights_with_what_they_bring_along(): void
    {
        $f = $this->rightsFixture();
        $viewer = $this->memberOf($f['organization'], 'viewer');
        $person = $this->personFor($f['organization'], $viewer);
        $this->grant($f, $person, 'enrollment.enroll');
        $this->grant($f, $person, 'catalog.publish');

        $access = $this->rightsOf($f['organization'], $viewer);
        $this->assertSame(OrganizationRole::Viewer, $access->role);
        $this->assertSame(StaffScope::All, $access->scope);
        $this->assertTrue($access->fromRole('enrollment.view'));
        $this->assertTrue($access->grantedOnly('enrollment.enroll'));
        // The old key is the same right; publishing brings the settings along.
        $this->assertTrue($access->has('enrollment.manage'));
        $this->assertTrue($access->has('catalog.manage'));
        $this->assertFalse($access->has('contacts.view'));
        $this->assertNotNull($access->grantId('catalog.publish'));
        $this->assertNull($access->grantId('catalog.manage'));

        $this->inTenant($f['organization'], function () use ($f, $viewer): void {
            $gate = Gate::forUser($viewer);
            $this->assertTrue($gate->allows('enrollment.enroll', $f['organization']));
            $this->assertTrue($gate->allows('enrollment.manage', $f['organization']));
            $this->assertTrue($gate->allows('catalog.manage', $f['organization']));
            $this->assertFalse($gate->allows('enrollment.contacts', $f['organization']));
            $this->assertFalse($gate->allows('manageTeam', $f['organization']));
        });
    }

    public function test_the_settings_the_assignments_and_the_schedule_bring_the_catalog_along_for_the_accountant(): void
    {
        $f = $this->rightsFixture();
        $accountant = $this->memberOf($f['organization'], 'finance');
        $person = $this->personFor($f['organization'], $accountant);
        // By role the accountant still has no catalog, as before.
        $this->assertFalse($this->rightsOf($f['organization'], $accountant)->has('catalog.view'));
        $this->signInTo($accountant, $f['organization'])->getJson('/api/v1/catalog/activities')->assertForbidden();

        foreach (['catalog.manage', 'catalog.publish', 'team.assign', 'schedule.manage'] as $ability) {
            $grant = $this->grant($f, $person, $ability);
            $access = $this->rightsOf($f['organization'], $accountant);
            $this->assertTrue($access->has('catalog.view'), $ability);
            $this->assertFalse($access->fromRole('catalog.view'), $ability);
            $this->inTenant($f['organization'], fn () => $this->assertTrue(Gate::forUser($accountant)->allows('catalog.view', $f['organization']), $ability));
            $this->inTenant($f['organization'], fn () => $grant->forceFill([
                'revoked_at' => now(), 'revoked_by' => $f['owner']->id, 'revoked_reason' => 'removed',
            ])->save());
        }

        $this->grant($f, $person, 'catalog.manage');
        $this->signInTo($accountant, $f['organization'])->getJson('/api/v1/catalog/activities')->assertOk();
    }

    public function test_the_platform_operator_keeps_its_set_and_does_not_see_the_team(): void
    {
        $f = $this->rightsFixture();
        $operator = $this->memberOf($f['organization'], 'platform_admin');

        $access = $this->rightsOf($f['organization'], $operator);
        $this->assertNull($access->staffMemberId);
        $this->assertTrue($access->has('team.assign'));
        $this->assertTrue($access->has('enrollment.enroll'));
        $this->assertFalse($access->has('team.view'));
        $this->assertFalse($access->has('contacts.view'));
    }

    public function test_an_expired_or_revoked_right_gives_nothing(): void
    {
        $f = $this->rightsFixture();
        $viewer = $this->memberOf($f['organization'], 'viewer');
        $person = $this->personFor($f['organization'], $viewer);
        // "до 15 ноември" is kept as the start of 16 November in Sofia.
        $this->grant($f, $person, 'contacts.view', '2026-11-15 22:00:00');
        $revoked = $this->grant($f, $person, 'enrollment.enroll');
        $this->inTenant($f['organization'], fn () => $revoked->forceFill([
            'revoked_at' => now(), 'revoked_by' => $f['owner']->id, 'revoked_reason' => 'removed',
        ])->save());

        $this->assertTrue($this->rightsOf($f['organization'], $viewer)->has('contacts.view'));
        $this->assertFalse($this->rightsOf($f['organization'], $viewer)->has('enrollment.enroll'));

        $this->travelTo('2026-11-15 21:59:59');
        $this->assertTrue($this->rightsOf($f['organization'], $viewer)->has('contacts.view'));
        $this->travelTo('2026-11-15 22:00:00');
        $this->assertFalse($this->rightsOf($f['organization'], $viewer)->has('contacts.view'));
    }

    public function test_an_archived_person_and_a_stopped_membership_give_nothing_and_a_member_without_a_person_has_the_role(): void
    {
        $f = $this->rightsFixture();
        $viewer = $this->memberOf($f['organization'], 'viewer');
        $person = $this->personFor($f['organization'], $viewer);
        $this->grant($f, $person, 'enrollment.enroll');
        $this->inTenant($f['organization'], fn () => $person->forceFill(['archived_at' => now()])->save());
        // The archive stops the login too; until then an archived person has only the role.
        $this->assertFalse($this->rightsOf($f['organization'], $viewer)->has('enrollment.enroll'));
        $this->assertTrue($this->rightsOf($f['organization'], $viewer)->has('enrollment.view'));
        OrganizationMembership::query()->withoutGlobalScopes()->where('user_id', $viewer->id)->update(['status' => 'suspended']);
        $stopped = $this->rightsOf($f['organization'], $viewer);
        $this->assertNull($stopped->role);
        $this->assertFalse($stopped->has('view'));
        $this->inTenant($f['organization'], fn () => $this->assertFalse(Gate::forUser($viewer)->allows('view', $f['organization'])));

        // Old data: a member without a person of the team has the role and its scope.
        $coordinator = $this->memberOf($f['organization'], 'coordinator');
        $access = $this->rightsOf($f['organization'], $coordinator);
        $this->assertNull($access->staffMemberId);
        $this->assertSame(StaffScope::All, $access->scope);
        $this->assertTrue($access->allowsAcrossOrganization('enrollment.enroll'));
    }

    public function test_the_scope_own_holds_in_the_groups_the_person_teaches_now(): void
    {
        $f = $this->rightsFixture();
        [$teacher, $person, $profile] = $this->teacherOf($f);
        $this->grant($f, $person, 'contacts.view');
        $this->inTenant($f['organization'], fn () => $f['tuesday']->assignments()->attach($profile, ['role' => 'assistant', 'version' => 1]));

        $this->assertSame(StaffScope::Own, $this->rightsOf($f['organization'], $teacher)->scope);
        $this->inTenant($f['organization'], function () use ($f, $teacher): void {
            $scope = app(AccessScope::class);
            $this->assertTrue($scope->allows($teacher, 'contacts.view', $f['tuesday']->id));
            $this->assertFalse($scope->allows($teacher, 'contacts.view', $f['thursday']->id));
            $this->assertSame([$f['tuesday']->id], $scope->groups($teacher, 'contacts.view'));
            // Seeing the children of one's group comes from teaching it, never from the scope.
            $this->assertTrue($scope->allows($teacher, 'enrollment.view_own', $f['tuesday']->id));
            $this->assertFalse($scope->allows($teacher, 'enrollment.view_own', $f['thursday']->id));
            // For the whole organization a right of a group needs the scope "all".
            $this->assertFalse($scope->allowsEverywhere($teacher, 'contacts.view'));
            $this->assertFalse(Gate::forUser($teacher)->allows('contacts.view', $f['organization']));
            $this->assertTrue(Gate::forUser($teacher)->allows('enrollment.view_own', $f['organization']));
        });

        // With the scope "all" the added right holds in every group.
        $this->inTenant($f['organization'], fn () => $person->forceFill(['access_scope' => 'all'])->save());
        $this->inTenant($f['organization'], function () use ($f, $teacher): void {
            $scope = app(AccessScope::class);
            $this->assertTrue($scope->allows($teacher, 'contacts.view', $f['thursday']->id));
            $this->assertNull($scope->groups($teacher, 'contacts.view'));
            $this->assertTrue(Gate::forUser($teacher)->allows('contacts.view', $f['organization']));
            $this->assertFalse($scope->allows($teacher, 'enrollment.view_own', $f['thursday']->id));
        });
    }

    public function test_the_scope_groups_holds_in_the_selected_groups_the_groups_of_a_selected_activity_and_the_own_groups(): void
    {
        $f = $this->rightsFixture();
        $viewer = $this->memberOf($f['organization'], 'viewer');
        $person = $this->personFor($f['organization'], $viewer, teaches: true);
        $profile = $this->profileOf($f['organization'], $person);
        $this->inTenant($f['organization'], function () use ($f, $person, $profile): void {
            $person->forceFill(['access_scope' => 'groups'])->save();
            $this->select($f, $person, groupId: $f['thursday']->id);
            $f['studio']->assignments()->attach($profile, ['role' => 'lead', 'version' => 1]);
        });

        $access = $this->rightsOf($f['organization'], $viewer);
        $this->assertSame(StaffScope::Groups, $access->scope);
        $this->assertSame([$f['thursday']->id], $access->groupIds);
        $this->inTenant($f['organization'], function () use ($f, $viewer): void {
            $scope = app(AccessScope::class);
            $this->assertTrue($scope->allows($viewer, 'enrollment.view', $f['thursday']->id));
            // The groups the person teaches are always in.
            $this->assertTrue($scope->allows($viewer, 'enrollment.view', $f['studio']->id));
            $this->assertFalse($scope->allows($viewer, 'enrollment.view', $f['tuesday']->id));
            $this->assertEqualsCanonicalizing([$f['thursday']->id, $f['studio']->id], $scope->groups($viewer, 'enrollment.view') ?? []);
            $this->assertFalse(Gate::forUser($viewer)->allows('enrollment.view', $f['organization']));
            $this->assertFalse(Gate::forUser($viewer)->allows('viewTeam', $f['organization']));
            // A right for the organization holds with every scope.
            $this->assertTrue(Gate::forUser($viewer)->allows('catalog.view', $f['organization']));
        });

        // A whole activity covers its groups, also one created after the choice.
        $this->inTenant($f['organization'], fn () => $this->select($f, $person, activityId: $f['activity']->id));
        $later = $this->inTenant($f['organization'], fn (): Group => $f['chess']->groups()->create(['name' => 'Събота', 'status' => 'draft']));
        $this->inTenant($f['organization'], function () use ($f, $viewer, $later): void {
            $scope = app(AccessScope::class);
            $this->assertTrue($scope->allows($viewer, 'enrollment.view', $f['tuesday']->id));
            $this->assertTrue($scope->allows($viewer, 'enrollment.view', $later->id));
            $this->assertContains($later->id, $scope->groups($viewer, 'enrollment.view') ?? []);
        });
    }

    public function test_an_added_right_in_the_own_groups_holds_from_the_first_day_of_the_assignment_and_the_day_of_the_substitution(): void
    {
        $f = $this->rightsFixture();
        [$teacher, $person, $profile] = $this->teacherOf($f);
        $this->grant($f, $person, 'contacts.view');
        $this->inTenant($f['organization'], function () use ($f, $profile): void {
            $f['tuesday']->assignments()->attach($profile, ['role' => 'lead', 'starts_on' => '2026-11-10', 'version' => 1]);
            // Stands in on Thursday 5 November.
            $session = Session::query()->where('group_id', $f['thursday']->id)->whereDate('starts_at', '2026-11-05')->firstOrFail();
            $session->instructors()->attach($profile, ['role' => 'assistant']);
            $session->forceFill(['instructors_source' => 'manual'])->save();
        });

        $scope = fn (): AccessScope => app(AccessScope::class);
        $this->inTenant($f['organization'], function () use ($f, $teacher, $scope): void {
            // A group that starts later is the teacher's to see and mark, not yet for the added right.
            $this->assertTrue($scope()->allows($teacher, 'enrollment.view_own', $f['tuesday']->id));
            $this->assertTrue($scope()->allows($teacher, 'attendance.mark', $f['tuesday']->id));
            $this->assertFalse($scope()->allows($teacher, 'contacts.view', $f['tuesday']->id));
            $this->assertFalse($scope()->allows($teacher, 'contacts.view', $f['thursday']->id));
        });

        // Thursday 5 November, the day of the session, and to the end of the next day.
        $this->travelTo('2026-11-04 22:00:00');
        $this->inTenant($f['organization'], fn () => $this->assertTrue($scope()->allows($teacher, 'contacts.view', $f['thursday']->id)));
        $this->travelTo('2026-11-06 21:59:00');
        $this->inTenant($f['organization'], fn () => $this->assertTrue($scope()->allows($teacher, 'contacts.view', $f['thursday']->id)));
        $this->travelTo('2026-11-06 22:01:00');
        $this->inTenant($f['organization'], fn () => $this->assertFalse($scope()->allows($teacher, 'contacts.view', $f['thursday']->id)));

        // Tuesday 10 November: the assignment has begun.
        $this->travelTo('2026-11-09 22:00:00');
        $this->inTenant($f['organization'], fn () => $this->assertTrue($scope()->allows($teacher, 'contacts.view', $f['tuesday']->id)));
    }

    public function test_a_right_for_the_whole_organization_only_gives_nothing_with_a_narrower_scope(): void
    {
        $f = $this->rightsFixture();
        $viewer = $this->memberOf($f['organization'], 'viewer');
        $person = $this->personFor($f['organization'], $viewer);
        // Put straight into the database: the guards of the card would refuse it.
        $this->grant($f, $person, 'catalog.manage');
        $this->grant($f, $person, 'team.assign');
        $this->grant($f, $person, 'finance.view');
        $this->inTenant($f['organization'], function () use ($f, $person): void {
            $person->forceFill(['access_scope' => 'groups'])->save();
            $this->select($f, $person, groupId: $f['tuesday']->id);
        });

        $access = $this->rightsOf($f['organization'], $viewer);
        foreach (['catalog.manage', 'team.assign', 'finance.view'] as $ability) {
            $this->assertFalse($access->has($ability), $ability);
            $this->assertNull($access->grantId($ability), $ability);
        }
        $this->inTenant($f['organization'], function () use ($f, $viewer): void {
            $this->assertFalse(Gate::forUser($viewer)->allows('catalog.manage', $f['organization']));
            $this->assertFalse(Gate::forUser($viewer)->allows('team.assign', $f['organization']));
            $this->assertFalse(app(AccessScope::class)->allows($viewer, 'catalog.manage', $f['tuesday']->id));
        });
    }

    public function test_a_scope_the_role_does_not_allow_is_the_roles_default_and_another_organizations_rights_give_nothing(): void
    {
        $f = $this->rightsFixture();
        $coordinator = $this->memberOf($f['organization'], 'coordinator');
        $person = $this->personFor($f['organization'], $coordinator);
        $this->inTenant($f['organization'], fn () => $person->forceFill(['access_scope' => 'own'])->save());
        $this->assertSame(StaffScope::All, $this->rightsOf($f['organization'], $coordinator)->scope);

        // The same user with a right in another organization, where they are a viewer.
        $other = $this->rightsFixture();
        OrganizationMembership::query()->withoutGlobalScopes()->create([
            'organization_id' => $other['organization']->id, 'user_id' => $coordinator->id, 'role' => 'viewer', 'status' => 'active',
        ]);
        $there = $this->personFor($other['organization'], $coordinator);
        $this->grant($other, $there, 'contacts.view');
        $this->assertTrue($this->rightsOf($other['organization'], $coordinator)->has('contacts.view'));
        $this->assertNull($this->rightsOf($f['organization'], $coordinator)->grantId('contacts.view'));
        $this->inTenant($f['organization'], fn () => $this->assertSame(0, StaffGrant::query()->count()));
    }

    /** @return array{User, StaffMember, string} a teacher with a person of the team and a teacher profile */
    private function teacherOf(array $f): array
    {
        $teacher = $this->memberOf($f['organization'], 'instructor');
        $person = $this->personFor($f['organization'], $teacher, teaches: true);

        return [$teacher, $person, (string) $this->profileOf($f['organization'], $person)->id];
    }

    /** @param array{organization: Organization, owner: User} $f */
    private function grant(array $f, StaffMember $person, string $ability, ?string $expiresAt = null): StaffGrant
    {
        return $this->inTenant($f['organization'], fn (): StaffGrant => StaffGrant::query()->create([
            'staff_member_id' => $person->id, 'ability' => $ability, 'granted_by' => $f['owner']->id,
            'granted_at' => now()->subDay(), 'expires_at' => $expiresAt,
        ]));
    }

    /** Inside the organization: a selected group or activity of the person's scope. */
    private function select(array $f, StaffMember $person, ?string $groupId = null, ?string $activityId = null): StaffScopeGroup
    {
        return StaffScopeGroup::query()->create([
            'staff_member_id' => $person->id, 'group_id' => $groupId, 'activity_id' => $activityId,
            'added_by' => $f['owner']->id, 'added_at' => now(),
        ]);
    }

    private function rightsOf(Organization $organization, User $user): StaffAccess
    {
        return $this->inTenant($organization, fn (): StaffAccess => app(StaffRights::class)->for($user));
    }
}
