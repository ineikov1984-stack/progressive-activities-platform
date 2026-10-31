<?php

namespace Tests\Feature\Enrollment;

use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\Session;
use App\Domain\Enrollment\Models\Child;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Enrollment\Models\EnrollmentPause;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\StaffGrant;
use App\Domain\Organizations\Models\StaffMember;
use App\Domain\Organizations\Models\StaffScopeGroup;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Tests\Support\TeamRightsFixture;
use Tests\TestCase;

/**
 * The card of a child in the schedule (docs/product/schedule.md, т. 4.1): the child is seen only through the
 * enrollments in the groups where the person sees the children — the rule of the session's panel
 * (`AccessScope::scheduleChildren`) — and only those groups are listed; anything else is 404. Name, class, the age in
 * full years and the groups with their state and what the person may do there; never the birth date, contacts, money or
 * health, and nothing is written to the audit log. The fixture (TeamRightsFixture) has Ани for the period in
 * "Шах · Вторник", Вики for one visit on 3 November there and Боби waiting for it. Today is Monday 2 November 2026 in
 * Sofia; 90 days back is 4 August. Every name is synthetic.
 */
class ChildCardTest extends TestCase
{
    use RefreshDatabase;
    use TeamRightsFixture;

    private const CARD = '/api/v1/enrollment/children/';

    private const ALL = ['contacts' => true, 'skip' => true, 'pause' => true];

