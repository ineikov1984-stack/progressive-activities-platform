<?php

namespace Tests\Feature\Enrollment;

use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Catalog\Models\Offering;
use App\Domain\Catalog\Models\PriceOption;
use App\Domain\Catalog\Models\Session;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Models\StaffMember;
use App\Domain\Organizations\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Tests\TestCase;

/**
 * Б2в: a teacher sees only their own groups and, in them, only what they need about each child; "Моите групи" for every
 * role with a teacher profile (docs/product/team.md, т. 8).
 */
class TeacherOwnGroupsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        // Monday 2 November 2026, 12:00 in Sofia.
        $this->travelTo('2026-11-02 10:00:00');
    }

    public function test_an_assignment_gives_the_group_until_its_last_day(): void
    {
        $f = $this->fixture();
        [$teacher, $profile] = $this->teacher($f['organization']);
        $this->assignTeacher($f, $profile, $f['tuesday']);
        // An assignment that starts later gives the group already.
        $this->assignTeacher($f, $profile, $f['studio'], ['role' => 'assistant', 'starts_on' => '2026-11-18']);

        $this->signIn($teacher, $f['organization'])->getJson('/api/v1/enrollment/groups')
            ->assertOk()->assertJsonCount(2, 'groups')
            ->assertJsonPath('groups.0.id', $f['studio']->id)
            ->assertJsonPath('groups.1.id', $f['tuesday']->id)
            ->assertJsonPath('groups.1.enrolled_for_period', 1);
        $row = $this->getJson('/api/v1/enrollment/groups')->json('groups.1');
        $this->assertSame(['id', 'name', 'status', 'capacity_max', 'activity_id', 'activity_title', 'activity_category', 'schedule', 'location_name', 'enrolled_for_period'], array_keys($row));
        $this->getJson("/api/v1/enrollment/groups/{$f['tuesday']->id}/roster")->assertOk()->assertJsonCount(1, 'enrollments');
        $this->getJson("/api/v1/enrollment/groups/{$f['studio']->id}/roster")->assertOk();

        // The assignment ends on Monday 9 November: that day it still counts, the next day it does not.
        $this->signIn($f['owner'], $f['organization'])
            ->postJson("/api/v1/catalog/groups/{$f['tuesday']->id}/assignments/{$profile->id}/end", ['ends_on' => '2026-11-09', 'version' => 1])
            ->assertOk();
        $this->travelTo('2026-11-09 21:00:00');
        $this->signIn($teacher, $f['organization'])->getJson("/api/v1/enrollment/groups/{$f['tuesday']->id}/roster")->assertOk();
        // 22:30 UTC on 9 November is already 10 November in Sofia.
        $this->travelTo('2026-11-09 22:30:00');
        $this->getJson("/api/v1/enrollment/groups/{$f['tuesday']->id}/roster")->assertForbidden();
        $this->travelTo('2026-11-10 10:00:00');
        $this->getJson("/api/v1/enrollment/groups/{$f['tuesday']->id}/roster")
            ->assertForbidden()->assertJsonPath('message', 'Нямате достъп до тази група.');
        $this->getJson('/api/v1/enrollment/groups')->assertOk()->assertJsonCount(1, 'groups')->assertJsonPath('groups.0.id', $f['studio']->id);
    }

    public function test_a_substitute_keeps_the_group_until_the_end_of_the_day_after_the_session(): void
    {
        $f = $this->fixture();
        [$teacher, $profile] = $this->teacher($f['organization']);
        $this->withTenant($f['organization'], function () use ($f, $profile): void {
            // Changed by hand: the teacher stands in on Thursday 12 November.
            $session = $this->sessionOn($f['thursday'], '2026-11-12');
            $session->instructors()->attach($profile->id, ['role' => 'assistant']);
            $session->forceFill(['instructors_source' => 'manual'])->save();
            // A cancelled session is not taught.
            $cancelled = $this->sessionOn($f['studio'], '2026-11-11');
            $cancelled->instructors()->attach($profile->id, ['role' => 'lead']);
            $cancelled->forceFill(['status' => 'cancelled', 'instructors_source' => 'manual'])->save();
        });

        // Ten days before: the future substitution is in My groups, but its children are not available.
        $this->signIn($teacher, $f['organization'])->getJson('/api/v1/enrollment/groups')
            ->assertOk()->assertJsonCount(0, 'groups');
        $this->getJson("/api/v1/enrollment/groups/{$f['thursday']->id}/roster")->assertForbidden();
        $this->getJson("/api/v1/enrollment/groups/{$f['studio']->id}/roster")->assertForbidden();
        $this->getJson('/api/v1/me/teaching')->assertOk()->assertExactJson(['groups' => [[
            'group_id' => $f['thursday']->id, 'group_name' => 'Четвъртък', 'activity_id' => $f['chessActivityId'],
            'activity_title' => 'Шах', 'label' => 'Шах · Четвъртък', 'basis' => 'substitute', 'role' => 'assistant',
            'starts_on' => '2026-11-12', 'ends_on' => '2026-11-12', 'next_session_starts_at' => '2026-11-12T15:00:00+00:00',
        ]]]);

        // The session's day opens the roster at midnight in Sofia.
        $this->travelTo('2026-11-11 22:00:00');
        $this->getJson('/api/v1/enrollment/groups')->assertOk()->assertJsonCount(1, 'groups');
        $this->getJson("/api/v1/enrollment/groups/{$f['thursday']->id}/roster")->assertOk();

        // Friday 13 November, 23:59 in Sofia: the session was yesterday, the attendance can still be corrected.
        $this->travelTo('2026-11-13 21:59:00');
        $this->getJson("/api/v1/enrollment/groups/{$f['thursday']->id}/roster")->assertOk();
        $this->getJson('/api/v1/me/teaching')->assertOk()->assertJsonCount(1, 'groups')->assertJsonPath('groups.0.next_session_starts_at', null);
        // Saturday 14 November, 00:01 in Sofia: the session was the day before yesterday.
        $this->travelTo('2026-11-13 22:01:00');
        $this->getJson("/api/v1/enrollment/groups/{$f['thursday']->id}/roster")->assertForbidden();
        $this->getJson('/api/v1/enrollment/groups')->assertOk()->assertJsonCount(0, 'groups');
        $this->getJson('/api/v1/me/teaching')->assertOk()->assertExactJson(['groups' => []]);
    }

    #[DataProvider('substituteMidnights')]
    public function test_the_substitution_window_uses_calendar_days_across_dst(string $start, string $before, string $opens, string $last, string $after): void
    {
        $f = $this->fixture();
        [$teacher, $profile] = $this->teacher($f['organization']);
        $this->withTenant($f['organization'], function () use ($f, $profile, $start): void {
            $session = $f['thursday']->sessions()->create(['starts_at' => $start, 'ends_at' => CarbonImmutable::parse($start)->addHour(), 'status' => 'scheduled', 'instructors_source' => 'manual']);
            $session->instructors()->attach($profile->id, ['role' => 'assistant']);
        });
        $this->signIn($teacher, $f['organization']);
        foreach ([[$before, 403], [$opens, 200], [$last, 200], [$after, 403]] as [$instant, $status]) {
            $this->travelTo($instant);
            $this->getJson("/api/v1/enrollment/groups/{$f['thursday']->id}/roster")->assertStatus($status);
        }
    }

    public static function substituteMidnights(): array
    {
        return [
            'spring' => ['2026-03-29T12:00:00Z', '2026-03-28 21:59:59', '2026-03-28 22:00:00', '2026-03-30 20:59:59', '2026-03-30 21:00:00'],
            'autumn' => ['2026-10-25T12:00:00Z', '2026-10-24 20:59:59', '2026-10-24 21:00:00', '2026-10-26 21:59:59', '2026-10-26 22:00:00'],
        ];
    }

    public function test_the_lead_of_an_offering_sees_all_its_groups_and_an_assignment_tells_the_role(): void
    {
        $f = $this->fixture();
        [$teacher, $profile] = $this->teacher($f['organization']);
        $this->withTenant($f['organization'], fn () => Offering::query()->whereKey($f['chess']->id)->update(['lead_instructor_profile_id' => $profile->id]));
        $this->assignTeacher($f, $profile, $f['thursday'], ['role' => 'assistant']);

        $this->signIn($teacher, $f['organization'])->getJson('/api/v1/enrollment/groups')
            ->assertOk()->assertJsonCount(2, 'groups')
            ->assertJsonPath('groups.0.id', $f['tuesday']->id)
            ->assertJsonPath('groups.1.id', $f['thursday']->id);
        $this->getJson("/api/v1/enrollment/groups/{$f['tuesday']->id}/roster")->assertOk();
        $this->getJson("/api/v1/enrollment/groups/{$f['studio']->id}/roster")->assertForbidden();
        $this->getJson('/api/v1/me/teaching')->assertOk()->assertJsonCount(2, 'groups')
            ->assertJsonPath('groups.0.label', 'Шах · Вторник')
            ->assertJsonPath('groups.0.basis', 'offering_lead')
            ->assertJsonPath('groups.0.role', 'lead')
            ->assertJsonPath('groups.0.starts_on', null)
            ->assertJsonPath('groups.0.next_session_starts_at', '2026-11-03T15:00:00+00:00')
            ->assertJsonPath('groups.1.label', 'Шах · Четвъртък')
            ->assertJsonPath('groups.1.basis', 'assignment')
            ->assertJsonPath('groups.1.role', 'assistant')
            ->assertJsonPath('groups.1.next_session_starts_at', '2026-11-05T15:00:00+00:00');
    }

    public function test_the_teachers_roster_has_only_names_classes_dates_pauses_and_skipped_sessions(): void
    {
        $f = $this->fixture();
        [$teacher, $profile] = $this->teacher($f['organization']);
        $this->assignTeacher($f, $profile, $f['tuesday']);
        $nov24 = $this->withTenant($f['organization'], fn (): Session => $this->sessionOn($f['tuesday'], '2026-11-24'));
        $base = "/api/v1/enrollment/enrollments/{$f['enrollment']->id}";
        $this->postJson("{$base}/pauses", ['starts_on' => '2026-11-16', 'ends_on' => '2026-11-18', 'reason_category' => 'health', 'note' => 'Болест', 'version' => 1])->assertCreated();
        $this->postJson("{$base}/skipped-sessions", ['session_id' => $nov24->id, 'reason' => 'Състезание', 'version' => 2])->assertOk();

        $roster = $this->signIn($teacher, $f['organization'])->getJson("/api/v1/enrollment/groups/{$f['tuesday']->id}/roster")->assertOk()->json();

        $this->assertSame(['group', 'enrollments'], array_keys($roster));
        $this->assertSame(['id' => $f['tuesday']->id, 'name' => 'Вторник', 'activity_id' => $f['chessActivityId'], 'activity_title' => 'Шах'], $roster['group']);
        $this->assertCount(1, $roster['enrollments']);
        $this->assertSame([
            'id' => $f['enrollment']->id,
            'child' => ['name' => 'Ани Иванова', 'class_label' => '3 клас'],
            'starts_on' => '2026-10-20',
            'ends_on' => null,
            'paused_today' => false,
            'pauses' => [['starts_on' => '2026-11-16', 'ends_on' => '2026-11-18']],
            'skipped_sessions' => [['session_id' => $nov24->id, 'starts_at' => '2026-11-24T15:00:00+00:00']],
        ], $roster['enrollments'][0]);
        $text = (string) json_encode($roster, JSON_UNESCAPED_UNICODE);
        foreach (['Мария', '@', '+359', 'Боби', 'Болест', 'Състезание', 'health', 'Плаща в брой', 'Алергия', 'Вазов', 'EUR'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }

        // During the pause the teacher sees it as such.
        $this->travelTo('2026-11-17 10:00:00');
        $this->getJson("/api/v1/enrollment/groups/{$f['tuesday']->id}/roster")->assertOk()->assertJsonPath('enrollments.0.paused_today', true);
    }

    public function test_a_teacher_is_refused_every_enrollment_route_that_is_not_for_teachers(): void
    {
        $f = $this->fixture();
        [$teacher, $profile] = $this->teacher($f['organization']);
        // Even in the teacher's own group.
        $this->assignTeacher($f, $profile, $f['tuesday']);
        $session = $this->withTenant($f['organization'], fn (): Session => $this->sessionOn($f['tuesday'], '2026-11-10'));
        $base = "/api/v1/enrollment/enrollments/{$f['enrollment']->id}";
        $this->signIn($teacher, $f['organization']);

        $this->getJson('/api/v1/enrollment/applications')->assertForbidden();
        $this->getJson('/api/v1/enrollment/applications?status=waitlisted')->assertForbidden();
        $this->getJson("/api/v1/enrollment/applications/{$f['waiting']->id}")->assertForbidden();
        $this->getJson($base)->assertForbidden();
        $this->getJson("{$base}/transfer-options")->assertForbidden();
        $this->postJson("/api/v1/enrollment/applications/{$f['waiting']->id}/review", ['decision' => 'approve', 'version' => 1])->assertForbidden();
        $this->patchJson("/api/v1/enrollment/groups/{$f['tuesday']->id}/approval", ['enrollment_approval_mode' => 'automatic'])->assertForbidden();
        $this->postJson("{$base}/transfer", [
            'target_group_id' => $f['thursday']->id, 'price_option_id' => $f['price']->id, 'effective_date' => '2026-11-12', 'reason' => 'Смяна', 'version' => 1,
        ])->assertForbidden();
        $this->postJson("{$base}/pauses", ['starts_on' => '2026-11-16', 'ends_on' => '2026-11-18', 'reason_category' => 'health', 'version' => 1])->assertForbidden();
        $this->postJson("{$base}/pauses/".Str::uuid().'/end', ['resume_on' => '2026-11-17', 'version' => 1])->assertForbidden();
        $this->postJson("{$base}/end", ['last_day' => '2026-11-30', 'reason' => 'Край', 'version' => 1])->assertForbidden();
        $this->postJson("{$base}/reopen", ['version' => 1])->assertForbidden();
        $this->postJson("{$base}/skipped-sessions", ['session_id' => $session->id, 'reason' => 'Състезание', 'version' => 1])->assertForbidden();
        $this->deleteJson("{$base}/skipped-sessions/{$session->id}?version=1")->assertForbidden();
        // Marking how a visit went is not for a teacher yet (the roles are listed in RecordVisitOutcome); a month's
        // enrollment has no visit, so the refusal may come as "not found" first.
        $this->assertContains($this->postJson("/api/v1/enrollments/{$f['enrollment']->id}/visit-outcome", ['outcome' => 'attended'])->status(), [403, 404]);
        // No page "Екип" for a teacher (т. 4).
        $this->getJson('/api/v1/team')->assertForbidden();

        $this->withTenant($f['organization'], function () use ($f): void {
            $this->assertSame(1, Enrollment::query()->findOrFail($f['enrollment']->id)->version);
            $this->assertSame('waitlisted', EnrollmentApplication::query()->findOrFail($f['waiting']->id)->status->value);
            $this->assertSame('manual', Group::query()->findOrFail($f['tuesday']->id)->enrollment_approval_mode);
        });
    }

    #[TestGroup('tenant-isolation')]
    public function test_another_organizations_groups_are_not_found_even_for_a_teacher_of_both(): void
    {
        $f = $this->fixture();
        $foreign = $this->fixture();
        [$teacher, $profile] = $this->teacher($f['organization']);
        $this->assignTeacher($f, $profile, $f['tuesday']);
        // The same person teaches in the other organization too, with a profile there.
        $this->withTenant($foreign['organization'], function () use ($foreign, $teacher): void {
            OrganizationMembership::query()->withoutGlobalScopes()->create([
                'organization_id' => $foreign['organization']->id, 'user_id' => $teacher->id, 'role' => 'instructor', 'status' => 'active',
            ]);
            $person = StaffMember::query()->create(['full_name' => 'Учител', 'user_id' => $teacher->id]);
            $there = InstructorProfile::query()->create(['public_name' => 'Учител', 'user_id' => $teacher->id]);
            $there->forceFill(['staff_member_id' => $person->id])->save();
            $foreign['thursday']->assignments()->attach($there->id, ['role' => 'lead']);
            Offering::query()->whereKey($foreign['chess']->id)->update(['lead_instructor_profile_id' => $there->id]);
            // And an old profile there, without a person, that still names the user.
            $old = InstructorProfile::query()->create(['public_name' => 'Учител (стар)', 'user_id' => $teacher->id]);
            $foreign['tuesday']->assignments()->attach($old->id, ['role' => 'assistant']);
        });

        $this->signIn($teacher, $f['organization']);
        $this->getJson("/api/v1/enrollment/groups/{$foreign['thursday']->id}/roster")->assertNotFound();
        $this->getJson("/api/v1/enrollment/groups/{$foreign['tuesday']->id}/roster")->assertNotFound();
        $this->getJson('/api/v1/enrollment/groups')->assertOk()->assertJsonCount(1, 'groups')->assertJsonPath('groups.0.id', $f['tuesday']->id);
        $this->getJson('/api/v1/me/teaching')->assertOk()->assertJsonCount(1, 'groups')->assertJsonPath('groups.0.group_id', $f['tuesday']->id);

        // In the other organization: its own groups only.
        $this->signIn($teacher, $foreign['organization']);
        $this->getJson("/api/v1/enrollment/groups/{$f['tuesday']->id}/roster")->assertNotFound();
        $this->getJson("/api/v1/enrollment/groups/{$foreign['thursday']->id}/roster")->assertOk();
        $this->getJson('/api/v1/me/teaching')->assertOk()->assertJsonCount(2, 'groups')->assertJsonMissing(['group_id' => $f['tuesday']->id]);

        // An organization the person is not a member of.
        $stranger = $this->fixture();
        $this->signIn($teacher, $stranger['organization']);
        $this->getJson('/api/v1/me/teaching')->assertNotFound();
        $this->getJson("/api/v1/enrollment/groups/{$stranger['tuesday']->id}/roster")->assertNotFound();
    }

    public function test_my_teaching_lists_an_owner_who_teaches_and_is_empty_without_a_profile(): void
    {
        $f = $this->fixture();
        $ownerProfile = $this->profileFor($f['organization'], $f['owner']);
        $this->assignTeacher($f, $ownerProfile, $f['studio'], ['role' => 'assistant', 'starts_on' => '2026-11-10', 'ends_on' => '2026-12-20']);

        $this->signIn($f['owner'], $f['organization'])->getJson('/api/v1/me/teaching')->assertOk()->assertExactJson(['groups' => [[
            'group_id' => $f['studio']->id, 'group_name' => 'Ателие', 'activity_id' => $f['paintingActivityId'],
            'activity_title' => 'Рисуване', 'label' => 'Рисуване · Ателие', 'basis' => 'assignment', 'role' => 'assistant',
            'starts_on' => '2026-11-10', 'ends_on' => '2026-12-20', 'next_session_starts_at' => '2026-11-11T15:00:00+00:00',
        ]]]);
        // The owner still sees every group, as before.
        $this->getJson('/api/v1/enrollment/groups')->assertOk()->assertJsonCount(3, 'groups');

        $viewer = $this->member($f['organization'], 'viewer');
        $this->signIn($viewer, $f['organization'])->getJson('/api/v1/me/teaching')->assertOk()->assertExactJson(['groups' => []]);
        [$teacherWithoutGroups] = $this->teacher($f['organization']);
        $this->signIn($teacherWithoutGroups, $f['organization'])->getJson('/api/v1/me/teaching')->assertOk()->assertExactJson(['groups' => []]);
        $this->getJson('/api/v1/enrollment/groups')->assertOk()->assertExactJson(['groups' => []]);
    }

    public function test_an_old_profile_counts_only_while_it_is_not_linked_to_another_person(): void
    {
        $f = $this->fixture();
        $teacher = $this->member($f['organization'], 'instructor');
        [$old, $someoneElses] = $this->withTenant($f['organization'], function () use ($teacher): array {
            // Before the team: a profile that names the user (docs/product/team.md, т. 3).
            $old = InstructorProfile::query()->create(['public_name' => 'Стар профил', 'user_id' => $teacher->id]);
            // A profile of another person of the team that still names the user.
            $person = StaffMember::query()->create(['full_name' => 'Друг човек']);
            $someoneElses = InstructorProfile::query()->create(['public_name' => 'Друг човек', 'user_id' => $teacher->id]);
            $someoneElses->forceFill(['staff_member_id' => $person->id])->save();

            return [$old, $someoneElses];
        });
        $this->assignTeacher($f, $old, $f['thursday']);
        $this->assignTeacher($f, $someoneElses, $f['studio']);

        $this->signIn($teacher, $f['organization'])->getJson('/api/v1/enrollment/groups')
            ->assertOk()->assertJsonCount(1, 'groups')->assertJsonPath('groups.0.id', $f['thursday']->id);
        $this->getJson("/api/v1/enrollment/groups/{$f['studio']->id}/roster")->assertForbidden();

        // An archived person of the team teaches nothing.
        $archived = $this->member($f['organization'], 'instructor');
        $profile = $this->profileFor($f['organization'], $archived);
        $this->assignTeacher($f, $profile, $f['tuesday']);
        $this->withTenant($f['organization'], fn () => StaffMember::query()->where('user_id', $archived->id)->update(['archived_at' => now()]));
        $this->signIn($archived, $f['organization'])->getJson('/api/v1/enrollment/groups')->assertOk()->assertJsonCount(0, 'groups');
    }

    /** @return iterable<string, array{string}> */
    public static function staffRoles(): iterable
    {
        yield 'platform administrator' => ['platform_admin'];
        yield 'owner' => ['organization_owner'];
        yield 'administrator' => ['organization_admin'];
        yield 'coordinator' => ['coordinator'];
        yield 'viewer' => ['viewer'];
        yield 'finance' => ['finance'];
    }

    #[DataProvider('staffRoles')]
    public function test_staff_roles_still_see_every_group_and_the_teams_roster(string $role): void
    {
        $f = $this->fixture();
        // A profile assigned elsewhere changes nothing for a role that sees all groups.
        $member = $this->member($f['organization'], $role);
        $this->assignTeacher($f, $this->profileFor($f['organization'], $member), $f['studio']);

        $this->signIn($member, $f['organization'])->getJson('/api/v1/enrollment/groups')
            ->assertOk()->assertJsonCount(3, 'groups')
            ->assertJsonPath('groups.1.id', $f['tuesday']->id)
            ->assertJsonPath('groups.1.waitlist_count', 1)
            ->assertJsonPath('groups.1.enrollment_approval_mode', 'manual');
        $this->getJson("/api/v1/enrollment/groups/{$f['thursday']->id}/roster")->assertOk();
        $roster = $this->getJson("/api/v1/enrollment/groups/{$f['tuesday']->id}/roster")
            ->assertOk()->assertJsonCount(1, 'waitlist')->assertJsonPath('enrollments.0.version', 1)->json();
        $this->assertSame(['group', 'enrollments', 'waitlist', 'upcoming_sessions'], array_keys($roster));
        $this->assertArrayHasKey('remaining', $roster['group']);
        $this->assertSame(['id', 'name', 'class_label'], array_keys($roster['enrollments'][0]['child']));
    }

    /**
     * Two groups of "Шах" (one offering) and one of "Рисуване", sessions at 17:00 in Sofia (15:00 UTC): Tuesdays 3–24,
     * Wednesdays 4–25 and Thursdays 5–26 November. A child enrolled in "Вторник", another one waiting there.
     *
     * @return array{organization: Organization, owner: User, chess: Offering, chessActivityId: string, paintingActivityId: string, tuesday: Group, thursday: Group, studio: Group, price: PriceOption, enrollment: Enrollment, waiting: EnrollmentApplication}
     */
    private function fixture(): array
    {
        $organization = Organization::query()->withoutGlobalScopes()->create(['name' => 'Школа', 'slug' => 'school-'.Str::random(12), 'status' => 'active']);
        $owner = $this->member($organization, 'organization_owner');

        return $this->withTenant($organization, function () use ($organization, $owner): array {
            $chessActivity = Activity::query()->create([
                'slug' => 'chess-'.Str::random(8), 'title' => 'Шах', 'category' => 'Наука', 'summary' => 'Описание', 'age_label' => '2.–4. клас', 'status' => 'published',
            ]);
            $chess = $chessActivity->offerings()->create([]);
            $tuesday = $chess->groups()->create(['name' => 'Вторник', 'capacity_max' => 10, 'status' => 'published', 'waitlist_enabled' => true, 'enrollment_approval_mode' => 'manual']);
            $thursday = $chess->groups()->create(['name' => 'Четвъртък', 'capacity_max' => 10, 'status' => 'published', 'waitlist_enabled' => true, 'enrollment_approval_mode' => 'manual']);
            $paintingActivity = Activity::query()->create([
                'slug' => 'painting-'.Str::random(8), 'title' => 'Рисуване', 'category' => 'Изкуство', 'summary' => 'Описание', 'age_label' => '1.–4. клас', 'status' => 'published',
            ]);
            $studio = $paintingActivity->offerings()->create([])->groups()->create(['name' => 'Ателие', 'capacity_max' => 8, 'status' => 'published', 'enrollment_approval_mode' => 'manual']);
            foreach ([[$tuesday, ['03', '10', '17', '24']], [$studio, ['04', '11', '18', '25']], [$thursday, ['05', '12', '19', '26']]] as [$group, $days]) {
                foreach ($days as $day) {
                    $group->sessions()->create(['starts_at' => "2026-11-{$day}T15:00:00Z", 'ends_at' => "2026-11-{$day}T16:00:00Z", 'status' => 'scheduled']);
                }
            }
            $price = $chess->priceOptions()->create(['group_id' => $tuesday->id, 'enrollment_mode' => 'monthly', 'unit' => 'month', 'amount_cents' => 4500, 'currency' => 'EUR', 'is_available' => true]);

            // The factory gives every user an e-mail and a phone (+359…): the teacher's roster has neither.
            $parent = User::factory()->create(['name' => 'Мария Иванова']);
            $child = $parent->children()->create(['first_name' => 'Ани', 'last_name' => 'Иванова', 'class_label' => '3 клас', 'school_name' => 'СУ „Иван Вазов“']);
            $application = $this->application($parent, $child->id, $tuesday->id, $price->id, 'approved');
            $enrollment = Enrollment::query()->create([
                'application_id' => $application->id, 'child_id' => $child->id, 'group_id' => $tuesday->id,
                'price_option_id' => $price->id, 'scope' => 'full_period', 'status' => 'active', 'starts_on' => '2026-10-20',
            ]);
            $otherParent = User::factory()->create(['name' => 'Петя Петрова']);
            $otherChild = $otherParent->children()->create(['first_name' => 'Боби', 'last_name' => 'Петров']);
            $waiting = $this->application($otherParent, $otherChild->id, $tuesday->id, $price->id, 'waitlisted');

            $chessActivityId = $chessActivity->id;
            $paintingActivityId = $paintingActivity->id;

            return compact('organization', 'owner', 'chess', 'chessActivityId', 'paintingActivityId', 'tuesday', 'thursday', 'studio', 'price', 'enrollment', 'waiting');
        });
    }

    private function application(User $parent, string $childId, string $groupId, string $priceId, string $status): EnrollmentApplication
    {
        return EnrollmentApplication::query()->create([
            'parent_user_id' => $parent->id, 'child_id' => $childId, 'group_id' => $groupId, 'price_option_id' => $priceId,
            'scope' => 'full_period', 'source' => 'organization_catalog', 'status' => $status, 'idempotency_key' => fake()->uuid(),
            'accepted_terms_version' => 'v1', 'accepted_terms_at' => now(), 'amount_cents_snapshot' => 4500, 'currency_snapshot' => 'EUR',
            'unit_snapshot' => 'month', 'internal_note' => 'Плаща в брой', 'parent_note' => 'Алергия към ядки',
        ]);
    }

    /**
     * A member with the role, a person of the team and a teacher profile linked to that person.
     *
     * @return array{User, InstructorProfile}
     */
    private function teacher(Organization $organization, string $role = 'instructor'): array
    {
        $user = $this->member($organization, $role);

        return [$user, $this->profileFor($organization, $user)];
    }

    private function profileFor(Organization $organization, User $user): InstructorProfile
    {
        return $this->withTenant($organization, function () use ($user): InstructorProfile {
            $person = StaffMember::query()->create(['full_name' => $user->name, 'user_id' => $user->id]);
            $profile = InstructorProfile::query()->create(['public_name' => $user->name]);
            $profile->forceFill(['staff_member_id' => $person->id])->save();

            return $profile;
        });
    }

    /**
     * Through the API, as the owner: the assignment's sessions follow it (docs/product/team.md, т. 5.2).
     *
     * @param  array{organization: Organization, owner: User}  $f
     * @param  array<string, string>  $period
     */
    private function assignTeacher(array $f, InstructorProfile $profile, Group $group, array $period = []): void
    {
        $this->signIn($f['owner'], $f['organization'])
            ->postJson("/api/v1/catalog/groups/{$group->id}/assignments", ['instructor_profile_id' => $profile->id, ...$period])
            ->assertCreated();
    }

    private function sessionOn(Group $group, string $day): Session
    {
        return Session::query()->where('group_id', $group->id)->whereDate('starts_at', $day)->firstOrFail();
    }

    private function member(Organization $organization, string $role): User
    {
        $user = User::factory()->create();
        OrganizationMembership::query()->withoutGlobalScopes()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active',
        ]);

        return $user;
    }

    private function signIn(User $user, Organization $organization): static
    {
        return $this->actingAs($user)->withHeader('X-Organization-Id', $organization->id);
    }

    /**
     * Each request ends with the organization cleared: models are used inside the organization again.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withTenant(Organization $organization, callable $callback): mixed
    {
        app(TenantContext::class)->select($organization);

        try {
            return $callback();
        } finally {
            app(TenantContext::class)->clear();
        }
    }
}
