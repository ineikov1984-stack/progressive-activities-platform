<?php

namespace Tests\Feature\Organizations;

use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Actions\SaveStaffAccess;
use App\Domain\Organizations\Enums\StaffScope;
use App\Domain\Organizations\Models\Invitation;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\StaffGrant;
use App\Domain\Organizations\Models\StaffMember;
use App\Domain\Organizations\Models\StaffScopeGroup;
use App\Domain\Organizations\Services\StaffRights;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\TeamRightsFixture;
use Tests\TestCase;

/**
 * The card "Права" is saved whole, after the guards of docs/product/team-rights.md, т. 5: only the owner and the
 * administrator, never for oneself, the owner, an archived person or a person without a login or an invitation;
 * everyone gives only what they have; finance only the owner, confirmed, for the whole organization, once it is on;
 * the administrator keeps the rights they may not give unchanged and may not take finance away.
 */
class SaveStaffAccessTest extends TestCase
{
    use RefreshDatabase;
    use TeamRightsFixture;

    protected function setUp(): void
    {
        parent::setUp();
        // Monday 2 November 2026, 12:00 in Sofia.
        $this->travelTo('2026-11-02 10:00:00');
    }

    public function test_the_owner_makes_a_school_partner_and_the_same_card_again_changes_nothing(): void
    {
        $f = $this->rightsFixture();
        [$partner, $person] = $this->viewer($f);
        $card = [
            'scope' => 'groups', 'groups' => [['group_id' => $f['tuesday']->id]], 'template' => 'school_partner', 'version' => 1,
            'grants' => [['ability' => 'enrollment.enroll', 'expires_on' => '2027-06-30'], ['ability' => 'attendance.mark']],
        ];

        $saved = $this->save($f, $f['owner'], $person, $card);

        $this->assertSame(2, $saved->version);
        $this->assertSame('groups', $saved->access_scope);
        $this->assertSame('school_partner', $saved->access_template);
        $this->inTenant($f['organization'], function () use ($f, $partner, $person): void {
            // "до 30 юни 2027" — the start of 1 July 2027 in Sofia.
            $this->assertSame('2027-06-30 21:00:00', StaffGrant::query()->where('ability', 'enrollment.enroll')->firstOrFail()->expires_at?->utc()->format('Y-m-d H:i:s'));
            $this->assertSame([$f['tuesday']->id], StaffScopeGroup::query()->where('staff_member_id', $person->id)->pluck('group_id')->all());
            $access = app(StaffRights::class)->for($partner);
            $this->assertSame(StaffScope::Groups, $access->scope);
            $this->assertTrue($access->has('enrollment.enroll'));
        });

        $again = $this->save($f, $f['owner'], $person, [...$card, 'version' => 2]);
        $this->assertSame(2, $again->version);

        // Without one of its two rights the template's label goes too.
        $less = $this->save($f, $f['owner'], $person, [...$card, 'version' => 2, 'template' => null, 'grants' => [['ability' => 'enrollment.enroll', 'expires_on' => '2027-06-30']]]);
        $this->assertNull($less->access_template);
        $this->inTenant($f['organization'], fn () => $this->assertSame('removed', StaffGrant::query()->where('ability', 'attendance.mark')->firstOrFail()->revoked_reason));
        $this->assertSame([422, 'Партньорът от училище е „Само преглед“ с избрани групи, записване и присъствия.'], $this->outcome(
            fn () => $this->save($f, $f['owner'], $person, [...$card, 'version' => 3, 'grants' => [['ability' => 'attendance.mark']]]),
        ));
    }

    public function test_whose_card_it_is(): void
    {
        $f = $this->rightsFixture();
        [$viewer, $person] = $this->viewer($f);
        $admin = $this->memberOf($f['organization'], 'organization_admin');
        $adminPerson = $this->personFor($f['organization'], $admin);
        $ownerPerson = $this->inTenant($f['organization'], fn (): StaffMember => StaffMember::query()->where('user_id', $f['owner']->id)->firstOrFail());
        $card = ['scope' => null, 'grants' => [['ability' => 'contacts.view']], 'version' => 1];

        $this->assertSame([422, 'Не можете да променяте собствените си права.'], $this->outcome(fn () => $this->save($f, $admin, $adminPerson, $card)));
        $this->assertSame([422, 'Собственикът има всички права.'], $this->outcome(fn () => $this->save($f, $admin, $ownerPerson, $card)));
        $this->assertSame([403, 'Нямате право да променяте правата на този човек.'], $this->outcome(fn () => $this->save($f, $this->secondAdmin($f), $adminPerson, $card)));
        $this->assertSame([403, 'This action is unauthorized.'], $this->outcome(fn () => $this->save($f, $viewer, $person, $card)));
        $coordinator = $this->memberOf($f['organization'], 'coordinator');
        $this->assertSame(403, $this->outcome(fn () => $this->save($f, $coordinator, $person, $card))[0]);
        $this->assertSame([409, 'Правата са променени междувременно. Заредете страницата отново.'], $this->outcome(fn () => $this->save($f, $f['owner'], $person, [...$card, 'version' => 7])));

        // A person without a login and without an invitation has no rights to keep.
        $this->assertSame([422, 'Първо изпратете покана — правата важат за човек с достъп.'], $this->outcome(fn () => $this->save($f, $f['owner'], $f['person'], $card)));
        // With a pending invitation the rights go with the invitation's role.
        $this->inTenant($f['organization'], fn () => Invitation::query()->create([
            'organization_id' => $f['organization']->id, 'email' => 'petar@example.test', 'role' => 'viewer', 'token_hash' => Str::random(64), 'active_key' => Str::random(20),
            'expires_at' => now()->addDays(7), 'invited_by' => $f['owner']->id, 'staff_member_id' => $f['person']->id,
        ]));
        $this->assertSame(2, $this->save($f, $f['owner'], $f['person'], $card)->version);

        $this->inTenant($f['organization'], fn () => $person->forceFill(['archived_at' => now()])->save());
        $this->assertSame([422, 'Човекът е в архива.'], $this->outcome(fn () => $this->save($f, $f['owner'], $person, $card)));

        // Another organization's person is not found.
        $other = $this->rightsFixture();
        $this->assertSame(404, $this->outcome(fn () => $this->save($f, $f['owner'], $other['person'], $card))[0]);
    }

