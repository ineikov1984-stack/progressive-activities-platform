<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Catalog\Models\Location;
use App\Domain\Catalog\Models\Session;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Enrollment\Models\EnrollmentPause;
use App\Domain\Enrollment\Models\EnrollmentSessionException;
use App\Domain\Enrollment\Services\EnrollmentCapacity;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\StaffGrant;
use App\Domain\Organizations\Models\StaffMember;
use App\Domain\Organizations\Models\StaffScopeGroup;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Tests\Support\TeamRightsFixture;
use Tests\TestCase;

/**
 * "График" — the API of PR 1 (docs/product/schedule.md, т. 2, 5, 6): who sees which sessions, places and children,
 * the roles of the teachers, the days of Sofia and a constant number of queries. The fixture (TeamRightsFixture) has
 * "Шах · Вторник" (3–24 November), "Рисуване · Ателие" (4–25) and "Шах · Четвъртък" (5–26), each at 17:00 in Sofia;
 * Ани for the period and Вики for one visit on 3 November in "Вторник", Боби waiting for it. Every name is synthetic.
 */
class ScheduleApiTest extends TestCase
{
    use RefreshDatabase;
    use TeamRightsFixture;

    private const NOVEMBER = '/api/v1/schedule?from=2026-11-02&to=2026-11-29';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        // Monday 2 November 2026, 12:00 in Sofia.
        $this->travelTo('2026-11-02 10:00:00');
    }

    public function test_the_office_sees_every_session_with_its_places_and_the_dictionaries(): void
    {
        $f = $this->rightsFixture();
        // Waiting for one session (a visit) is not waiting for a place in the group.
        $this->inTenant($f['organization'], fn () => EnrollmentApplication::query()->whereKey($f['waiting']->id)->firstOrFail()
            ->replicate()->fill(['session_id' => $f['session']->id, 'idempotency_key' => (string) Str::uuid()])->save());

        $response = $this->signInTo($f['owner'], $f['organization'])->getJson(self::NOVEMBER)->assertOk()->assertJsonCount(12, 'sessions');

        $response->assertJsonPath('sessions.0', [
            'id' => $f['session']->id,
            'starts_at' => '2026-11-03T15:00:00+00:00',
            'ends_at' => '2026-11-03T16:00:00+00:00',
            'status' => 'scheduled',
            'version' => 1,
            'moved' => false,
            'moved_from' => null,
            'extra' => false,
            'group_id' => $f['tuesday']->id,
            'activity_id' => $f['activity']->id,
            'location_id' => null,
            'instructors' => [],
            'capacity' => 10,
            // Ани for the period and Вики for this one visit.
            'taken' => 2,
            'waiting' => 1,
            'not_held' => false,
            'attendance' => null,
        ]);
        $this->assertSame(
            [$f['tuesday']->id, $f['studio']->id, $f['thursday']->id],
            array_slice(array_column($response->json('sessions'), 'group_id'), 0, 3),
        );
        $response->assertJsonPath('sessions.3.taken', 1)->assertJsonPath('sessions.1.taken', 0)->assertJsonPath('sessions.1.waiting', 0)
            ->assertJsonPath('sessions.1.capacity', 8);
        $response->assertJsonPath('activities', [
            ['id' => $f['painting']->id, 'title' => 'Рисуване', 'color' => 0],
            ['id' => $f['activity']->id, 'title' => 'Шах', 'color' => 1],
        ]);
        $this->assertSame([$f['studio']->id, $f['tuesday']->id, $f['thursday']->id], array_column($response->json('groups'), 'id'));
        $response->assertJsonPath('groups.1', ['id' => $f['tuesday']->id, 'name' => 'Вторник', 'activity_id' => $f['activity']->id, 'age_label' => '2.–4. клас'])
            ->assertJsonPath('locations', [])->assertJsonPath('instructors', []);
        foreach ([$f['tuesday'], $f['thursday'], $f['studio']] as $group) {
            $response->assertJsonPath("abilities.{$group->id}", ['children' => true, 'manage' => true, 'substitute' => true]);
        }
    }

    public function test_sessions_at_the_same_time_go_by_activity_and_the_filters_narrow_the_list(): void
    {
        $f = $this->rightsFixture();
        $profile = $this->inTenant($f['organization'], function () use ($f): InstructorProfile {
            $profile = InstructorProfile::query()->create(['public_name' => 'Мария Колева']);
            Session::query()->whereKey($f['session']->id)->firstOrFail()->instructors()->attach($profile->id, ['role' => 'lead']);
            // "Рисуване" at the same time as the first "Вторник", in "Зала 1".
            $f['studio']->sessions()->create(['starts_at' => '2026-11-03T15:00:00Z', 'ends_at' => '2026-11-03T16:00:00Z', 'status' => 'scheduled', 'location_id' => $f['location']->id]);

            return $profile;
        });
        $this->signInTo($f['owner'], $f['organization']);

        $all = $this->getJson(self::NOVEMBER)->assertOk()->assertJsonCount(13, 'sessions');
        $this->assertSame([$f['studio']->id, $f['tuesday']->id], array_slice(array_column($all->json('sessions'), 'group_id'), 0, 2));
        $all->assertJsonPath('locations', [['id' => $f['location']->id, 'name' => 'Зала 1']])
            ->assertJsonPath('instructors', [['id' => $profile->id, 'name' => 'Мария Колева']]);

        $this->assertSame([$f['session']->id], $this->sessionIds(self::NOVEMBER."&instructor[]={$profile->id}"));
        $this->assertCount(5, $this->sessionIds(self::NOVEMBER."&activity[]={$f['painting']->id}"));
        $this->assertCount(4, $this->sessionIds(self::NOVEMBER."&group[]={$f['thursday']->id}"));
        $this->assertCount(8, $this->sessionIds(self::NOVEMBER."&group[]={$f['thursday']->id}&group[]={$f['tuesday']->id}"));
        $this->assertCount(1, $this->sessionIds(self::NOVEMBER."&location[]={$f['location']->id}"));
        // Several filters at once: all of them hold.
        $this->assertSame([], $this->sessionIds(self::NOVEMBER."&location[]={$f['location']->id}&group[]={$f['tuesday']->id}"));
        // An identifier in capitals finds the same.
        $this->assertCount(4, $this->sessionIds(self::NOVEMBER.'&group[]='.Str::upper($f['thursday']->id)));
        // The dictionaries do not depend on the filters.
        $this->getJson(self::NOVEMBER."&group[]={$f['thursday']->id}")->assertJsonCount(2, 'activities')->assertJsonCount(3, 'groups');
    }

    public function test_the_period_is_up_to_42_days_and_the_parameters_are_checked(): void
    {
        $f = $this->rightsFixture();
        $this->signInTo($f['owner'], $f['organization']);

        // 1 November – 12 December: 42 days.
        $this->getJson('/api/v1/schedule?from=2026-11-01&to=2026-12-12')->assertOk()->assertJsonCount(12, 'sessions');
        $this->getJson('/api/v1/schedule?from=2026-11-01&to=2026-12-13')->assertUnprocessable()->assertJsonValidationErrors('to')
            ->assertJsonPath('errors.to.0', 'Изберете период до 42 дни.');
        $this->getJson('/api/v1/schedule?from=2026-11-10&to=2026-11-09')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/api/v1/schedule?to=2026-11-09')->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->getJson('/api/v1/schedule?from=2026-11-3&to=2026-11-09')->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->getJson(self::NOVEMBER.'&group[]=nope')->assertUnprocessable()->assertJsonValidationErrors('group.0');
        $this->getJson(self::NOVEMBER.'&child=nope')->assertUnprocessable()->assertJsonValidationErrors('child');
        $many = implode('&', array_map(fn (): string => 'instructor[]='.Str::uuid(), range(1, 51)));
        $this->getJson(self::NOVEMBER.'&'.$many)->assertUnprocessable()->assertJsonValidationErrors('instructor');
        $fifty = implode('&', array_map(fn (): string => 'location[]='.Str::uuid(), range(1, 50)));
        $this->getJson(self::NOVEMBER.'&'.$fifty)->assertOk()->assertJsonCount(0, 'sessions');
    }

    public function test_a_session_belongs_to_its_day_in_sofia_across_the_change_of_time(): void
    {
        $f = $this->rightsFixture();
        $at = $this->inTenant($f['organization'], function () use ($f): array {
            $at = [];
            foreach ([
                // 25 October 2026: summer time ends at 04:00 — the day has 25 hours.
                'oct25_0030' => '2026-10-24T21:30:00Z', 'oct25_2330' => '2026-10-25T21:30:00Z', 'oct26_0030' => '2026-10-25T22:30:00Z',
                // 29 March 2026: summer time starts at 03:00 — the day has 23 hours.
                'mar28_2330' => '2026-03-28T21:30:00Z', 'mar29_0030' => '2026-03-28T22:30:00Z', 'mar29_2330' => '2026-03-29T20:30:00Z',
                'mar30_0030' => '2026-03-29T21:30:00Z',
            ] as $key => $startsAt) {
                $endsAt = CarbonImmutable::parse($startsAt)->addMinutes(45)->utc()->format('Y-m-d\\TH:i:s\\Z');
                $at[$key] = $f['tuesday']->sessions()->create(['starts_at' => $startsAt, 'ends_at' => $endsAt, 'status' => 'scheduled'])->id;
            }

            return $at;
        });
        $this->signInTo($f['owner'], $f['organization']);

        $this->assertSame([], $this->sessionIds('/api/v1/schedule?from=2026-10-24&to=2026-10-24'));
        $this->assertSame([$at['oct25_0030'], $at['oct25_2330']], $this->sessionIds('/api/v1/schedule?from=2026-10-25&to=2026-10-25'));
        $this->assertSame([$at['oct26_0030']], $this->sessionIds('/api/v1/schedule?from=2026-10-26&to=2026-10-26'));
        $this->assertSame([$at['mar28_2330']], $this->sessionIds('/api/v1/schedule?from=2026-03-28&to=2026-03-28'));
        $this->assertSame([$at['mar29_0030'], $at['mar29_2330']], $this->sessionIds('/api/v1/schedule?from=2026-03-29&to=2026-03-29'));
        $this->assertSame([$at['mar30_0030']], $this->sessionIds('/api/v1/schedule?from=2026-03-30&to=2026-03-30'));
    }

    #[TestGroup('tenant-isolation')]
    public function test_another_organizations_session_is_not_found_and_its_groups_and_filters_give_nothing(): void
    {
        $f = $this->rightsFixture();
        $other = $this->rightsFixture();
        $foreignProfile = $this->inTenant($other['organization'], function () use ($other): InstructorProfile {
            $profile = InstructorProfile::query()->create(['public_name' => 'Чужд Треньор']);
            Session::query()->whereKey($other['session']->id)->firstOrFail()->instructors()->attach($profile->id, ['role' => 'lead']);

            return $profile;
        });
        $this->signInTo($f['owner'], $f['organization']);

        $this->getJson("/api/v1/schedule/sessions/{$other['session']->id}")->assertNotFound();
        $this->getJson('/api/v1/schedule/sessions/'.Str::uuid())->assertNotFound();
        foreach ([
            "group[]={$other['tuesday']->id}", "activity[]={$other['activity']->id}", "location[]={$other['location']->id}",
            "instructor[]={$foreignProfile->id}", "child={$other['enrollment']->child_id}",
        ] as $filter) {
            $this->assertSame([], $this->sessionIds(self::NOVEMBER.'&'.$filter), $filter);
        }
        $all = $this->getJson(self::NOVEMBER)->assertOk()->assertJsonCount(12, 'sessions');
        $seen = json_encode($all->json(), JSON_THROW_ON_ERROR);
        foreach ([$other['tuesday']->id, $other['activity']->id, $other['session']->id, $foreignProfile->id] as $foreign) {
            $this->assertStringNotContainsString($foreign, $seen);
        }

        // The other organization's owner, the other way round.
        $this->signInTo($other['owner'], $other['organization'])->getJson("/api/v1/schedule/sessions/{$f['session']->id}")->assertNotFound();
        $this->getJson("/api/v1/schedule/sessions/{$other['session']->id}")->assertOk()
            ->assertJsonPath('session.instructors.0.name', 'Чужд Треньор');
    }

    public function test_a_school_partner_sees_only_the_selected_groups_even_with_the_catalog(): void
    {
        $f = $this->rightsFixture();
        $partner = $this->memberOf($f['organization'], 'viewer');
        $person = $this->personFor($f['organization'], $partner);
        $this->selectGroups($f, $person, [$f['tuesday']->id]);
        $thursdaySession = $this->sessionOf($f, $f['thursday'], '2026-11-12');
        $this->signInTo($partner, $f['organization']);

        $list = $this->getJson(self::NOVEMBER)->assertOk()->assertJsonCount(4, 'sessions');
        $this->assertSame([$f['tuesday']->id], array_values(array_unique(array_column($list->json('sessions'), 'group_id'))));
        $list->assertJsonPath('groups', [['id' => $f['tuesday']->id, 'name' => 'Вторник', 'activity_id' => $f['activity']->id, 'age_label' => '2.–4. клас']])
            ->assertJsonPath('activities', [['id' => $f['activity']->id, 'title' => 'Шах', 'color' => 0]])
            ->assertJsonPath("abilities.{$f['tuesday']->id}", ['children' => true, 'manage' => false, 'substitute' => false]);
        $this->assertNull($list->json("abilities.{$f['thursday']->id}"));
        // A filter outside the scope gives nothing, without an error.
        $this->assertSame([], $this->sessionIds(self::NOVEMBER."&group[]={$f['thursday']->id}"));
        $this->getJson("/api/v1/schedule/sessions/{$thursdaySession->id}")->assertNotFound();

        $panel = $this->getJson("/api/v1/schedule/sessions/{$f['session']->id}")->assertOk()
            ->assertJsonPath('session.children', [
                [
                    'enrollment_id' => $f['enrollment']->id, 'child_id' => $f['enrollment']->child_id, 'name' => 'Ани Иванова', 'class' => '3 клас',
                    'state' => 'active', 'scope' => 'full_period',
                ],
                ['enrollment_id' => $f['visit']->id, 'child_id' => $f['visit']->child_id, 'name' => 'Вики Петрова', 'class' => null, 'state' => 'active', 'scope' => 'single'],
            ])
            ->assertJsonPath('group', ['id' => $f['tuesday']->id, 'name' => 'Вторник', 'age_label' => '2.–4. клас'])
            ->assertJsonPath('activity', ['id' => $f['activity']->id, 'title' => 'Шах'])
            ->assertJsonPath('location', null)
            ->assertJsonMissingPath('session.change_reason');
        // Name and class only — and the child for the card (т. 4.1): no parent, contact, health or money.
        $this->assertSame(['enrollment_id', 'child_id', 'name', 'class', 'state', 'scope'], array_keys($panel->json('session.children.0')));

        // A selected activity covers its groups.
        $this->selectGroups($f, $person, [], [$f['painting']->id]);
        $this->assertCount(8, $this->sessionIds(self::NOVEMBER));
    }

    public function test_the_accountant_sees_the_schedule_of_the_scope_without_changes(): void
    {
        $f = $this->rightsFixture();
        $this->inTenant($f['organization'], fn () => Session::query()->whereKey($f['session']->id)->update(['status' => 'cancelled', 'change_reason' => 'Ремонт на залата']));
        $accountant = $this->memberOf($f['organization'], 'finance');
        $this->personFor($f['organization'], $accountant);

        $this->signInTo($accountant, $f['organization'])->getJson(self::NOVEMBER)->assertOk()->assertJsonCount(12, 'sessions')
            ->assertJsonPath('sessions.0.status', 'cancelled')
            ->assertJsonPath("abilities.{$f['thursday']->id}", ['children' => true, 'manage' => false, 'substitute' => false]);
        $this->getJson("/api/v1/schedule/sessions/{$f['session']->id}")->assertOk()
            ->assertJsonPath('session.status', 'cancelled')->assertJsonMissingPath('session.change_reason');
        $this->assertSame([$f['session']->id], $this->sessionIds(self::NOVEMBER."&child={$f['visit']->child_id}"));

        // Who changes the schedule sees why it changed.
        $this->signInTo($f['owner'], $f['organization'])->getJson("/api/v1/schedule/sessions/{$f['session']->id}")->assertOk()
            ->assertJsonPath('session.change_reason', 'Ремонт на залата');
    }

    public function test_seeing_the_whole_schedule_as_an_added_right_shows_every_session_but_not_the_children(): void
    {
        $f = $this->rightsFixture();
        [$teacher, $person] = $this->teacherIn($f);
        $this->addGrant($f, $person, 'schedule.view_all');
        $this->signInTo($teacher, $f['organization']);

        $this->getJson(self::NOVEMBER)->assertOk()->assertJsonCount(12, 'sessions')->assertJsonCount(3, 'groups')
            ->assertJsonPath("abilities.{$f['tuesday']->id}", ['children' => false, 'manage' => false, 'substitute' => false]);
        $this->getJson("/api/v1/schedule/sessions/{$f['session']->id}")->assertOk()->assertJsonMissingPath('session.children');
        $this->getJson(self::NOVEMBER."&child={$f['enrollment']->child_id}")->assertForbidden();
    }

    public function test_a_teacher_sees_their_groups_whole_and_where_they_only_stand_in_just_those_sessions(): void
    {
        $f = $this->rightsFixture();
        [$teacher, $person] = $this->teacherIn($f);
        $profile = $this->profileOf($f['organization'], $person);
        $hall2 = $this->inTenant($f['organization'], fn (): Location => Location::query()->create(['name' => 'Зала 2', 'type' => 'physical', 'space_usage' => 'shared']));
        $colleague = $this->inTenant($f['organization'], fn (): InstructorProfile => InstructorProfile::query()->create(['public_name' => 'Ани Петрова']));
        $daniel = $this->enrol($f, $f['thursday'], 'Дани Колев');
        $standIn = $this->sessionOf($f, $f['thursday'], '2026-11-12');
        $other = $this->sessionOf($f, $f['thursday'], '2026-11-19');
        $this->inTenant($f['organization'], function () use ($f, $profile, $colleague, $hall2, $standIn, $other): void {
            $f['tuesday']->assignments()->attach($profile->id, ['role' => 'lead', 'version' => 1]);
            Group::query()->whereKey($f['thursday']->id)->update(['location_id' => $hall2->id]);
            $f['thursday']->assignments()->attach($colleague->id, ['role' => 'lead', 'version' => 1]);
            foreach (Session::query()->where('group_id', $f['thursday']->id)->get() as $session) {
                $session->forceFill(['location_id' => $hall2->id])->save();
                if ($session->id !== $standIn->id) {
                    $session->instructors()->attach($colleague->id, ['role' => 'lead']);
                }
            }
            // Changed by hand: the teacher stands in on Thursday 12 November, in "Зала 1"; the editor writes "lead".
            $standIn->forceFill(['location_id' => $f['location']->id, 'instructors_source' => 'manual'])->save();
            $standIn->instructors()->attach($profile->id, ['role' => 'lead']);
            $other->forceFill(['change_reason' => 'Друга зала'])->save();
        });
        $this->signInTo($teacher, $f['organization']);

        $list = $this->getJson(self::NOVEMBER)->assertOk()->assertJsonCount(5, 'sessions');
        $ids = array_column($list->json('sessions'), 'id');
        $this->assertContains($standIn->id, $ids);
        $this->assertNotContains($other->id, $ids);
        $row = collect($list->json('sessions'))->firstWhere('id', $standIn->id);
        $this->assertSame([[
            'profile_id' => $profile->id, 'name' => $profile->public_name, 'role' => 'substitute',
            'replaces' => [['profile_id' => $colleague->id, 'name' => 'Ани Петрова']],
        ]], $row['instructors']);
        // The dictionaries tell nothing more: the colleague, "Зала 2" and "Рисуване" are not there.
        $list->assertJsonPath('groups.0.id', $f['tuesday']->id)->assertJsonPath('groups.1.id', $f['thursday']->id)->assertJsonCount(2, 'groups')
            ->assertJsonPath('activities', [['id' => $f['activity']->id, 'title' => 'Шах', 'color' => 0]])
            ->assertJsonPath('locations', [['id' => $f['location']->id, 'name' => 'Зала 1']])
            ->assertJsonPath('instructors', [['id' => $profile->id, 'name' => $profile->public_name]])
            ->assertJsonPath("abilities.{$f['tuesday']->id}", ['children' => true, 'manage' => false, 'substitute' => false])
            ->assertJsonPath("abilities.{$f['thursday']->id}", ['children' => false, 'manage' => false, 'substitute' => false]);
        $this->assertSame([], $this->sessionIds(self::NOVEMBER."&instructor[]={$colleague->id}"));
        $this->getJson("/api/v1/schedule/sessions/{$other->id}")->assertNotFound();
        $this->getJson(self::NOVEMBER."&child={$daniel->child_id}")->assertForbidden();

        // In their own group the teacher sees the children; where they stand in, not before the day of the session.
        $this->getJson("/api/v1/schedule/sessions/{$f['session']->id}")->assertOk()->assertJsonCount(2, 'session.children')
            ->assertJsonPath('session.children.0.child_id', $f['enrollment']->child_id);
        $this->getJson("/api/v1/schedule/sessions/{$standIn->id}")->assertOk()->assertJsonMissingPath('session.children')
            ->assertJsonPath('location', ['id' => $f['location']->id, 'name' => 'Зала 1']);
        // Thursday 12 November, 10:00 in Sofia: the day of the session.
        $this->travelTo('2026-11-12 08:00:00');
        $this->getJson("/api/v1/schedule/sessions/{$standIn->id}")->assertOk()
            // Where the teacher only stands in, the child has no card (т. 4.1): no `child_id`.
            ->assertJsonPath('session.children', [['enrollment_id' => $daniel->id, 'name' => 'Дани Колев', 'class' => '2 клас', 'state' => 'active', 'scope' => 'full_period']]);
        $this->getJson("/api/v1/schedule/sessions/{$other->id}")->assertNotFound();
    }

    public function test_a_stand_in_sees_their_sessions_since_yesterday_and_the_children_on_the_day_and_the_next(): void
    {
        $f = $this->rightsFixture();
        [$teacher, $person] = $this->teacherIn($f);
        $profile = $this->profileOf($f['organization'], $person);
        $daniel = $this->enrol($f, $f['thursday'], 'Дани Колев', 'full_period', null, 'active', '2026-09-01');
        $old = $this->inTenant($f['organization'], fn (): Session => $f['thursday']->sessions()->create([
            'starts_at' => '2026-10-01T14:00:00Z', 'ends_at' => '2026-10-01T15:00:00Z', 'status' => 'scheduled',
        ]));
        $twelfth = $this->sessionOf($f, $f['thursday'], '2026-11-12');
        $nineteenth = $this->sessionOf($f, $f['thursday'], '2026-11-19');
        $cancelled = $this->sessionOf($f, $f['thursday'], '2026-11-26');
        $this->inTenant($f['organization'], function () use ($profile, $old, $twelfth, $nineteenth, $cancelled): void {
            // The teacher stands in on 1 October and again from 12 November; the last of these is cancelled.
            foreach ([$old, $twelfth, $nineteenth, $cancelled] as $session) {
                $session->forceFill(['instructors_source' => 'manual'])->save();
                $session->instructors()->attach($profile->id, ['role' => 'lead']);
            }
            Session::query()->whereKey($cancelled->id)->update(['status' => 'cancelled']);
        });
        // Thursday 12 November, 10:00 in Sofia.
        $this->travelTo('2026-11-12 08:00:00');
        $this->signInTo($teacher, $f['organization']);

        // 1 October is long gone: neither the session nor its children (docs/product/schedule.md, т. 6.4).
        $this->assertSame([], $this->sessionIds('/api/v1/schedule?from=2026-09-28&to=2026-11-08'));
        $this->getJson("/api/v1/schedule/sessions/{$old->id}")->assertNotFound();
        $this->assertSame([$twelfth->id, $nineteenth->id, $cancelled->id], $this->sessionIds(self::NOVEMBER));
        $this->getJson(self::NOVEMBER)->assertJsonPath("abilities.{$f['thursday']->id}", ['children' => false, 'manage' => false, 'substitute' => false]);
        // Today the children; a substitution later on, not yet.
        $this->getJson("/api/v1/schedule/sessions/{$twelfth->id}")->assertOk()
            ->assertJsonPath('session.children.0.enrollment_id', $daniel->id)->assertJsonPath('abilities.children', true)
            ->assertJsonMissingPath('session.children.0.child_id');
        $this->getJson("/api/v1/schedule/sessions/{$nineteenth->id}")->assertOk()
            ->assertJsonMissingPath('session.children')->assertJsonPath('abilities.children', false);
        $this->getJson("/api/v1/schedule/sessions/{$cancelled->id}")->assertOk()
            ->assertJsonPath('session.status', 'cancelled')->assertJsonMissingPath('session.children');

        // Friday 13 November, 23:59 in Sofia: the session was yesterday.
        $this->travelTo('2026-11-13 21:59:00');
        $this->getJson("/api/v1/schedule/sessions/{$twelfth->id}")->assertOk()->assertJsonCount(1, 'session.children');
        // Saturday 14 November, 00:01 in Sofia: the day before yesterday — out of the schedule.
        $this->travelTo('2026-11-13 22:01:00');
        $this->getJson("/api/v1/schedule/sessions/{$twelfth->id}")->assertNotFound();
        $this->assertSame([$nineteenth->id, $cancelled->id], $this->sessionIds(self::NOVEMBER));
    }

    public function test_an_added_right_that_has_expired_no_longer_shows_the_whole_schedule(): void
    {
        $f = $this->rightsFixture();
        [$teacher, $person] = $this->teacherIn($f);
        $this->inTenant($f['organization'], fn () => StaffGrant::query()->create([
            'staff_member_id' => $person->id, 'ability' => 'schedule.view_all', 'granted_by' => $f['owner']->id,
            'granted_at' => now()->subDays(10), 'expires_at' => now()->subDay(),
        ]));

        $this->signInTo($teacher, $f['organization'])->getJson(self::NOVEMBER)->assertOk()
            ->assertJsonCount(0, 'sessions')->assertJsonPath('groups', [])->assertJsonPath('activities', []);
        $this->getJson("/api/v1/schedule/sessions/{$f['session']->id}")->assertNotFound();
    }

    public function test_an_archived_person_sees_nothing(): void
    {
        $f = $this->rightsFixture();
        $viewer = $this->memberOf($f['organization'], 'viewer');
        $person = $this->personFor($f['organization'], $viewer);
        $this->signInTo($viewer, $f['organization'])->getJson(self::NOVEMBER)->assertOk()->assertJsonCount(12, 'sessions');

        // The archive stops the login (docs/product/team.md, т. 6).
        $this->signInTo($f['owner'], $f['organization'])->postJson("/api/v1/team/members/{$person->id}/archive", ['version' => 1])->assertOk();

        $this->signInTo($viewer, $f['organization'])->getJson(self::NOVEMBER)->assertNotFound();
        $this->getJson("/api/v1/schedule/sessions/{$f['session']->id}")->assertNotFound();
    }

    public function test_the_child_filter_goes_only_through_the_groups_where_the_person_sees_enrollments(): void
    {
        $f = $this->rightsFixture();
        [$teacher, $person] = $this->teacherIn($f);
        $profile = $this->profileOf($f['organization'], $person);
        $this->inTenant($f['organization'], fn () => $f['thursday']->assignments()->attach($profile->id, ['role' => 'lead', 'version' => 1]));
        // "Записва деца" brings "enrollment.view" in the teacher's groups only; the whole schedule only to look at.
        $this->addGrant($f, $person, 'enrollment.enroll');
        $this->addGrant($f, $person, 'schedule.view_all');
        $daniel = $this->enrol($f, $f['thursday'], 'Дани Колев');
        $this->signInTo($teacher, $f['organization']);

        $this->assertCount(4, $this->sessionIds(self::NOVEMBER."&child={$daniel->child_id}"));
        // Ани goes to "Вторник", which the teacher sees in the schedule but not among the enrollments.
        $this->assertSame([], $this->sessionIds(self::NOVEMBER."&child={$f['enrollment']->child_id}"));
        $this->getJson(self::NOVEMBER)->assertOk()->assertJsonCount(12, 'sessions')
            ->assertJsonPath("abilities.{$f['tuesday']->id}", ['children' => false, 'manage' => false, 'substitute' => false])
            ->assertJsonPath("abilities.{$f['thursday']->id}", ['children' => true, 'manage' => false, 'substitute' => false]);

        // The office: every group.
        $this->signInTo($f['owner'], $f['organization']);
        $this->assertCount(4, $this->sessionIds(self::NOVEMBER."&child={$f['enrollment']->child_id}"));
        $this->assertSame([$f['session']->id], $this->sessionIds(self::NOVEMBER."&child={$f['visit']->child_id}&group[]={$f['tuesday']->id}"));
    }

    public function test_the_roles_come_from_the_assignments_not_from_the_session(): void
    {
        $f = $this->rightsFixture();
        [$lead, $assistant, $standIn] = $this->inTenant($f['organization'], function () use ($f): array {
            $lead = InstructorProfile::query()->create(['public_name' => 'Ани Петрова']);
            $assistant = InstructorProfile::query()->create(['public_name' => 'Иван Петров']);
            $standIn = InstructorProfile::query()->create(['public_name' => 'Деси Маринова']);
            $f['tuesday']->assignments()->attach($lead->id, ['role' => 'lead', 'version' => 1]);
            $f['tuesday']->assignments()->attach($assistant->id, ['role' => 'assistant', 'version' => 1]);
            $tenth = Session::query()->where('group_id', $f['tuesday']->id)->where('starts_at', '>=', '2026-11-10 00:00:00')->orderBy('starts_at')->firstOrFail();
            // Changed by hand: the editor writes "lead" for everyone.
            $tenth->instructors()->attach([$assistant->id => ['role' => 'lead'], $standIn->id => ['role' => 'lead']]);
            Session::query()->whereKey($f['session']->id)->firstOrFail()->instructors()->attach([$lead->id => ['role' => 'lead'], $assistant->id => ['role' => 'lead']]);

            return [$lead, $assistant, $standIn];
        });

        $sessions = $this->signInTo($f['owner'], $f['organization'])->getJson(self::NOVEMBER."&group[]={$f['tuesday']->id}")->assertOk()->json('sessions');

        $this->assertSame([
            ['profile_id' => $lead->id, 'name' => 'Ани Петрова', 'role' => 'lead'],
            ['profile_id' => $assistant->id, 'name' => 'Иван Петров', 'role' => 'assistant'],
        ], $sessions[0]['instructors']);
        $this->assertSame([
            ['profile_id' => $standIn->id, 'name' => 'Деси Маринова', 'role' => 'substitute', 'replaces' => [['profile_id' => $lead->id, 'name' => 'Ани Петрова']]],
            ['profile_id' => $assistant->id, 'name' => 'Иван Петров', 'role' => 'assistant'],
        ], $sessions[1]['instructors']);
        $this->getJson("/api/v1/schedule/sessions/{$sessions[1]['id']}")->assertOk()->assertJsonPath('session.instructors.0.role', 'substitute');
    }

    public function test_an_explicit_substitute_names_the_replaced_assistant_in_the_panel_and_dictionary(): void
    {
        $f = $this->rightsFixture();
        [$user, $person] = $this->teacherIn($f);
        $profile = $this->profileOf($f['organization'], $person);
        $replaced = $this->inTenant($f['organization'], function () use ($f, $profile): InstructorProfile {
            $replaced = InstructorProfile::query()->create(['public_name' => 'Иван Петров']);
            $f['session']->forceFill(['instructors_source' => 'manual'])->save();
            $f['session']->instructors()->attach($profile->id, ['role' => 'assistant', 'replaces_instructor_profile_id' => $replaced->id]);

            return $replaced;
        });
        $this->signInTo($user, $f['organization']);
        $expected = [['profile_id' => $profile->id, 'name' => $profile->public_name, 'role' => 'substitute', 'replaces' => [['profile_id' => $replaced->id, 'name' => 'Иван Петров']]]];
        $this->getJson(self::NOVEMBER)->assertOk()->assertJsonCount(1, 'sessions')->assertJsonPath('sessions.0.instructors', $expected)
            ->assertJsonFragment(['id' => $replaced->id, 'name' => 'Иван Петров']);
        $this->getJson("/api/v1/schedule/sessions/{$f['session']->id}")->assertOk()->assertJsonPath('session.instructors', $expected)->assertJsonMissingPath('session.children');
    }

    public function test_places_taken_agree_with_the_rule_for_one_session_and_the_panel_names_who_is_away(): void
    {
        $f = $this->rightsFixture();
        $sessions = [];
        foreach (['05', '12', '19', '26'] as $day) {
            $sessions[$day] = $this->sessionOf($f, $f['thursday'], "2026-11-{$day}");
        }
        $always = $this->enrol($f, $f['thursday'], 'Ася Георгиева');
        $paused = $this->enrol($f, $f['thursday'], 'Борис Йорданов');
        $skipping = $this->enrol($f, $f['thursday'], 'Цвети Стоева');
        $trial = $this->enrol($f, $f['thursday'], 'Гала Тодорова', 'trial', $sessions['26']);
        $single = $this->enrol($f, $f['thursday'], 'Дея Николова', 'single', $sessions['05']);
        $this->enrol($f, $f['thursday'], 'Ема Димова', 'full_period', null, 'cancelled');
        $ending = $this->enrol($f, $f['thursday'], 'Жоро Иванов', 'full_period', null, 'ended', '2026-11-01', '2026-11-15');
        $this->inTenant($f['organization'], function () use ($always, $paused, $skipping, $sessions): void {
            EnrollmentPause::query()->create(['enrollment_id' => $paused->id, 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-14', 'reason_category' => 'holiday']);
            // A cancelled pause does not count.
            EnrollmentPause::query()->create(['enrollment_id' => $always->id, 'starts_on' => '2026-11-20', 'ends_on' => '2026-11-30', 'reason_category' => 'other', 'cancelled_at' => now()]);
            EnrollmentSessionException::query()->create(['enrollment_id' => $skipping->id, 'session_id' => $sessions['19']->id, 'kind' => 'excluded']);
        });

        $list = $this->signInTo($f['owner'], $f['organization'])->getJson(self::NOVEMBER."&group[]={$f['thursday']->id}")->assertOk();
        $taken = array_column($list->json('sessions'), 'taken', 'id');

        $this->assertSame([
            $sessions['05']->id => 5, $sessions['12']->id => 3, $sessions['19']->id => 2, $sessions['26']->id => 4,
        ], $taken);
        $this->inTenant($f['organization'], function () use ($sessions, $taken): void {
            foreach ($sessions as $session) {
                $fresh = Session::query()->with('group')->findOrFail($session->id);
                $this->assertSame(10 - $taken[$session->id], app(EnrollmentCapacity::class)->remainingForSession($fresh));
            }
        });

        $states = fn (Session $session): array => array_column(
            $this->getJson("/api/v1/schedule/sessions/{$session->id}")->assertOk()->json('session.children'), 'state', 'name',
        );
        $this->assertSame(['Ася Георгиева' => 'active', 'Борис Йорданов' => 'paused', 'Жоро Иванов' => 'active', 'Цвети Стоева' => 'active'], $states($sessions['12']));
        $this->assertSame(['Ася Георгиева' => 'active', 'Борис Йорданов' => 'active', 'Цвети Стоева' => 'skipped'], $states($sessions['19']));
        $this->assertSame('trial', collect($this->getJson("/api/v1/schedule/sessions/{$sessions['26']->id}")->json('session.children'))->firstWhere('name', 'Гала Тодорова')['scope']);
        $this->assertSame('single', collect($this->getJson("/api/v1/schedule/sessions/{$sessions['05']->id}")->json('session.children'))->firstWhere('name', 'Дея Николова')['scope']);
        $this->assertSame([$trial->id], array_column(array_filter(
            $this->getJson("/api/v1/schedule/sessions/{$sessions['26']->id}")->json('session.children'),
            fn (array $child): bool => $child['scope'] !== 'full_period',
        ), 'enrollment_id'));
        $this->assertNotContains($single->id, array_column($this->getJson("/api/v1/schedule/sessions/{$sessions['12']->id}")->json('session.children'), 'enrollment_id'));
        $this->assertNotContains($ending->id, array_column($this->getJson("/api/v1/schedule/sessions/{$sessions['19']->id}")->json('session.children'), 'enrollment_id'));

        // A cancelled session takes no places, and its panel still names whom it concerned.
        $this->inTenant($f['organization'], fn () => Session::query()->whereKey($sessions['12']->id)->update(['status' => 'cancelled']));
        $this->assertSame(0, collect($this->getJson(self::NOVEMBER."&group[]={$f['thursday']->id}")->json('sessions'))->firstWhere('id', $sessions['12']->id)['taken']);
        $this->assertSame(['Ася Георгиева' => 'active', 'Борис Йорданов' => 'paused', 'Жоро Иванов' => 'active', 'Цвети Стоева' => 'active'], $states($sessions['12']));
    }

    public function test_the_number_of_queries_does_not_grow_with_the_sessions(): void
    {
        $f = $this->rightsFixture();
        [$teacher, $person] = $this->teacherIn($f);
        $profile = $this->profileOf($f['organization'], $person);
        $colleague = $this->inTenant($f['organization'], fn (): InstructorProfile => InstructorProfile::query()->create(['public_name' => 'Ани Петрова']));
        /** @var Activity $danceActivity */
        [$dance, $danceActivity] = $this->inTenant($f['organization'], function () use ($f, $profile, $colleague): array {
            $activity = Activity::query()->create(['slug' => 'dance-'.Str::random(6), 'title' => 'Танци', 'category' => 'Спорт', 'summary' => 'Описание', 'age_label' => '1.–4. клас', 'status' => 'published']);
            $group = $activity->offerings()->create([])->groups()->create(['name' => 'Група 1', 'capacity_max' => 12, 'status' => 'published', 'location_id' => $f['location']->id]);
            $group->assignments()->attach($colleague->id, ['role' => 'lead', 'version' => 1]);
            $f['studio']->assignments()->attach($profile->id, ['role' => 'lead', 'version' => 1]);

            return [$group, $activity];
        });
        $add = function (int $from, int $count) use ($f, $dance, $profile, $colleague): void {
            $this->inTenant($f['organization'], function () use ($dance, $profile, $colleague, $from, $count): void {
                for ($index = $from; $index < $from + $count; $index++) {
                    $day = sprintf('2026-12-%02d', 1 + intdiv($index, 2));
                    $hour = $index % 2 === 0 ? '14' : '16';
                    $session = $dance->sessions()->create(['starts_at' => "{$day}T{$hour}:00:00Z", 'ends_at' => "{$day}T{$hour}:45:00Z", 'status' => 'scheduled']);
                    // Every other session the teacher stands in for the colleague.
                    $session->instructors()->attach($index % 2 === 0 ? $colleague->id : $profile->id, ['role' => 'lead']);
                }
            });
        };
        $add(0, 3);
        $child = $this->enrol($f, $dance, 'Ася Георгиева', 'full_period', null, 'active', '2026-11-01');
        $first = $this->inTenant($f['organization'], fn (): Session => Session::query()->where('group_id', $dance->id)->orderBy('starts_at')->firstOrFail());
        $this->enrol($f, $dance, 'Гала Тодорова', 'trial', $first);
        $this->inTenant($f['organization'], function () use ($child, $first): void {
            EnrollmentPause::query()->create(['enrollment_id' => $child->id, 'starts_on' => '2026-12-20', 'ends_on' => '2026-12-24', 'reason_category' => 'holiday']);
            EnrollmentSessionException::query()->create(['enrollment_id' => $child->id, 'session_id' => $first->id, 'kind' => 'excluded']);
        });
        // Attendance summaries must stay batched too: the sessions are now in the past.
        $this->travelTo('2026-12-02T18:00:00Z');
        $december = '/api/v1/schedule?from=2026-12-01&to=2026-12-31';
        $byChild = $december."&child={$child->child_id}&activity[]={$danceActivity->id}";

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $count = function (User $user, string $uri, int $sessions) use (&$queries, $f): int {
            $this->signInTo($user, $f['organization'])->getJson($uri)->assertOk();
            $queries = 0;
            $this->getJson($uri)->assertOk()->assertJsonCount($sessions, 'sessions');

            return $queries;
        };
        $owner = $count($f['owner'], $december, 3);
        $ownerByChild = $count($f['owner'], $byChild, 3);
        // The teacher: the sessions they stand in.
        $teaching = $count($teacher, $december, 1);

        $add(3, 27);

        $this->assertSame($owner, $count($f['owner'], $december, 30));
        $this->assertSame($ownerByChild, $count($f['owner'], $byChild, 30));
        $this->assertSame($teaching, $count($teacher, $december, 15));
    }

    public function test_reading_the_schedule_writes_nothing_to_the_audit_log(): void
    {
        $f = $this->rightsFixture();
        $before = DB::table('audit_events')->count();

        $this->signInTo($f['owner'], $f['organization'])->getJson(self::NOVEMBER."&child={$f['enrollment']->child_id}")->assertOk();
        $this->getJson("/api/v1/schedule/sessions/{$f['session']->id}")->assertOk()->assertJsonCount(2, 'session.children');

        $this->assertSame($before, DB::table('audit_events')->count());
    }

    /** @return list<string> */
    private function sessionIds(string $uri): array
    {
        return array_column($this->getJson($uri)->assertOk()->json('sessions'), 'id');
    }

    /**
     * A teacher with a login, a person of the team and a teacher profile, and no group yet.
     *
     * @param  array<string, mixed>  $f
     * @return array{User, StaffMember}
     */
    private function teacherIn(array $f): array
    {
        /** @var Organization $organization */
        $organization = $f['organization'];
        $teacher = $this->memberOf($organization, 'instructor');

        return [$teacher, $this->personFor($organization, $teacher, teaches: true)];
    }

    /** @param array<string, mixed> $f */
    private function addGrant(array $f, StaffMember $person, string $ability): void
    {
        $this->inTenant($f['organization'], fn () => StaffGrant::query()->create([
            'staff_member_id' => $person->id, 'ability' => $ability, 'granted_by' => $f['owner']->id, 'granted_at' => now()->subDay(),
        ]));
    }

    /**
     * The scope "Избрани групи" with these groups and activities, instead of any chosen before.
     *
     * @param  array<string, mixed>  $f
     * @param  list<string>  $groupIds
     * @param  list<string>  $activityIds
     */
    private function selectGroups(array $f, StaffMember $person, array $groupIds, array $activityIds = []): void
    {
        $this->inTenant($f['organization'], function () use ($f, $person, $groupIds, $activityIds): void {
            $person->forceFill(['access_scope' => 'groups'])->save();
            foreach ($groupIds as $groupId) {
                StaffScopeGroup::query()->create(['staff_member_id' => $person->id, 'group_id' => $groupId, 'added_by' => $f['owner']->id, 'added_at' => now()]);
            }
            foreach ($activityIds as $activityId) {
                StaffScopeGroup::query()->create(['staff_member_id' => $person->id, 'activity_id' => $activityId, 'added_by' => $f['owner']->id, 'added_at' => now()]);
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
     * A child of the owner in the group: for the period from a day, or for one session (a trial or a single visit).
     *
     * @param  array<string, mixed>  $f
     */
    private function enrol(array $f, Group $group, string $name, string $scope = 'full_period', ?Session $session = null, string $status = 'active', string $startsOn = '2026-11-01', ?string $endsOn = null): Enrollment
    {
        return $this->inTenant($f['organization'], function () use ($f, $group, $name, $scope, $session, $status, $startsOn, $endsOn): Enrollment {
            [$first, $last] = explode(' ', $name, 2);
            $child = $f['owner']->children()->create(['first_name' => $first, 'last_name' => $last, 'class_label' => '2 клас']);
            $application = EnrollmentApplication::query()->create([
                'parent_user_id' => $f['owner']->id, 'child_id' => $child->id, 'group_id' => $group->id, 'price_option_id' => $f['price']->id,
                'session_id' => $session?->id, 'scope' => $scope, 'source' => 'organization_catalog', 'status' => 'approved',
                'idempotency_key' => (string) Str::uuid(), 'accepted_terms_version' => 'v1', 'accepted_terms_at' => now(),
                'amount_cents_snapshot' => 4500, 'currency_snapshot' => 'EUR', 'unit_snapshot' => 'month',
            ]);

            return Enrollment::query()->create([
                'application_id' => $application->id, 'child_id' => $child->id, 'group_id' => $group->id, 'price_option_id' => $f['price']->id,
                'session_id' => $session?->id, 'scope' => $scope, 'status' => $status,
                'starts_on' => $session === null ? $startsOn : $session->starts_at->setTimezone('Europe/Sofia')->toDateString(), 'ends_on' => $endsOn,
            ]);
        });
    }
}
