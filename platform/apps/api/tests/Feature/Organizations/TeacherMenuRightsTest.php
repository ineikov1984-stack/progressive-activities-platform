<?php

namespace Tests\Feature\Organizations;

use App\Domain\Catalog\Models\Group;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\StaffGrant;
use App\Domain\Organizations\Models\StaffMember;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Tests\Support\TeamRightsFixture;
use Tests\TestCase;

/**
 * The owner tried the dashboard as a teacher (DECISIONS 31.10.2026 „Учителят — само своите групи; кой одобрява и кой
 * вижда таксите“): the teacher has "Начало" and their own groups — no activities, open days or payment terms unless
 * an added right needs them; "Записва деца" decides the applications of their groups but not how a group approves;
 * the payment terms are read by the owner, the administrator, the coordinator and the accountant. And "История" of a
 * profile names the rights given or taken and the role before and after.
 */
#[TestGroup('authorization')]
class TeacherMenuRightsTest extends TestCase
{
    use RefreshDatabase;
    use TeamRightsFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->travelTo('2026-11-02 10:00:00');
    }

    public function test_the_teacher_sees_their_own_groups_without_the_catalog_or_the_payment_terms(): void
    {
        $f = $this->rightsFixture();
        [$teacher] = $this->teacherOf($f);

        $this->signInTo($teacher, $f['organization']);
        $this->getJson('/api/v1/me/teaching')->assertOk()
            ->assertJsonCount(1, 'groups')->assertJsonPath('groups.0.group_id', $f['tuesday']->id);
        $this->getJson("/api/v1/enrollment/groups/{$f['tuesday']->id}/roster")->assertOk();
        $this->getJson('/api/v1/catalog/activities')->assertForbidden();
        $this->getJson('/api/v1/catalog/open-day-campaigns')->assertForbidden();
        $this->getJson('/api/v1/payment-terms')->assertForbidden()->assertJsonMissingPath('payment_terms');

        $permissions = $this->getJson('/api/v1/organizations')->assertOk()->json('organizations.0.permissions');
        $this->assertSame(['view', 'enrollment.view_own'], $permissions);
        $abilities = $this->getJson('/api/v1/context/organization')->assertOk()->json('access.abilities');
        $this->assertIsArray($abilities);
        $this->assertNotContains('catalog.view', $abilities);
        $this->assertNotContains('terms.view', $abilities);
    }

    /** The parents' page of the activity does not ask for a right: PaymentTermsCopyTest keeps its terms. */
    public function test_the_payment_terms_are_read_by_the_owner_the_administrator_the_coordinator_and_the_accountant(): void
    {
        $f = $this->rightsFixture();
        foreach (['organization_owner' => 200, 'organization_admin' => 200, 'coordinator' => 200, 'finance' => 200, 'instructor' => 403, 'viewer' => 403] as $role => $status) {
            $this->signInTo($this->memberOf($f['organization'], $role), $f['organization'])
                ->getJson('/api/v1/payment-terms')->assertStatus($status);
        }
        $accountant = $this->signInTo($this->memberOf($f['organization'], 'finance'), $f['organization'])->getJson('/api/v1/organizations')->json('organizations.0.permissions');
        $this->assertIsArray($accountant);
        $this->assertContains('terms.view', $accountant);
    }

    /** The catalog does not carry the terms to those who do not read them: neither the rules of a visit nor the chosen sets. */
    public function test_the_catalog_gives_the_payment_terms_only_to_those_who_read_them(): void
    {
        $f = $this->rightsFixture();
        $offer = "/api/v1/catalog/visit-offers/{$f['offer']->id}";
        $activity = "/api/v1/catalog/activities/{$f['activity']->id}";
        // A teacher who places teachers in the groups reads the catalog, but not the terms.
        [$assigner, $person] = $this->teacherOf($f);
        $this->inTenant($f['organization'], function () use ($f, $person): void {
            $person->forceFill(['access_scope' => 'all'])->save();
            $this->grant($f, $person, 'team.assign');
        });

        foreach ([$this->memberOf($f['organization'], 'viewer'), $assigner] as $reader) {
            $this->signInTo($reader, $f['organization']);
            $this->getJson('/api/v1/payment-terms')->assertForbidden();
            $this->getJson('/api/v1/catalog/visit-offers')->assertOk()->assertJsonPath('visit_offers.0.payment_terms', null);
            $this->getJson($offer)->assertOk()->assertJsonPath('visit_offer.payment_terms', null)->assertJsonPath('visit_offer.title', 'Пробно занимание');
            $this->getJson('/api/v1/catalog/activities')->assertOk()->assertJsonPath('activities.0.offering.payment_terms', null);
            $this->getJson($activity)->assertOk()->assertJsonPath('activity.offering.payment_terms', null)->assertJsonPath('activity.title', 'Шах');
        }

        foreach (['organization_owner', 'coordinator'] as $role) {
            $this->signInTo($this->memberOf($f['organization'], $role), $f['organization']);
            $this->getJson($offer)->assertOk()->assertJsonPath('visit_offer.payment_terms.billing_kind', 'visit');
            $this->assertNotNull($this->getJson($activity)->assertOk()->json('activity.offering.payment_terms'));
        }
    }

    public function test_an_added_right_that_needs_the_catalog_brings_it_and_the_payment_terms(): void
    {
        $f = $this->rightsFixture();
        [$teacher, $person] = $this->teacherOf($f);
        $this->inTenant($f['organization'], function () use ($f, $person): void {
            $person->forceFill(['access_scope' => 'all'])->save();
            $this->grant($f, $person, 'catalog.manage');
        });

        $this->signInTo($teacher, $f['organization']);
        $this->getJson('/api/v1/catalog/activities')->assertOk();
        // The editor of the activities chooses the payment terms, so it reads them — and may set how a group approves.
        $this->getJson('/api/v1/payment-terms')->assertOk()->assertJsonPath('can_manage', false);
        $this->patchJson("/api/v1/enrollment/groups/{$f['tuesday']->id}/approval", ['enrollment_approval_mode' => 'automatic'])->assertOk();

        // With the teacher's own groups the rights for the whole organization fall away, and what they bring along too.
        $this->inTenant($f['organization'], fn () => $person->forceFill(['access_scope' => 'own'])->save());
        $this->signInTo($teacher, $f['organization']);
        $this->getJson('/api/v1/catalog/activities')->assertForbidden();
        $this->getJson('/api/v1/payment-terms')->assertForbidden();
        $this->patchJson("/api/v1/enrollment/groups/{$f['tuesday']->id}/approval", ['enrollment_approval_mode' => 'manual'])->assertForbidden();
    }

    public function test_enroll_children_decides_the_applications_but_only_the_catalog_sets_how_a_group_approves(): void
    {
        $f = $this->rightsFixture();
        [$teacher, $person] = $this->teacherOf($f);
        $this->inTenant($f['organization'], fn () => $this->grant($f, $person, 'enrollment.enroll'));
        $partner = $this->memberOf($f['organization'], 'viewer');
        $partnerPerson = $this->personFor($f['organization'], $partner);
        $this->inTenant($f['organization'], fn () => $this->grant($f, $partnerPerson, 'enrollment.enroll'));
        $approval = "/api/v1/enrollment/groups/{$f['tuesday']->id}/approval";

        // The teacher's own group: the decision reaches its validation; the setting of the group does not.
        $this->signInTo($teacher, $f['organization'])->postJson("/api/v1/enrollment/applications/{$f['waiting']->id}/review", [])->assertUnprocessable();
        $this->signInTo($teacher, $f['organization'])->patchJson($approval, ['enrollment_approval_mode' => 'automatic'])->assertForbidden();
        $this->signInTo($partner, $f['organization'])->patchJson($approval, ['enrollment_approval_mode' => 'automatic'])->assertForbidden();
        $this->inTenant($f['organization'], fn () => $this->assertSame('manual', Group::query()->findOrFail($f['tuesday']->id)->enrollment_approval_mode));

        foreach (['organization_owner', 'organization_admin', 'coordinator'] as $role) {
            $this->signInTo($this->memberOf($f['organization'], $role), $f['organization'])
                ->patchJson($approval, ['enrollment_approval_mode' => 'manual'])->assertOk();
        }
        foreach (['finance', 'viewer', 'instructor'] as $role) {
            $this->signInTo($this->memberOf($f['organization'], $role), $f['organization'])
                ->patchJson($approval, ['enrollment_approval_mode' => 'automatic'])->assertForbidden();
        }
        // Another organization's group: not found, never changed.
        $other = $this->rightsFixture();
        $this->signInTo($this->memberOf($f['organization'], 'coordinator'), $f['organization'])
            ->patchJson("/api/v1/enrollment/groups/{$other['tuesday']->id}/approval", ['enrollment_approval_mode' => 'automatic'])->assertNotFound();
        $this->inTenant($other['organization'], fn () => $this->assertSame('manual', Group::query()->findOrFail($other['tuesday']->id)->enrollment_approval_mode));
    }

    public function test_the_profile_history_names_the_rights_given_or_taken_together_and_the_role_change(): void
    {
        $f = $this->rightsFixture();
        [, $person] = $this->teacherOf($f);
        $path = "/api/v1/team/members/{$person->id}";

        $groups = [['group_id' => $f['tuesday']->id], ['activity_id' => $f['painting']->id]];
        $this->signInTo($f['owner'], $f['organization'])->putJson("{$path}/access", [
            'scope' => 'groups', 'groups' => $groups, 'grants' => [['ability' => 'contacts.message'], ['ability' => 'enrollment.enroll']], 'version' => 1,
        ])->assertOk();
        $this->signInTo($f['owner'], $f['organization'])->putJson("{$path}/access", ['scope' => 'groups', 'groups' => $groups, 'grants' => [], 'version' => 2])->assertOk();
        $this->signInTo($f['owner'], $f['organization'])->postJson("{$path}/role", ['role' => 'coordinator', 'version' => 3])->assertOk();

        $history = $this->signInTo($f['owner'], $f['organization'])->getJson($path)->assertOk()->json('history');
        $this->assertIsArray($history);
        $by = fn (string $action): array => array_values(array_filter($history, fn (array $entry): bool => $entry['action'] === $action));

        $given = $by('team.access_grant_added');
        $this->assertCount(1, $given, 'The rights of one save are one entry.');
        $this->assertSame(['enrollment.enroll', 'contacts.message'], $given[0]['abilities']);
        $this->assertSame($f['owner']->name, $given[0]['by']);
        $taken = $by('team.access_grant_revoked');
        $this->assertCount(1, $taken);
        $this->assertSame(['enrollment.enroll', 'contacts.message'], $taken[0]['abilities']);
        $this->assertSame('removed', $taken[0]['reason']);
        $role = $by('team.role_changed');
        $this->assertCount(1, $role);
        $this->assertSame(['instructor', 'coordinator'], [$role[0]['from'], $role[0]['to']]);
        // A group and a whole activity, each by its kind only.
        $this->assertEqualsCanonicalizing(['group', 'activity'], array_column($by('team.access_group_added'), 'target'));

        // Keys only: no identifiers of the records behind, no contacts.
        $text = (string) json_encode($history, JSON_UNESCAPED_UNICODE);
        foreach (['grant_id', 'membership_id', 'scope_group_id', 'group_id', 'activity_id', 'expires_at', '@example', 'phone'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $text);
        }
        // The history is only for those who manage the team.
        $this->signInTo($this->memberOf($f['organization'], 'coordinator'), $f['organization'])->getJson($path)->assertOk()->assertJsonMissingPath('history');
    }

    /**
     * A teacher with a login, a person of the team and a profile, leading "Вторник".
     *
     * @param  array<string, mixed>  $f
     * @return array{User, StaffMember}
     */
    private function teacherOf(array $f): array
    {
        $teacher = $this->memberOf($f['organization'], 'instructor');
        $person = $this->personFor($f['organization'], $teacher, teaches: true);
        $profile = $this->profileOf($f['organization'], $person);
        $this->inTenant($f['organization'], fn () => $f['tuesday']->assignments()->attach($profile->id, ['role' => 'lead', 'version' => 1]));

        return [$teacher, $person];
    }

    /** Inside the organization. @param array<string, mixed> $f */
    private function grant(array $f, StaffMember $person, string $ability): StaffGrant
    {
        return StaffGrant::query()->create([
            'staff_member_id' => $person->id, 'ability' => $ability, 'granted_by' => $f['owner']->id, 'granted_at' => now()->subDay(),
        ]);
    }
}