    public function test_what_may_be_added_where(): void
    {
        $f = $this->rightsFixture();
        [, $person] = $this->viewer($f);
        $card = fn (array $grants, ?string $scope = null, array $groups = []): array => ['scope' => $scope, 'groups' => $groups, 'grants' => $grants, 'version' => 1];

        $this->assertSame([422, 'Това право идва само с ролята.'], $this->outcome(fn () => $this->save($f, $f['owner'], $person, $card([['ability' => 'team.manage']]))));
        $this->assertSame([422, 'Това право идва само с ролята.'], $this->outcome(fn () => $this->save($f, $f['owner'], $person, $card([['ability' => 'enrollment.view']]))));
        $this->assertSame([422, 'Ролята вече дава това право.'], $this->outcome(fn () => $this->save($f, $f['owner'], $person, $card([['ability' => 'team.view']]))));
        $this->assertSame([422, 'Финансите и настройките се дават само за цялата организация.'], $this->outcome(
            fn () => $this->save($f, $f['owner'], $person, $card([['ability' => 'catalog.manage']], 'groups', [['group_id' => $f['tuesday']->id]])),
        ));
        $this->assertSame([422, 'Изберете поне една група.'], $this->outcome(fn () => $this->save($f, $f['owner'], $person, $card([], 'groups'))));
        $this->assertSame([422, 'Изберете за кои групи важат правата.'], $this->outcome(fn () => $this->save($f, $f['owner'], $person, $card([], 'own'))));
        $this->assertSame([422, 'Крайната дата трябва да е след днес.'], $this->outcome(fn () => $this->save($f, $f['owner'], $person, $card([['ability' => 'contacts.view', 'expires_on' => '2026-11-02']]))));
        $this->assertSame([422, 'Изберете дата до три години напред.'], $this->outcome(fn () => $this->save($f, $f['owner'], $person, $card([['ability' => 'contacts.view', 'expires_on' => '2029-11-03']]))));
        $this->assertSame(2, $this->save($f, $f['owner'], $person, $card([['ability' => 'contacts.view', 'expires_on' => '2029-11-02'], ['ability' => 'catalog.publish']]))->version);

        // The roles locked on the whole organization have no other scope.
        $coordinator = $this->memberOf($f['organization'], 'coordinator');
        $coordinatorPerson = $this->personFor($f['organization'], $coordinator);
        $this->assertSame([422, 'За тази роля обхватът е цялата организация.'], $this->outcome(
            fn () => $this->save($f, $f['owner'], $coordinatorPerson, $card([], 'groups', [['group_id' => $f['tuesday']->id]])),
        ));
    }

    public function test_finance_is_the_owners_confirmed_for_the_whole_organization_and_only_once_it_is_on(): void
    {
        $f = $this->rightsFixture();
        [, $person] = $this->viewer($f);
        $admin = $this->memberOf($f['organization'], 'organization_admin');
        $finance = ['scope' => null, 'grants' => [['ability' => 'finance.view']], 'version' => 1];

        $this->assertSame([403, 'Финансите дава само собственикът.'], $this->outcome(fn () => $this->save($f, $admin, $person, $finance)));
        $this->assertSame([422, 'Финансите още не са включени.'], $this->outcome(fn () => $this->save($f, $f['owner'], $person, [...$finance, 'confirm_finance' => true])));
        config(['rights.finance_enabled' => true]);
        $this->assertSame([422, 'Потвърдете, че давате достъп до финансите.'], $this->outcome(fn () => $this->save($f, $f['owner'], $person, $finance)));
        $this->assertSame([422, 'Финансите и настройките се дават само за цялата организация.'], $this->outcome(
            fn () => $this->save($f, $f['owner'], $person, [...$finance, 'scope' => 'groups', 'groups' => [['group_id' => $f['tuesday']->id]], 'confirm_finance' => true]),
        ));
        $this->assertSame(2, $this->save($f, $f['owner'], $person, [...$finance, 'confirm_finance' => true])->version);
    }

