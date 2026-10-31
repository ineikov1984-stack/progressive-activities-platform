<?php

namespace Tests\Feature\Enrollment;

use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\Session;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
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
 * The search of the filter "Дете" in the schedule (docs/product/schedule.md, т. 3.5): the children enrolled — not only
 * applied — in the groups where the person sees enrollments, by name, at most 20; the name and the class only. The
 * fixture (TeamRightsFixture) has Ани for the period and Вики for one visit on 3 November in "Шах · Вторник", Боби
 * waiting for it. Today is Monday 2 November 2026 in Sofia; 90 days back is 4 August. Every name is synthetic.
 */
class EnrolledChildSearchTest extends TestCase
{
    use RefreshDatabase;
    use TeamRightsFixture;

    private const SEARCH = '/api/v1/enrollment/children?q=';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        // Monday 2 November 2026, 12:00 in Sofia.
        $this->travelTo('2026-11-02 10:00:00');
    }

    public function test_who_does_not_see_enrollments_anywhere_may_not_search(): void
    {
        $f = $this->rightsFixture();
        $teacher = $this->memberOf($f['organization'], 'instructor');
        $person = $this->personFor($f['organization'], $teacher, teaches: true);
        $profile = $this->profileOf($f['organization'], $person);
        $this->inTenant($f['organization'], fn () => $f['tuesday']->assignments()->attach($profile->id, ['role' => 'lead', 'version' => 1]));
        // The whole schedule to look at is no right to the children.
        $this->addGrant($f, $person, 'schedule.view_all');
        $this->signInTo($teacher, $f['organization']);

        $this->getJson(self::SEARCH.urlencode('Ани'))->assertForbidden();
        // Refused before the text is checked.
        $this->getJson(self::SEARCH.urlencode('А'))->assertForbidden();
        $this->getJson('/api/v1/enrollment/children')->assertForbidden();
    }

    public function test_the_text_is_from_2_to_80_characters_and_has_no_wildcards(): void
    {
        $f = $this->rightsFixture();
        $this->signInTo($f['owner'], $f['organization']);

        $this->getJson('/api/v1/enrollment/children')->assertUnprocessable()->assertJsonValidationErrors('q')
            ->assertJsonPath('errors.q.0', 'Напишете поне 2 букви от името.');
        $this->getJson(self::SEARCH.urlencode('А'))->assertUnprocessable()->assertJsonValidationErrors('q')
            ->assertJsonPath('errors.q.0', 'Напишете поне 2 букви от името.');
        // The spaces around do not count.
        $this->getJson(self::SEARCH.urlencode('  А  '))->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->getJson(self::SEARCH.urlencode('   '))->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->getJson('/api/v1/enrollment/children?q[]=Ани')->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->getJson(self::SEARCH.urlencode(str_repeat('а', 81)))->assertUnprocessable()->assertJsonValidationErrors('q')
            ->assertJsonPath('errors.q.0', 'Напишете до 80 знака.');
        $this->getJson(self::SEARCH.urlencode(str_repeat('а', 80)))->assertOk()->assertJsonPath('children', []);
        // Two letters are enough; "%" and "_" are letters, not wildcards.
        $this->assertSame(['Ани Иванова'], $this->names('Ан'));
        $this->assertSame([], $this->names('%%'));
        $this->assertSame([], $this->names('__'));
    }

    public function test_an_enrolled_child_is_found_by_name_and_one_who_only_applied_is_not(): void
    {
        $f = $this->rightsFixture();
        // Цвети has only sent an application; Ани goes to "Четвъртък" too.
        $this->apply($f, $f['tuesday'], 'Цвети Петрова', 'submitted');
        $this->enrol($f, $f['thursday'], 'Mila Stone', classLabel: null);
        $again = $this->apply($f, $f['thursday'], null, 'approved', $f['enrollment']->child_id);
        $this->inTenant($f['organization'], fn () => Enrollment::query()->create([
            'application_id' => $again->id, 'child_id' => $again->child_id, 'group_id' => $f['thursday']->id, 'price_option_id' => $f['price']->id,
            'scope' => 'full_period', 'status' => 'active', 'starts_on' => '2026-11-05',
        ]));
        $before = DB::table('audit_events')->count();
        $this->signInTo($f['owner'], $f['organization']);

        // The identifier is the child's — the one "child" of the schedule takes; the name and the class only, once.
        $found = $this->getJson(self::SEARCH.urlencode('Ани'))->assertOk()
            ->assertJsonPath('children', [['id' => $f['enrollment']->child_id, 'name' => 'Ани Иванова', 'class' => '3 клас']]);
        $this->assertSame(['children'], array_keys($found->json()));
        $this->getJson("/api/v1/schedule?from=2026-11-02&to=2026-11-29&child={$found->json('children.0.id')}")->assertOk()
            ->assertJsonCount(8, 'sessions');
        // By the family name, by a part of the whole name; a single visit is an enrollment too, without a class.
        $this->assertSame(['Ани Иванова'], $this->names('Иванова'));
        $this->assertSame(['Ани Иванова'], $this->names('ни Ив'));
        $this->getJson(self::SEARCH.urlencode('Вики'))->assertOk()
            ->assertJsonPath('children', [['id' => $f['visit']->child_id, 'name' => 'Вики Петрова', 'class' => null]]);
        // Боби is waiting for a place and Цвети has applied: neither is enrolled.
        $this->assertSame(['Вики Петрова'], $this->names('Петров'));
        $this->assertSame([], $this->names('Боби'));
        // Capitals or not (on PostgreSQL also for Cyrillic, with ILIKE).
        $this->assertSame(['Mila Stone'], $this->names('mILA'));

        // Searching writes nothing to the audit log.
        $this->assertSame($before, DB::table('audit_events')->count());
    }

    public function test_an_enrollment_that_holds_or_ended_in_the_last_90_days_counts(): void
    {
        $f = $this->rightsFixture();
        [$old, $recent] = $this->inTenant($f['organization'], fn (): array => [
            $f['tuesday']->sessions()->create(['starts_at' => '2026-07-21T15:00:00Z', 'ends_at' => '2026-07-21T16:00:00Z', 'status' => 'scheduled']),
            $f['tuesday']->sessions()->create(['starts_at' => '2026-10-20T15:00:00Z', 'ends_at' => '2026-10-20T16:00:00Z', 'status' => 'scheduled']),
        ]);
        $this->enrol($f, $f['tuesday'], 'Асен Колев', status: 'paused');
        $this->enrol($f, $f['tuesday'], 'Борис Колев', status: 'ended', startsOn: '2026-03-01', endsOn: '2026-08-04');
        $this->enrol($f, $f['tuesday'], 'Васил Колев', status: 'ended', startsOn: '2026-03-01', endsOn: '2026-08-03');
        $this->enrol($f, $f['tuesday'], 'Георги Колев', status: 'cancelled');
        $this->enrol($f, $f['tuesday'], 'Дамян Колев', startsOn: '2026-12-01');
        // A visit ends on its day.
        $this->enrol($f, $f['tuesday'], 'Евгени Колев', scope: 'trial', status: 'ended', session: $old);
        $this->enrol($f, $f['tuesday'], 'Живко Колев', scope: 'single', status: 'ended', session: $recent);
        $this->signInTo($f['owner'], $f['organization']);

        $this->assertSame(['Асен Колев', 'Борис Колев', 'Дамян Колев', 'Живко Колев'], $this->names('Колев'));
    }

    public function test_a_child_in_a_group_without_seeing_its_enrollments_is_not_found(): void
    {
        $f = $this->rightsFixture();
        $daniel = $this->enrol($f, $f['thursday'], 'Дани Колев');
        // The school partner: the whole schedule's groups are no matter, only the selected one.
        $partner = $this->memberOf($f['organization'], 'viewer');
        $this->selectGroups($f, $this->personFor($f['organization'], $partner), [$f['tuesday']->id]);
        $this->signInTo($partner, $f['organization']);

        // "ни": Ани in "Вторник", not Дани in "Четвъртък".
        $this->assertSame(['Ани Иванова'], $this->names('ни'));
        $this->assertSame([], $this->names('Дани'));
        $this->signInTo($f['owner'], $f['organization']);
        $this->assertSame(['Ани Иванова', 'Дани Колев'], $this->names('ни'));

        // A teacher with "Записва деца" sees the enrollments of their own group only.
        $teacher = $this->memberOf($f['organization'], 'instructor');
        $person = $this->personFor($f['organization'], $teacher, teaches: true);
        $profile = $this->profileOf($f['organization'], $person);
        $this->inTenant($f['organization'], fn () => $f['thursday']->assignments()->attach($profile->id, ['role' => 'lead', 'version' => 1]));
        $this->addGrant($f, $person, 'enrollment.enroll');
        $this->signInTo($teacher, $f['organization']);

        $this->getJson(self::SEARCH.urlencode('Дани'))->assertOk()
            ->assertJsonPath('children', [['id' => $daniel->child_id, 'name' => 'Дани Колев', 'class' => '2 клас']]);
        $this->assertSame([], $this->names('Ани'));
    }

    #[TestGroup('tenant-isolation')]
    public function test_another_organizations_children_are_not_found(): void
    {
        $f = $this->rightsFixture();
        $other = $this->rightsFixture();
        $this->enrol($other, $other['tuesday'], 'Зара Чуждова');
        $this->signInTo($f['owner'], $f['organization']);

        $this->assertSame([], $this->names('Зара'));
        // Each organization has its own "Ани Иванова": only this one's.
        $this->assertSame([$f['enrollment']->child_id], array_column($this->getJson(self::SEARCH.urlencode('Ани'))->assertOk()->json('children'), 'id'));
        // An organization the user is not a member of is not found.
        $this->signInTo($f['owner'], $other['organization'])->getJson(self::SEARCH.urlencode('Зара'))->assertNotFound();

        // The other way round.
        $this->signInTo($other['owner'], $other['organization']);
        $this->assertSame(['Зара Чуждова'], $this->names('Зара'));
        $this->assertSame([$other['enrollment']->child_id], array_column($this->getJson(self::SEARCH.urlencode('Ани'))->assertOk()->json('children'), 'id'));
    }

    public function test_at_most_20_children_by_name(): void
    {
        $f = $this->rightsFixture();
        foreach (range(25, 1) as $number) {
            $this->enrol($f, $number % 2 === 0 ? $f['tuesday'] : $f['studio'], sprintf('Тест %02d', $number));
        }
        $this->signInTo($f['owner'], $f['organization']);

        $this->assertSame(array_map(fn (int $number): string => sprintf('Тест %02d', $number), range(1, 20)), $this->names('Тест'));
    }

    /** @return list<string> */
    private function names(string $text): array
    {
        return array_column($this->getJson(self::SEARCH.urlencode($text))->assertOk()->json('children'), 'name');
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

    /**
     * An application of the owner for a child in the group: a new child by name, or one that exists.
     *
     * @param  array<string, mixed>  $f
     */
    private function apply(array $f, Group $group, ?string $name, string $status, ?string $childId = null, ?Session $session = null, string $scope = 'full_period', ?string $classLabel = '2 клас'): EnrollmentApplication
    {
        /** @var Organization $organization */
        $organization = $f['organization'];
        /** @var User $owner */
        $owner = $f['owner'];

        return $this->inTenant($organization, function () use ($f, $owner, $group, $name, $status, $childId, $session, $scope, $classLabel): EnrollmentApplication {
            if ($childId === null) {
                [$first, $last] = explode(' ', (string) $name, 2);
                $childId = $owner->children()->create(['first_name' => $first, 'last_name' => $last, 'class_label' => $classLabel])->id;
            }

            return EnrollmentApplication::query()->create([
                'parent_user_id' => $owner->id, 'child_id' => $childId, 'group_id' => $group->id, 'price_option_id' => $f['price']->id,
                'session_id' => $session?->id, 'scope' => $scope, 'source' => 'organization_catalog', 'status' => $status,
                'idempotency_key' => (string) Str::uuid(), 'accepted_terms_version' => 'v1', 'accepted_terms_at' => now(),
                'amount_cents_snapshot' => 4500, 'currency_snapshot' => 'EUR', 'unit_snapshot' => 'month',
            ]);
        });
    }

    /**
     * A new child of the owner enrolled in the group: for the period from a day, or for one session (a trial or a single
     * visit) on its day.
     *
     * @param  array<string, mixed>  $f
     */
    private function enrol(array $f, Group $group, string $name, string $scope = 'full_period', ?Session $session = null, string $status = 'active', string $startsOn = '2026-11-01', ?string $endsOn = null, ?string $classLabel = '2 клас'): Enrollment
    {
        $application = $this->apply($f, $group, $name, 'approved', null, $session, $scope, $classLabel);

        return $this->inTenant($f['organization'], fn (): Enrollment => Enrollment::query()->create([
            'application_id' => $application->id, 'child_id' => $application->child_id, 'group_id' => $group->id, 'price_option_id' => $f['price']->id,
            'session_id' => $session?->id, 'scope' => $scope, 'status' => $status,
            'starts_on' => $session === null ? $startsOn : $session->starts_at->setTimezone('Europe/Sofia')->toDateString(), 'ends_on' => $endsOn,
        ]));
    }
}