    private const NONE = ['contacts' => false, 'skip' => false, 'pause' => false];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        // Monday 2 November 2026, 12:00 in Sofia.
        $this->travelTo('2026-11-02 10:00:00');
    }

    public function test_the_office_sees_the_childs_groups_with_their_state_and_what_it_may_do_there(): void
    {
        $f = $this->rightsFixture();
        $ani = $f['enrollment']->child_id;
        // Ани goes to "Четвъртък" too, on a pause until 15 November, and went to "Ателие" until 20 October.
        $thursday = $this->enrolAgain($f, $ani, $f['thursday'], startsOn: '2026-10-22');
        $studio = $this->enrolAgain($f, $ani, $f['studio'], status: 'ended', startsOn: '2026-09-01', endsOn: '2026-10-20');
        $this->inTenant($f['organization'], fn () => EnrollmentPause::query()->create([
            'enrollment_id' => $thursday->id, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-15', 'reason_category' => 'health', 'note' => 'Бележка за екипа',
        ]));
        Child::query()->whereKey($ani)->update(['birth_date' => '2017-05-10', 'school_name' => 'СУ „Примерно“']);
        $before = DB::table('audit_events')->count();

        $card = $this->signInTo($f['owner'], $f['organization'])->getJson(self::CARD.$ani)->assertOk();

        $this->assertSame(['id', 'name', 'class', 'age', 'groups'], array_keys($card->json()));
        $card->assertJsonPath('id', $ani)->assertJsonPath('name', 'Ани Иванова')->assertJsonPath('class', '3 клас')->assertJsonPath('age', 9);
        // By activity and group: "Рисуване · Ателие", "Шах · Вторник", "Шах · Четвъртък".
        $card->assertJsonPath('groups', [
            [
                'enrollment_id' => $studio->id, 'group_id' => $f['studio']->id, 'activity' => 'Рисуване', 'group' => 'Ателие', 'version' => 1,
                'state' => 'ended', 'paused_until' => null, 'ended_on' => '2026-10-20', 'abilities' => self::ALL,
            ],
            [
                'enrollment_id' => $f['enrollment']->id, 'group_id' => $f['tuesday']->id, 'activity' => 'Шах', 'group' => 'Вторник', 'version' => 1,
                'state' => 'active', 'paused_until' => null, 'ended_on' => null, 'abilities' => self::ALL,
            ],
            [
                'enrollment_id' => $thursday->id, 'group_id' => $f['thursday']->id, 'activity' => 'Шах', 'group' => 'Четвъртък', 'version' => 1,
                'state' => 'paused', 'paused_until' => '2026-11-15', 'ended_on' => null, 'abilities' => self::ALL,
            ],
        ]);
        // Nothing more: no birth date, school, parent, contact, money, health or note.
        $body = (string) $card->getContent();
        foreach (['2017-05-10', 'birth', 'Примерно', 'Мария', 'parent', 'phone', 'email', 'amount', 'price', 'health', 'Бележка'] as $word) {
            $this->assertStringNotContainsString($word, $body);
        }
        // Reading writes nothing to the audit log.
        $this->assertSame($before, DB::table('audit_events')->count());
    }

    public function test_a_trial_a_pause_ahead_and_an_enrollment_that_begins_later(): void
    {
        $f = $this->rightsFixture();
        $ani = $f['enrollment']->child_id;
        $trial = $this->enrolAgain($f, $ani, $f['thursday'], scope: 'trial', session: $this->sessionOf($f, $f['thursday'], '2026-11-05'));
        $later = $this->enrolAgain($f, $ani, $f['studio'], startsOn: '2026-12-01');
        $this->inTenant($f['organization'], function () use ($f): void {
            EnrollmentPause::query()->create(['enrollment_id' => $f['enrollment']->id, 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-14', 'reason_category' => 'holiday']);
            // A cancelled pause does not count.
            EnrollmentPause::query()->create(['enrollment_id' => $f['enrollment']->id, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-05', 'reason_category' => 'other', 'cancelled_at' => now()]);
        });

        $groups = collect($this->signInTo($f['owner'], $f['organization'])->getJson(self::CARD.$ani)->assertOk()->json('groups'))->keyBy('enrollment_id');

        $this->assertSame(['active', null], [$groups[$f['enrollment']->id]['state'], $groups[$f['enrollment']->id]['paused_until']]);
        $this->assertSame('trial', $groups[$trial->id]['state']);
        $this->assertSame('active', $groups[$later->id]['state']);
        // Вики's one visit.
        $this->getJson(self::CARD.$f['visit']->child_id)->assertOk()->assertJsonPath('class', null)
            ->assertJsonPath('groups', [[
                'enrollment_id' => $f['visit']->id, 'group_id' => $f['tuesday']->id, 'activity' => 'Шах', 'group' => 'Вторник', 'version' => 1,
                'state' => 'single', 'paused_until' => null, 'ended_on' => null, 'abilities' => self::ALL,
            ]]);
    }

    public function test_the_age_is_in_full_years_by_sofia_and_there_is_none_without_a_birth_date(): void
    {
        $f = $this->rightsFixture();
        $ani = $f['enrollment']->child_id;
        $this->signInTo($f['owner'], $f['organization']);

        $this->getJson(self::CARD.$ani)->assertOk()->assertJsonPath('age', null);

        Child::query()->whereKey($ani)->update(['birth_date' => '2017-11-03']);
        // 2 November, 23:59 in Sofia: still 8.
        $this->travelTo('2026-11-02 21:59:00');
        $this->getJson(self::CARD.$ani)->assertOk()->assertJsonPath('age', 8);
        // 3 November, 00:01 in Sofia — 22:01 the day before in UTC: 9 from the start of the birthday.
        $this->travelTo('2026-11-02 22:01:00');
        $card = $this->getJson(self::CARD.$ani)->assertOk()->assertJsonPath('age', 9);
        $this->assertStringNotContainsString('2017-11-03', (string) $card->getContent());
    }

    public function test_a_school_partner_sees_the_child_only_through_the_selected_groups(): void
    {
        $f = $this->rightsFixture();
        $ani = $f['enrollment']->child_id;
        $this->enrolAgain($f, $ani, $f['thursday'], startsOn: '2026-10-22');
        $dani = $this->enrol($f, $f['thursday'], 'Дани Колев');
        Child::query()->whereKey($ani)->update(['birth_date' => '2017-05-10']);
        $partner = $this->memberOf($f['organization'], 'viewer');
        $this->selectGroups($f, $this->personFor($f['organization'], $partner), [$f['tuesday']->id]);
        // The same partner, with "Вижда телефона" and "Записва деца" added.
        $helper = $this->memberOf($f['organization'], 'viewer');
        $person = $this->personFor($f['organization'], $helper);
        $this->selectGroups($f, $person, [$f['tuesday']->id]);
        $this->addGrant($f, $person, 'contacts.view');
        $this->addGrant($f, $person, 'enrollment.enroll');

        $card = $this->signInTo($partner, $f['organization'])->getJson(self::CARD.$ani)->assertOk();
        // Only "Вторник": "Четвъртък" is not even named.
        $this->assertSame([$f['tuesday']->id], array_column($card->json('groups'), 'group_id'));
        // Without the parents' contacts no age either, as in "Подробности" (DECISIONS 30.10.2026).
        $card->assertJsonPath('groups.0.abilities', self::NONE)->assertJsonPath('age', null);
        $this->assertStringNotContainsString($f['thursday']->id, (string) $card->getContent());
        // Дани goes only to "Четвъртък": as if there were no such child.
        $this->getJson(self::CARD.$dani->child_id)->assertNotFound();

        $this->signInTo($helper, $f['organization'])->getJson(self::CARD.$ani)->assertOk()
            ->assertJsonCount(1, 'groups')->assertJsonPath('groups.0.abilities', self::ALL)->assertJsonPath('age', 9);
        $this->getJson(self::CARD.$dani->child_id)->assertNotFound();
    }

    public function test_a_teacher_sees_the_children_of_their_own_group_and_not_where_they_only_stand_in(): void
    {
        $f = $this->rightsFixture();
        [$teacher, $person] = $this->teacherIn($f);
        $profile = $this->profileOf($f['organization'], $person);
        $ani = $f['enrollment']->child_id;
        $this->enrolAgain($f, $ani, $f['thursday'], startsOn: '2026-10-22');
        $dani = $this->enrol($f, $f['thursday'], 'Дани Колев');
        $standIn = $this->sessionOf($f, $f['thursday'], '2026-11-05');
        $this->inTenant($f['organization'], function () use ($f, $profile, $standIn): void {
            $f['tuesday']->assignments()->attach($profile->id, ['role' => 'lead', 'version' => 1]);
            // The teacher stands in on Thursday 5 November.
            $standIn->forceFill(['instructors_source' => 'manual'])->save();
            $standIn->instructors()->attach($profile->id, ['role' => 'lead']);
        });
        Child::query()->whereKey($ani)->update(['birth_date' => '2017-05-10']);
        $this->signInTo($teacher, $f['organization']);

        // Their own group only; without added rights the teacher changes nothing here and sees no phone and no age.
        $card = $this->getJson(self::CARD.$ani)->assertOk();
        $this->assertSame([$f['tuesday']->id], array_column($card->json('groups'), 'group_id'));
        $card->assertJsonPath('groups.0.abilities', self::NONE)->assertJsonPath('age', null);
        $this->getJson(self::CARD.$f['visit']->child_id)->assertOk()->assertJsonPath('groups.0.state', 'single');
        // Дани goes to "Четвъртък", where the teacher only stands in — not before that day and not on it.
        $this->getJson(self::CARD.$dani->child_id)->assertNotFound();
        $this->travelTo('2026-11-05 08:00:00');
        $this->getJson(self::CARD.$dani->child_id)->assertNotFound();
        // On that day the panel names the children, without the eye to a card that is not there.
        $panel = $this->getJson("/api/v1/schedule/sessions/{$standIn->id}")->assertOk()->assertJsonCount(2, 'session.children');
        $this->assertSame([], array_filter(array_column($panel->json('session.children'), 'child_id')));
    }

    public function test_a_stand_in_who_may_enrol_sees_the_card_on_the_day_of_the_session(): void
    {
        $f = $this->rightsFixture();
        [$teacher, $person] = $this->teacherIn($f);
        $profile = $this->profileOf($f['organization'], $person);
        $dani = $this->enrol($f, $f['thursday'], 'Дани Колев');
        $standIn = $this->sessionOf($f, $f['thursday'], '2026-11-05');
        $this->inTenant($f['organization'], function () use ($profile, $standIn): void {
            $standIn->forceFill(['instructors_source' => 'manual'])->save();
            $standIn->instructors()->attach($profile->id, ['role' => 'lead']);
        });
        // "Записва деца" with the scope "Своите групи": a substitution is the teacher's own from the start of its day to
        // the end of the next (`activeGroupsFor`, team-rights.md т. 4.4).
        $this->addGrant($f, $person, 'enrollment.enroll');
        $this->signInTo($teacher, $f['organization']);

        $this->getJson(self::CARD.$dani->child_id)->assertNotFound();

        // Thursday 5 November, 10:00 in Sofia.
        $this->travelTo('2026-11-05 08:00:00');
        $this->getJson(self::CARD.$dani->child_id)->assertOk()->assertJsonCount(1, 'groups')
            ->assertJsonPath('groups.0.group_id', $f['thursday']->id)
            ->assertJsonPath('groups.0.abilities', ['contacts' => false, 'skip' => true, 'pause' => true]);
        $this->getJson("/api/v1/schedule/sessions/{$standIn->id}")->assertOk()
            ->assertJsonPath('session.children.0.child_id', $dani->child_id);

        // Saturday 7 November: the substitution is over.
        $this->travelTo('2026-11-06 22:30:00');
        $this->getJson(self::CARD.$dani->child_id)->assertNotFound();
    }

    public function test_the_age_only_for_who_sees_the_parents_contacts(): void
    {
        $f = $this->rightsFixture();
        $ani = $f['enrollment']->child_id;
        Child::query()->whereKey($ani)->update(['birth_date' => '2017-05-10']);
        // The accountant sees the enrollments of the whole organization, not the contacts.
        $accountant = $this->memberOf($f['organization'], 'finance');
        $this->personFor($f['organization'], $accountant);
        $coordinator = $this->memberOf($f['organization'], 'coordinator');
        $this->personFor($f['organization'], $coordinator);

        $this->signInTo($accountant, $f['organization'])->getJson(self::CARD.$ani)->assertOk()
            ->assertJsonPath('groups.0.abilities.contacts', false)->assertJsonPath('age', null);
        $this->signInTo($coordinator, $f['organization'])->getJson(self::CARD.$ani)->assertOk()
            ->assertJsonPath('groups.0.abilities.contacts', true)->assertJsonPath('age', 9);
    }

    public function test_an_added_right_that_has_expired_shows_no_card(): void
    {
        $f = $this->rightsFixture();
        $ani = $f['enrollment']->child_id;
        // Two teachers with "Вторник" selected: "Записва деца" ended yesterday for one, still holds for the other.
        [$teacher, $person] = $this->teacherIn($f);
        $this->selectGroups($f, $person, [$f['tuesday']->id]);
        $this->inTenant($f['organization'], fn () => StaffGrant::query()->create([
            'staff_member_id' => $person->id, 'ability' => 'enrollment.enroll', 'granted_by' => $f['owner']->id,
            'granted_at' => now()->subDays(10), 'expires_at' => now()->subDay(),
        ]));
        [$colleague, $other] = $this->teacherIn($f);
        $this->selectGroups($f, $other, [$f['tuesday']->id]);
        $this->addGrant($f, $other, 'enrollment.enroll');

        $this->signInTo($teacher, $f['organization'])->getJson(self::CARD.$ani)->assertNotFound();
        $this->signInTo($colleague, $f['organization'])->getJson(self::CARD.$ani)->assertOk()
            ->assertJsonPath('groups.0.abilities', ['contacts' => false, 'skip' => true, 'pause' => true]);
    }

    public function test_a_teacher_with_the_phone_added_may_see_it_in_their_group(): void
    {
        $f = $this->rightsFixture();
        [$teacher, $person] = $this->teacherIn($f);
        $profile = $this->profileOf($f['organization'], $person);
        $this->inTenant($f['organization'], fn () => $f['tuesday']->assignments()->attach($profile->id, ['role' => 'lead', 'version' => 1]));
        $this->addGrant($f, $person, 'contacts.view');
        Child::query()->whereKey($f['enrollment']->child_id)->update(['birth_date' => '2017-05-10']);

        $this->signInTo($teacher, $f['organization'])->getJson(self::CARD.$f['enrollment']->child_id)->assertOk()
            ->assertJsonPath('groups.0.abilities', ['contacts' => true, 'skip' => false, 'pause' => false])->assertJsonPath('age', 9);
    }

    public function test_without_a_right_to_the_children_or_an_enrollment_the_child_is_not_found(): void
    {
        $f = $this->rightsFixture();
        $ani = $f['enrollment']->child_id;
        // The whole schedule to look at is no right to the children.
        [$teacher, $person] = $this->teacherIn($f);
        $this->addGrant($f, $person, 'schedule.view_all');
        $cancelled = $this->enrol($f, $f['tuesday'], 'Георги Колев', status: 'cancelled');
        $old = $this->enrol($f, $f['tuesday'], 'Васил Колев', status: 'ended', startsOn: '2026-03-01', endsOn: '2026-08-03');
        $recent = $this->enrol($f, $f['tuesday'], 'Борис Колев', status: 'ended', startsOn: '2026-03-01', endsOn: '2026-08-04');
        $oldVisit = $this->enrol($f, $f['tuesday'], 'Евгени Колев', scope: 'trial', status: 'ended',
            session: $this->inTenant($f['organization'], fn (): Session => $f['tuesday']->sessions()->create([
                'starts_at' => '2026-07-21T15:00:00Z', 'ends_at' => '2026-07-21T16:00:00Z', 'status' => 'scheduled',
            ])));

        $this->signInTo($teacher, $f['organization'])->getJson(self::CARD.$ani)->assertNotFound();

        $this->signInTo($f['owner'], $f['organization']);
        // Only waiting for a place, cancelled, ended more than 90 days ago, a visit long gone, no such child.
        foreach ([$f['waiting']->child_id, $cancelled->child_id, $old->child_id, $oldVisit->child_id, (string) Str::uuid()] as $childId) {
            $this->getJson(self::CARD.$childId)->assertNotFound();
        }
        $this->getJson(self::CARD.'not-a-child')->assertNotFound();
        // Ended 90 days ago: still there, said as ended.
        $this->getJson(self::CARD.$recent->child_id)->assertOk()
            ->assertJsonPath('groups.0.state', 'ended')->assertJsonPath('groups.0.ended_on', '2026-08-04');
        // An identifier in capitals finds the same child.
        $this->getJson(self::CARD.Str::upper($ani))->assertOk()->assertJsonPath('id', $ani);
    }

    #[TestGroup('tenant-isolation')]
    public function test_another_organizations_child_is_not_found(): void
    {
        $f = $this->rightsFixture();
        $other = $this->rightsFixture();
        $zara = $this->enrol($other, $other['tuesday'], 'Зара Чуждова');

        $this->signInTo($f['owner'], $f['organization']);
        $this->getJson(self::CARD.$zara->child_id)->assertNotFound();
        // The other organization's own "Ани Иванова".
        $this->getJson(self::CARD.$other['enrollment']->child_id)->assertNotFound();
        // An organization the user is not a member of.
        $this->signInTo($f['owner'], $other['organization'])->getJson(self::CARD.$zara->child_id)->assertNotFound();

        // The other way round.
        $this->signInTo($other['owner'], $other['organization'])->getJson(self::CARD.$zara->child_id)->assertOk()
            ->assertJsonPath('groups.0.group_id', $other['tuesday']->id);
        $this->getJson(self::CARD.$f['enrollment']->child_id)->assertNotFound();
    }

    #[TestGroup('tenant-isolation')]
    public function test_one_child_in_two_organizations_shows_only_this_organizations_groups(): void
    {
        $f = $this->rightsFixture();
        $other = $this->rightsFixture();
        // One child of one parent in both organizations: the children table is shared and has no RLS.
        $parent = User::factory()->create(['name' => 'Невена Стоева']);
        $child = $parent->children()->create(['first_name' => 'Яна', 'last_name' => 'Стоева', 'class_label' => '1 клас', 'birth_date' => '2019-04-01']);
        $elsewhere = [
            $this->enrolChild($other, $parent, $child->id, $other['tuesday']),
            $this->enrolChild($other, $parent, $child->id, $other['thursday']),
        ];
        $here = $this->enrolChild($f, $parent, $child->id, $f['thursday']);
        // Another child of the same parent: enrolled there, only an application here.
        $sister = $parent->children()->create(['first_name' => 'Ива', 'last_name' => 'Стоева']);
        $this->enrolChild($other, $parent, $sister->id, $other['studio']);
        $this->inTenant($f['organization'], fn () => $this->rightsApplication($parent, $sister->id, $f['studio']->id, $f['price']->id, 'submitted'));

        $card = $this->signInTo($f['owner'], $f['organization'])->getJson(self::CARD.$child->id)->assertOk()
            ->assertJsonPath('name', 'Яна Стоева')->assertJsonPath('age', 7);
        $this->assertSame([$here->id], array_column($card->json('groups'), 'enrollment_id'));
        $body = (string) $card->getContent();
        foreach ([$other['tuesday']->id, $other['thursday']->id, $elsewhere[0]->id, $elsewhere[1]->id] as $id) {
            $this->assertStringNotContainsString($id, $body);
        }
        $this->getJson(self::CARD.$sister->id)->assertNotFound();

        $this->signInTo($other['owner'], $other['organization']);
        $this->assertSame(
            [$other['tuesday']->id, $other['thursday']->id],
            array_column($this->getJson(self::CARD.$child->id)->assertOk()->json('groups'), 'group_id'),
        );
        $this->getJson(self::CARD.$sister->id)->assertOk()->assertJsonPath('groups.0.group_id', $other['studio']->id);
    }

    public function test_the_card_counts_its_own_120_a_minute(): void
    {
        $f = $this->rightsFixture();
        $viewer = $this->memberOf($f['organization'], 'viewer');
        $this->personFor($f['organization'], $viewer);
        $this->signInTo($viewer, $f['organization']);

        foreach (range(1, 120) as $ignored) {
            $this->getJson(self::CARD.$f['enrollment']->child_id)->assertOk();
        }
        $this->getJson(self::CARD.$f['enrollment']->child_id)->assertTooManyRequests();
        // The search of the filter and the schedule keep their own counters.
        $this->getJson('/api/v1/enrollment/children?q='.urlencode('Ани'))->assertOk();
        $this->getJson("/api/v1/schedule/sessions/{$f['session']->id}")->assertOk();
    }

    public function test_the_panel_of_a_session_names_each_child_for_the_card(): void
    {
        $f = $this->rightsFixture();

        $children = $this->signInTo($f['owner'], $f['organization'])->getJson("/api/v1/schedule/sessions/{$f['session']->id}")->assertOk()
            ->json('session.children');

        $this->assertSame([$f['enrollment']->child_id, $f['visit']->child_id], array_column($children, 'child_id'));
        foreach (array_column($children, 'child_id') as $childId) {
            $this->getJson(self::CARD.$childId)->assertOk()->assertJsonPath('id', $childId);
        }
    }

    /**
     * A teacher with a profile and no group yet.
     *
     * @param  array<string, mixed>  $f
     * @return array{User, StaffMember}
     */
    private function teacherIn(array $f): array
    {
        $teacher = $this->memberOf($f['organization'], 'instructor');

        return [$teacher, $this->personFor($f['organization'], $teacher, teaches: true)];
    }

    /** @param array<string, mixed> $f */
    private function addGrant(array $f, StaffMember $person, string $ability): void
    {
        $this->inTenant($f['organization'], fn () => StaffGrant::query()->create([
            'staff_member_id' => $person->id, 'ability' => $ability, 'granted_by' => $f['owner']->id, 'granted_at' => now()->subDay(),
        ]));
    }

    /**
     * The scope "Избрани групи" with these groups.
     *
     * @param  array<string, mixed>  $f
     * @param  list<string>  $groupIds
     */
    private function selectGroups(array $f, StaffMember $person, array $groupIds): void
    {
        $this->inTenant($f['organization'], function () use ($f, $person, $groupIds): void {
            $person->forceFill(['access_scope' => 'groups'])->save();
            foreach ($groupIds as $groupId) {
                StaffScopeGroup::query()->create(['staff_member_id' => $person->id, 'group_id' => $groupId, 'added_by' => $f['owner']->id, 'added_at' => now()]);
            }
        });
    }

    /** @param array<string, mixed> $f */
    private function sessionOf(array $f, Group $group, string $day): Session
    {
        return $this->inTenant($f['organization'], fn (): Session => Session::query()->where('group_id', $group->id)
            ->where('starts_at', '>=', "{$day} 00:00:00")->where('starts_at', '<', "{$day} 23:59:59")->firstOrFail());
    }

    /**
     * A new child of the owner enrolled in the group.
     *
     * @param  array<string, mixed>  $f
     */
    private function enrol(array $f, Group $group, string $name, string $scope = 'full_period', ?Session $session = null, string $status = 'active', string $startsOn = '2026-11-01', ?string $endsOn = null): Enrollment
    {
        /** @var User $owner */
        $owner = $f['owner'];
        [$first, $last] = explode(' ', $name, 2);
        $childId = $owner->children()->create(['first_name' => $first, 'last_name' => $last, 'class_label' => '2 клас'])->id;

        return $this->enrolAgain($f, $childId, $group, $scope, $session, $status, $startsOn, $endsOn);
    }

    /**
     * A child who is already here, enrolled in another group too: for the period from a day, or for one session.
     *
     * @param  array<string, mixed>  $f
     */
    private function enrolAgain(array $f, string $childId, Group $group, string $scope = 'full_period', ?Session $session = null, string $status = 'active', string $startsOn = '2026-11-01', ?string $endsOn = null): Enrollment
    {
        return $this->inTenant($f['organization'], function () use ($f, $childId, $group, $scope, $session, $status, $startsOn, $endsOn): Enrollment {
            $application = EnrollmentApplication::query()->create([
                'parent_user_id' => $f['owner']->id, 'child_id' => $childId, 'group_id' => $group->id, 'price_option_id' => $f['price']->id,
                'session_id' => $session?->id, 'scope' => $scope, 'source' => 'organization_catalog', 'status' => 'approved',
                'idempotency_key' => (string) Str::uuid(), 'accepted_terms_version' => 'v1', 'accepted_terms_at' => now(),
                'amount_cents_snapshot' => 4500, 'currency_snapshot' => 'EUR', 'unit_snapshot' => 'month',
            ]);

            return Enrollment::query()->create([
                'application_id' => $application->id, 'child_id' => $childId, 'group_id' => $group->id, 'price_option_id' => $f['price']->id,
                'session_id' => $session?->id, 'scope' => $scope, 'status' => $status,
                'starts_on' => $session === null ? $startsOn : $session->starts_at->setTimezone('Europe/Sofia')->toDateString(), 'ends_on' => $endsOn,
            ]);
        });
    }

    /**
     * Another parent's child enrolled for the period in a group of an organization.
     *
     * @param  array<string, mixed>  $f
     */
    private function enrolChild(array $f, User $parent, string $childId, Group $group): Enrollment
    {
        return $this->inTenant($f['organization'], function () use ($f, $parent, $childId, $group): Enrollment {
            $application = $this->rightsApplication($parent, $childId, $group->id, $f['price']->id, 'approved');

            return Enrollment::query()->create([
                'application_id' => $application->id, 'child_id' => $childId, 'group_id' => $group->id,
                'price_option_id' => $f['price']->id, 'scope' => 'full_period', 'status' => 'active', 'starts_on' => '2026-10-20',
            ]);
        });
    }
}