    public function test_the_administrator_keeps_finance_unchanged_and_may_not_take_it_away(): void
    {
        $f = $this->rightsFixture();
        [, $person] = $this->viewer($f);
        $admin = $this->memberOf($f['organization'], 'organization_admin');
        // Given by the owner until the end of 2026.
        $this->inTenant($f['organization'], fn () => StaffGrant::query()->create([
            'staff_member_id' => $person->id, 'ability' => 'finance.view', 'granted_by' => $f['owner']->id,
            'granted_at' => now()->subDay(), 'expires_at' => '2026-12-31 22:00:00',
        ]));
        $kept = ['ability' => 'finance.view', 'expires_on' => '2026-12-31'];

        // Unchanged, it is kept while the administrator adds what they may give.
        $saved = $this->save($f, $admin, $person, ['scope' => null, 'grants' => [$kept, ['ability' => 'enrollment.enroll']], 'version' => 1]);
        $this->assertSame(2, $saved->version);
        $this->inTenant($f['organization'], fn () => $this->assertSame(2, StaffGrant::query()->whereNull('revoked_at')->count()));

        $changeOnly = 'Можете да променяте само права, които сами давате.';
        $this->assertSame([403, $changeOnly], $this->outcome(fn () => $this->save($f, $admin, $person, ['scope' => null, 'grants' => [['ability' => 'enrollment.enroll']], 'version' => 2])));
        $this->assertSame([403, $changeOnly], $this->outcome(fn () => $this->save($f, $admin, $person, ['scope' => null, 'grants' => [['ability' => 'finance.view', 'expires_on' => '2027-01-31'], ['ability' => 'enrollment.enroll']], 'version' => 2])));
        $this->assertSame([403, 'Това би отнело достъпа до финансите — може само собственикът.'], $this->outcome(fn () => $this->save($f, $admin, $person, [
            'scope' => 'groups', 'groups' => [['group_id' => $f['tuesday']->id]], 'grants' => [$kept, ['ability' => 'enrollment.enroll']], 'version' => 2,
        ])));
        $this->inTenant($f['organization'], fn () => $this->assertSame(2, StaffGrant::query()->whereNull('revoked_at')->count()));

        // The owner may.
        $narrowed = $this->save($f, $f['owner'], $person, [
            'scope' => 'groups', 'groups' => [['group_id' => $f['tuesday']->id]], 'grants' => [['ability' => 'enrollment.enroll']], 'version' => 2,
        ]);
        $this->assertSame('groups', $narrowed->access_scope);
        $this->inTenant($f['organization'], fn () => $this->assertSame('removed', StaffGrant::query()->where('ability', 'finance.view')->firstOrFail()->revoked_reason));
    }

    public function test_an_expired_right_given_again_closes_the_old_row(): void
    {
        $f = $this->rightsFixture();
        [$viewer, $person] = $this->viewer($f);
        $old = $this->inTenant($f['organization'], fn (): StaffGrant => StaffGrant::query()->create([
            'staff_member_id' => $person->id, 'ability' => 'contacts.view', 'granted_by' => $f['owner']->id,
            'granted_at' => now()->subDays(10), 'expires_at' => now()->subDay(),
        ]));

        $this->save($f, $f['owner'], $person, ['scope' => null, 'grants' => [['ability' => 'contacts.view']], 'version' => 1]);

        $this->inTenant($f['organization'], function () use ($old, $viewer): void {
            $this->assertSame('expired', StaffGrant::query()->findOrFail($old->id)->revoked_reason);
            $this->assertSame(1, StaffGrant::query()->whereNull('revoked_at')->where('ability', 'contacts.view')->count());
            $this->assertTrue(app(StaffRights::class)->for($viewer)->has('contacts.view'));
        });
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

    /** @param array{organization: Organization} $f */
    private function secondAdmin(array $f): User
    {
        $admin = $this->memberOf($f['organization'], 'organization_admin');
        $this->personFor($f['organization'], $admin);

        return $admin;
    }

    /**
     * @param  array{organization: Organization}  $f
     * @param  array<string, mixed>  $card
     */
    private function save(array $f, User $actor, StaffMember $person, array $card): StaffMember
    {
        $this->actingAs($actor);

        return $this->inTenant($f['organization'], fn (): StaffMember => app(SaveStaffAccess::class)->handle($actor, $person, $card));
    }

    /**
     * The status and the message a refusal would give.
     *
     * @return array{int, string}
     */
    private function outcome(callable $action): array
    {
        try {
            $action();
        } catch (ValidationException $exception) {
            return [422, (string) collect($exception->errors())->flatten()->first()];
        } catch (AuthorizationException $exception) {
            return [403, $exception->getMessage()];
        } catch (HttpException $exception) {
            return [$exception->getStatusCode(), $exception->getMessage()];
        }

        return [200, ''];
    }
}
