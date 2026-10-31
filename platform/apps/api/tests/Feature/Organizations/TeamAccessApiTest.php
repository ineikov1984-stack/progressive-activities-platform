<?php

namespace Tests\Feature\Organizations;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Invitation;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Models\StaffGrant;
use App\Domain\Organizations\Models\StaffMember;
use App\Domain\Organizations\Models\StaffScopeGroup;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Tests\Support\TeamRightsFixture;
use Tests\TestCase;

/**
 * The card "Права" through the API (docs/product/team-rights.md, т. 5, 7, 9): read by those who see the person, saved
 * whole by the owner and the administrator under the person's version, with every guard of т. 5; the template
 * "Партньор от училище"; rights given together with an invitation; another organization's person is not found.
 */
class TeamAccessApiTest extends TestCase
{
    use RefreshDatabase;
    use TeamRightsFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        // Monday 2 November 2026, 12:00 in Sofia.
        $this->travelTo('2026-11-02 10:00:00');
    }

    public function test_the_owner_makes_a_school_partner_and_the_card_tells_it(): void
    {
        $f = $this->rightsFixture();
        [$partner, $person] = $this->viewer($f);
        $path = "/api/v1/team/members/{$person->id}/access";
        $this->signInTo($f['owner'], $f['organization']);

        $card = $this->getJson($path)->assertOk()
            ->assertJsonPath('access.state', 'access')->assertJsonPath('access.role', 'viewer')->assertJsonPath('access.scope', 'all')
            ->assertJsonPath('access.allowed_scopes', ['all', 'groups'])->assertJsonPath('access.editable', true)
            ->assertJsonPath('access.reason', null)->assertJsonPath('access.version', 1)->json('access');
        $this->assertContains('enrollment.enroll', $card['grantable']);
        $this->assertNotContains('team.view', $card['grantable'], 'The role gives it.');
        $this->assertNotContains('finance.view', $card['grantable'], 'Finance is not on yet.');
        $this->assertContains(['key' => 'enrollment.view', 'area' => 'enrollment', 'source' => 'role', 'grant_id' => null, 'expires_on' => null, 'implied' => false], $card['rights']);

        $saved = $this->putJson($path, [
            'scope' => 'groups', 'groups' => [['group_id' => $f['tuesday']->id], ['activity_id' => $f['painting']->id]],
            'grants' => [['ability' => 'enrollment.enroll', 'expires_on' => '2027-06-30'], ['ability' => 'attendance.mark']],
            'template' => 'school_partner', 'version' => 1,
        ])->assertOk()
            ->assertJsonPath('access.scope', 'groups')->assertJsonPath('access.template', 'school_partner')->assertJsonPath('access.version', 2)
            ->assertJsonPath('access.groups', [['group_id' => $f['tuesday']->id, 'label' => 'Шах · Вторник']])
            ->assertJsonPath('access.activities', [['activity_id' => $f['painting']->id, 'title' => 'Рисуване']])
            ->json('access');
        $enroll = collect($saved['rights'])->firstWhere('key', 'enrollment.enroll');
        $this->assertSame(['source' => 'grant', 'expires_on' => '2027-06-30', 'implied' => false], array_intersect_key($enroll, array_flip(['source', 'expires_on', 'implied'])));
        foreach (['team.access_scope_changed', 'team.access_template_changed', 'team.access_grant_added', 'team.access_group_added'] as $action) {
            $this->assertContains($action, array_column($saved['history'], 'action'));
        }
        $this->assertContains('Шах · Вторник', array_column($saved['history'], 'group_label'));

        // The template is applied whole: without one of its rights it is refused.
        $this->putJson($path, ['scope' => 'groups', 'groups' => [['group_id' => $f['tuesday']->id]], 'grants' => [['ability' => 'attendance.mark']], 'template' => 'school_partner', 'version' => 2])
            ->assertUnprocessable()->assertJsonPath('errors.template.0', 'Партньорът от училище е „Само преглед“ с избрани групи, записване и присъствия.');
        // A card opened before the change: 409.
        $this->putJson($path, ['scope' => null, 'grants' => [], 'version' => 1])
            ->assertConflict()->assertJsonPath('message', 'Правата са променени междувременно. Заредете страницата отново.');
        // A group of another organization is "not found", without saying why.
        $other = $this->rightsFixture();
        $this->signInTo($f['owner'], $f['organization'])->putJson($path, ['scope' => 'groups', 'groups' => [['group_id' => $other['tuesday']->id]], 'grants' => [], 'version' => 2])
            ->assertUnprocessable()->assertJsonPath('errors', ['groups.0.group_id' => ['Групата не е намерена.']]);

        // The partner's own access: only "Вторник" and the painting activity.
        $this->signInTo($partner, $f['organization'])->getJson('/api/v1/context/organization')->assertOk()
            ->assertJsonPath('access.role', 'viewer')->assertJsonPath('access.scope', 'groups')
            ->assertJsonPath('access.groups', [$f['tuesday']->id])->assertJsonPath('access.activities', [$f['painting']->id]);
        $this->assertContains('enrollment.enroll', $this->getJson('/api/v1/context/organization')->json('access.abilities'));
    }

    public function test_every_guard_of_the_card(): void
    {
        $f = $this->rightsFixture();
        [, $person] = $this->viewer($f);
        $admin = $this->memberOf($f['organization'], 'organization_admin');
        $adminPerson = $this->personFor($f['organization'], $admin);
        $owner = $this->inTenant($f['organization'], fn (): StaffMember => StaffMember::query()->where('user_id', $f['owner']->id)->firstOrFail());
        $card = ['scope' => null, 'grants' => [['ability' => 'contacts.view']], 'version' => 1];
        $put = fn (User $actor, StaffMember $member, array $body) => $this->signInTo($actor, $f['organization'])->putJson("/api/v1/team/members/{$member->id}/access", $body);

        $put($admin, $adminPerson, $card)->assertUnprocessable()->assertJsonPath('errors.member.0', 'Не можете да променяте собствените си права.');
        $put($admin, $owner, $card)->assertUnprocessable()->assertJsonPath('errors.member.0', 'Собственикът има всички права.');
        // The administrator sees the whole schedule through the scope; added, it changes nothing — but it may be given.
        $put($f['owner'], $adminPerson, [...$card, 'grants' => [['ability' => 'schedule.view_all']]])->assertOk();
        $otherAdmin = $this->memberOf($f['organization'], 'organization_admin');
        $put($otherAdmin, $adminPerson, [...$card, 'version' => 2])->assertForbidden()->assertJsonPath('message', 'Нямате право да променяте правата на този човек.');
        $put($admin, $f['person'], $card)->assertUnprocessable()->assertJsonPath('errors.member.0', 'Първо изпратете покана — правата важат за човек с достъп.');
        $put($admin, $person, [...$card, 'grants' => [['ability' => 'finance.view']]])->assertForbidden()->assertJsonPath('message', 'Финансите дава само собственикът.');
        $put($f['owner'], $person, [...$card, 'grants' => [['ability' => 'finance.view']], 'confirm_finance' => true])
            ->assertUnprocessable()->assertJsonPath('errors.grants.0', 'Финансите още не са включени.');
        $put($admin, $person, [...$card, 'grants' => [['ability' => 'team.manage']]])->assertUnprocessable()->assertJsonPath('errors', ['grants.0.ability' => ['Това право идва само с ролята.']]);
        $put($admin, $person, [...$card, 'grants' => [['ability' => 'team.view']]])->assertUnprocessable()->assertJsonPath('errors', ['grants.0.ability' => ['Ролята вече дава това право.']]);
        $put($admin, $person, [...$card, 'scope' => 'groups', 'groups' => [['group_id' => $f['tuesday']->id]], 'grants' => [['ability' => 'catalog.manage']]])
            ->assertUnprocessable()->assertJsonPath('errors', ['grants.0.ability' => ['Финансите и настройките се дават само за цялата организация.']]);
        $put($admin, $person, [...$card, 'scope' => 'groups', 'groups' => []])->assertUnprocessable()->assertJsonPath('errors.groups.0', 'Изберете поне една група.');
        $put($admin, $person, [...$card, 'grants' => [['ability' => 'contacts.view', 'expires_on' => '2026-11-02']]])
            ->assertUnprocessable()->assertJsonPath('errors', ['grants.0.expires_on' => ['Крайната дата трябва да е след днес.']]);
        $coordinator = $this->memberOf($f['organization'], 'coordinator');
        $put($coordinator, $person, $card)->assertForbidden();
        $this->signInTo($coordinator, $f['organization'])->getJson("/api/v1/team/members/{$person->id}/access")->assertOk()
            ->assertJsonPath('access.editable', false)->assertJsonPath('access.reason', 'Правата дават собственикът и администраторът.')
            ->assertJsonMissingPath('access.grantable')->assertJsonMissingPath('access.history');
        $put($admin, $person, $card)->assertOk()->assertJsonPath('access.version', 2);

        // A person without a login and without an invitation: no rights yet.
        $this->signInTo($admin, $f['organization'])->getJson("/api/v1/team/members/{$f['person']->id}/access")->assertOk()
            ->assertJsonPath('access.state', 'no_access')->assertJsonPath('access.role', null)->assertJsonPath('access.rights', [])
            ->assertJsonPath('access.editable', false)->assertJsonPath('access.reason', 'Първо изпратете покана — правата важат за човек с достъп.');
        // The archive.
        $this->inTenant($f['organization'], fn () => $person->forceFill(['archived_at' => now()])->save());
        $put($f['owner'], $person, [...$card, 'version' => 2])->assertUnprocessable()->assertJsonPath('errors.member.0', 'Човекът е в архива.');
        $this->assertSame(0, AuditEvent::query()->where('action', 'team.access_grant_added')->where('subject_id', $f['person']->id)->count());
    }

    #[TestGroup('tenant-isolation')]
    public function test_another_organizations_person_is_not_found(): void
    {
        $f = $this->rightsFixture();
        $other = $this->rightsFixture();
        [, $foreign] = $this->viewer($other);

        $this->signInTo($f['owner'], $f['organization']);
        $this->getJson("/api/v1/team/members/{$foreign->id}/access")->assertNotFound();
        $this->putJson("/api/v1/team/members/{$foreign->id}/access", ['scope' => null, 'grants' => [['ability' => 'contacts.view']], 'version' => 1])->assertNotFound();
        $this->inTenant($other['organization'], fn () => $this->assertSame(0, StaffGrant::query()->count()));
    }

    public function test_a_new_role_shows_what_it_would_take_away_and_takes_it_when_saved(): void
    {
        $f = $this->rightsFixture();
        [$viewer, $person] = $this->viewer($f);
        $path = "/api/v1/team/members/{$person->id}";
        $this->signInTo($f['owner'], $f['organization']);
        $this->putJson("{$path}/access", [
            'scope' => 'groups', 'groups' => [['group_id' => $f['tuesday']->id]],
            'grants' => [['ability' => 'enrollment.enroll'], ['ability' => 'attendance.mark']], 'template' => 'school_partner', 'version' => 1,
        ])->assertOk();

        $preview = $this->getJson("{$path}/access?role=coordinator")->assertOk();
        $this->assertEqualsCanonicalizing([['ability' => 'enrollment.enroll', 'reason' => 'role_changed'], ['ability' => 'attendance.mark', 'reason' => 'role_changed']],
            $preview->json('access.changes.revoke'));
        $preview->assertJsonPath('access.changes.clear_scope', true)->assertJsonPath('access.changes.remove_groups', 1)
            ->assertJsonPath('access.changes.clear_template', true)->assertJsonPath('access.changes.loses_finance', false);
        $this->inTenant($f['organization'], fn () => $this->assertSame(2, StaffGrant::query()->whereNull('revoked_at')->count()));

        // The change of role brings the rights in line in the same transaction.
        $this->postJson("{$path}/role", ['role' => 'coordinator', 'version' => 2])->assertOk();
        $this->inTenant($f['organization'], function () use ($person): void {
            $this->assertSame(0, StaffGrant::query()->whereNull('revoked_at')->count());
            $this->assertSame(0, StaffScopeGroup::query()->whereNull('removed_at')->count());
            $fresh = StaffMember::query()->findOrFail($person->id);
            $this->assertNull($fresh->access_scope);
            $this->assertNull($fresh->access_template);
        });
        $this->getJson("{$path}/access")->assertOk()->assertJsonPath('access.role', 'coordinator')->assertJsonPath('access.scope', 'all');
        $this->signInTo($viewer, $f['organization'])->getJson('/api/v1/context/organization')->assertOk()->assertJsonPath('access.scope', 'all');
    }

    public function test_the_administrator_may_not_take_finance_away_with_a_new_role(): void
    {
        $f = $this->rightsFixture();
        [, $person] = $this->viewer($f);
        $admin = $this->memberOf($f['organization'], 'organization_admin');
        $this->personFor($f['organization'], $admin);
        // Given by the owner while finance was on; a viewer has the whole organization by default, a teacher does not.
        $this->inTenant($f['organization'], fn () => StaffGrant::query()->create([
            'staff_member_id' => $person->id, 'ability' => 'finance.view', 'granted_by' => $f['owner']->id, 'granted_at' => now()->subDay(),
        ]));

        $this->signInTo($admin, $f['organization'])->postJson("/api/v1/team/members/{$person->id}/role", ['role' => 'instructor', 'version' => 1])
            ->assertForbidden()->assertJsonPath('message', 'Това би отнело достъпа до финансите — може само собственикът.');
        $this->assertSame('viewer', OrganizationMembership::query()->withoutGlobalScopes()->where('user_id', $person->user_id)->value('role'));
        $this->signInTo($f['owner'], $f['organization'])->postJson("/api/v1/team/members/{$person->id}/role", ['role' => 'instructor', 'version' => 1])->assertOk();
        $this->inTenant($f['organization'], fn () => $this->assertSame('scope_changed', StaffGrant::query()->where('ability', 'finance.view')->value('revoked_reason')));
    }

    public function test_rights_are_given_together_with_the_invitation_and_follow_its_role_and_the_archive(): void
    {
        $f = $this->rightsFixture();
        $this->signInTo($f['owner'], $f['organization']);
        $access = ['scope' => 'groups', 'groups' => [['group_id' => $f['tuesday']->id]], 'grants' => [['ability' => 'enrollment.enroll'], ['ability' => 'attendance.mark']], 'template' => 'school_partner'];

        // Without an invitation there is nobody to give rights to — and nobody is added.
        $this->postJson('/api/v1/team/members', ['full_name' => 'Учител от СУ', 'phone' => '0888 111 222', 'access' => $access])
            ->assertUnprocessable()->assertJsonPath('errors.access.0', 'Първо изпратете покана — правата важат за човек с достъп.');
        $this->inTenant($f['organization'], fn () => $this->assertSame(0, StaffMember::query()->where('full_name', 'Учител от СУ')->count()));

        $added = $this->postJson('/api/v1/team/members', [
            'full_name' => 'Учител от СУ', 'phone' => '0888 111 222', 'invitation' => ['role' => 'viewer', 'email' => 'partner@example.test'], 'access' => $access,
        ])->assertCreated()->assertJsonPath('invitation.role', 'viewer')->assertJsonPath('member.invitation.role', 'viewer')->json();
        $this->assertIsString($added['link']);
        $member = $added['member']['id'];
        $this->getJson("/api/v1/team/members/{$member}/access")->assertOk()
            ->assertJsonPath('access.state', 'access')->assertJsonPath('access.role', 'viewer')
            ->assertJsonPath('access.scope', 'groups')->assertJsonPath('access.template', 'school_partner');

        // Sent again with another role: the rights follow it.
        $this->postJson("/api/v1/team/members/{$member}/invitation", ['role' => 'coordinator'])->assertOk();
        $this->inTenant($f['organization'], function () use ($member): void {
            $this->assertSame(['role_changed'], StaffGrant::query()->where('staff_member_id', $member)->pluck('revoked_reason')->unique()->values()->all());
            $this->assertNull(StaffMember::query()->findOrFail($member)->access_scope);
        });

        // Accepted as a viewer again, after a new invitation with rights: they hold with the login.
        $this->postJson("/api/v1/team/members/{$member}/invitation", ['role' => 'viewer'])->assertOk();
        $version = $this->getJson("/api/v1/team/members/{$member}/access")->json('access.version');
        $this->putJson("/api/v1/team/members/{$member}/access", ['scope' => null, 'grants' => [['ability' => 'contacts.view']], 'version' => $version])->assertOk();
        $token = Str::random(64);
        $this->inTenant($f['organization'], fn () => Invitation::query()->where('staff_member_id', $member)->whereNull('accepted_at')
            ->update(['token_hash' => hash('sha256', $token)]));
        $user = User::factory()->create(['email' => 'partner@example.test']);
        $this->actingAs($user)->postJson('/api/v1/invitations/join', ['token' => $token])->assertOk();
        $this->signInTo($user, $f['organization'])->getJson('/api/v1/context/organization')->assertOk()
            ->assertJsonPath('access.role', 'viewer');
        $this->assertContains('contacts.view', $this->getJson('/api/v1/context/organization')->json('access.abilities'));

        // The archive takes everything.
        $assigned = $this->signInTo($f['owner'], $f['organization'])->getJson("/api/v1/team/members/{$member}")->json('member.version');
        $this->postJson("/api/v1/team/members/{$member}/archive", ['version' => $assigned])->assertOk();
        $this->inTenant($f['organization'], fn () => $this->assertSame(['archived'], StaffGrant::query()->where('staff_member_id', $member)
            ->where('ability', 'contacts.view')->pluck('revoked_reason')->all()));
    }

    public function test_adding_with_an_invitation_and_refused_rights_adds_nobody_and_sends_nothing(): void
    {
        Mail::fake();
        $f = $this->rightsFixture();
        $other = $this->rightsFixture();
        $admin = $this->memberOf($f['organization'], 'organization_admin');
        $this->signInTo($admin, $f['organization']);
        $person = ['full_name' => 'Нов човек', 'phone' => '0888 333 444', 'invitation' => ['role' => 'viewer', 'email' => 'new-person@example.test']];

        // Finance is the owner's; a group of another organization is "not found".
        $this->postJson('/api/v1/team/members', [...$person, 'access' => ['scope' => null, 'grants' => [['ability' => 'finance.view']]]])
            ->assertForbidden()->assertJsonPath('message', 'Финансите дава само собственикът.');
        $this->postJson('/api/v1/team/members', [...$person, 'access' => ['scope' => 'groups', 'groups' => [['group_id' => $other['tuesday']->id]], 'grants' => []]])
            ->assertUnprocessable()->assertJsonPath('errors', ['groups.0.group_id' => ['Групата не е намерена.']]);

        $this->inTenant($f['organization'], function (): void {
            $this->assertSame(0, StaffMember::query()->where('full_name', 'Нов човек')->count());
            $this->assertSame(0, Invitation::query()->where('email', 'new-person@example.test')->count());
            $this->assertSame(0, StaffGrant::query()->count());
        });
        $this->assertSame(0, AuditEvent::query()->where('action', 'team.invitation_sent')->count());
        Mail::assertNothingSent();
    }

    /**
     * @param  array{organization: Organization, owner: User}  $f
     * @return array{User, StaffMember}
     */
    private function viewer(array $f): array
    {
        $viewer = $this->memberOf($f['organization'], 'viewer');

        return [$viewer, $this->personFor($f['organization'], $viewer)];
    }
}
