<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Actions\AssignGroupInstructor;
use App\Domain\Catalog\Actions\CreateActivityDraft;
use App\Domain\Catalog\Actions\SaveGroupSchedule;
use App\Domain\Catalog\Actions\UpdateActivityDraft;
use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Catalog\Services\TeamAssignments;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\StaffGrant;
use App\Domain\Organizations\Models\StaffMember;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Tests\Support\TeamRightsFixture;
use Tests\TestCase;

/**
 * An assignment gives rights (docs/product/team-rights.md, т. 2, 2.2): who leads or assists a group, teaches a session
 * or leads an offering is placed with "team.assign". The settings alone ("catalog.manage" added) do not place anyone;
 * the same teachers again pass. With "team.assign" only added, a person never places themselves.
 */
class TeamAssignmentRightsTest extends TestCase
{
    use RefreshDatabase;
    use TeamRightsFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->travelTo('2026-11-02 10:00:00');
    }

    public function test_the_settings_alone_do_not_place_teachers_but_the_same_teachers_pass(): void
    {
        $f = $this->rightsFixture();
        [$editor] = $this->viewerWith($f, ['catalog.manage']);
        $teacher = $this->teacherProfile($f, 'Иван Петров');
        ['activity' => $draft, 'group' => $group, 'session' => $session] = $this->draft($f, $teacher);
        $refusal = 'Учителите в групите слагат собственикът, администраторът и координаторът.';
        $this->signInTo($editor, $f['organization']);

        $this->postJson("/api/v1/catalog/groups/{$f['tuesday']->id}/assignments", ['instructor_profile_id' => $teacher])->assertForbidden();
        $this->patchJson("/api/v1/catalog/groups/{$group}", ['version' => 1, 'instructor_ids' => []])
            ->assertForbidden()->assertJsonPath('message', $refusal);
        $this->patchJson("/api/v1/catalog/groups/{$group}", ['version' => 1, 'session_overrides' => [['id' => $session, 'instructor_ids' => []]]])
            ->assertForbidden()->assertJsonPath('message', $refusal);
        $this->patchJson("/api/v1/catalog/activities/{$draft}", ['version' => 1, 'offering' => ['lead_instructor_profile_id' => $teacher]])
            ->assertForbidden()->assertJsonPath('message', $refusal);
        $this->postJson('/api/v1/catalog/activities', [...$this->activityBody(), 'offering' => ['lead_instructor_profile_id' => $teacher]])
            ->assertForbidden()->assertJsonPath('message', $refusal);

        // Nothing about who teaches changes: allowed.
        $this->patchJson("/api/v1/catalog/groups/{$group}", ['version' => 1, 'name' => 'Събота', 'instructor_ids' => [$teacher]])->assertOk();
        $this->patchJson("/api/v1/catalog/groups/{$group}", ['version' => 2, 'session_overrides' => [['id' => $session, 'instructor_ids' => [$teacher]]]])->assertOk();
        $this->patchJson("/api/v1/catalog/activities/{$draft}", ['version' => 1, 'title' => 'Шах за напреднали', 'offering' => ['lead_instructor_profile_id' => null]])->assertOk();
        $this->postJson('/api/v1/catalog/activities', $this->activityBody())->assertCreated();

        $this->inTenant($f['organization'], fn () => $this->assertSame(
            [$teacher], Group::query()->findOrFail($group)->instructors()->pluck('instructor_profiles.id')->all(),
        ));
    }

    public function test_with_an_added_right_to_place_teachers_a_person_does_not_place_themselves(): void
    {
        $f = $this->rightsFixture();
        [$editor, $person] = $this->viewerWith($f, ['catalog.manage', 'team.assign'], teaches: true);
        $own = (string) $this->profileOf($f['organization'], $person)->id;
        $teacher = $this->teacherProfile($f, 'Иван Петров');
        ['activity' => $draft, 'group' => $group] = $this->draft($f, null);
        $self = 'Не можете да се назначите сами.';
        $this->signInTo($editor, $f['organization']);

        $this->postJson("/api/v1/catalog/groups/{$f['tuesday']->id}/assignments", ['instructor_profile_id' => $own])
            ->assertForbidden()->assertJsonPath('message', $self);
        $this->patchJson("/api/v1/catalog/groups/{$group}", ['version' => 1, 'instructor_ids' => [$own]])
            ->assertForbidden()->assertJsonPath('message', $self);
        $this->patchJson("/api/v1/catalog/activities/{$draft}", ['version' => 1, 'offering' => ['lead_instructor_profile_id' => $own]])
            ->assertForbidden()->assertJsonPath('message', $self);

        $this->postJson("/api/v1/catalog/groups/{$f['tuesday']->id}/assignments", ['instructor_profile_id' => $teacher])->assertCreated();
        $this->patchJson("/api/v1/catalog/groups/{$group}", ['version' => 1, 'instructor_ids' => [$teacher]])->assertOk();

        // With the right from the role, as before: the coordinator places themselves.
        $coordinator = $this->memberOf($f['organization'], 'coordinator');
        $mine = (string) $this->profileOf($f['organization'], $this->personFor($f['organization'], $coordinator, teaches: true))->id;
        $this->signInTo($coordinator, $f['organization'])
            ->postJson("/api/v1/catalog/groups/{$f['thursday']->id}/assignments", ['instructor_profile_id' => $mine])->assertCreated();
    }

    /**
     * PostgreSQL finds a profile by its UUID in any case: an identifier in capitals is still the person's own
     * (the security review of PR #81).
     */
    #[TestGroup('tenant-isolation')]
    public function test_an_identifier_in_capitals_is_still_ones_own_profile(): void
    {
        if (! $this->postgresIsAvailable()) {
            $this->markTestSkipped('PostgreSQL is required: SQLite compares identifiers by their letters.');
        }

        Artisan::call('migrate', ['--database' => 'pgsql_admin', '--force' => true]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('pgsql_admin');
        DB::beginTransaction();

        try {
            // Only the catalog and the team: the rest of the fixture is not needed here.
            $organization = Organization::query()->withoutGlobalScopes()->create(['name' => 'Школа', 'slug' => 'school-'.Str::random(12), 'status' => 'active']);
            $f = ['organization' => $organization, 'owner' => $this->memberOf($organization, 'organization_owner')];
            $f['tuesday'] = $this->inTenant($organization, fn (): Group => Activity::query()->create([
                'slug' => 'chess-'.Str::random(8), 'title' => 'Шах', 'category' => 'Наука', 'summary' => 'Описание', 'age_label' => '7+', 'status' => 'published',
            ])->offerings()->create([])->groups()->create(['name' => 'Вторник', 'status' => 'published', 'capacity_max' => 10]));
            [$editor, $person] = $this->viewerWith($f, ['catalog.manage', 'team.assign'], teaches: true);
            $own = Str::upper((string) $this->profileOf($f['organization'], $person)->id);
            ['activity' => $draft, 'group' => $group] = $this->draft($f, null);
            $this->actingAs($editor);
            app(TenantContext::class)->select($f['organization']);
            DB::select("SELECT set_config('app.current_organization_id', ?, true)", [$f['organization']->id]);

            $refusals = [];
            foreach ([
                'assignment' => fn () => app(AssignGroupInstructor::class)->handle($editor, Group::query()->findOrFail($f['tuesday']->id), ['instructor_profile_id' => $own]),
                'teachers of a group' => fn () => app(SaveGroupSchedule::class)->handle(Activity::query()->findOrFail($draft), ['id' => $group, 'version' => 1, 'instructor_ids' => [$own]]),
                'lead of an offering' => fn () => app(UpdateActivityDraft::class)->handle($editor, Activity::query()->findOrFail($draft), 1, ['offering' => ['lead_instructor_profile_id' => $own]]),
                'lead of a new activity' => fn () => app(CreateActivityDraft::class)->handle($editor, $f['organization'], [...$this->activityBody(), 'offering' => ['lead_instructor_profile_id' => $own]]),
            ] as $what => $attempt) {
                try {
                    $attempt();
                    $refusals[$what] = 'accepted';
                } catch (AuthorizationException $exception) {
                    $refusals[$what] = $exception->getMessage();
                }
            }

            $this->assertSame(array_fill_keys(['assignment', 'teachers of a group', 'lead of an offering', 'lead of a new activity'], 'Не можете да се назначите сами.'), $refusals);
            $this->assertSame(0, DB::table('activity_group_instructors')->whereRaw('lower(instructor_profile_id::text) = ?', [Str::lower($own)])->count());
        } finally {
            DB::rollBack();
            DB::setDefaultConnection($original);
            app(TenantContext::class)->clear();
        }
    }

    public function test_capitals_in_an_identifier_do_not_hide_ones_own_profile(): void
    {
        $f = $this->rightsFixture();
        [$editor, $person] = $this->viewerWith($f, ['catalog.manage', 'team.assign'], teaches: true);
        $own = Str::upper((string) $this->profileOf($f['organization'], $person)->id);

        $this->expectException(AuthorizationException::class);
        $this->inTenant($f['organization'], fn () => app(TeamAssignments::class)->assertMayAssign($editor, [$own]));
    }

    /**
     * A viewer with the whole organization and the added rights.
     *
     * @param  array{organization: Organization, owner: User}  $f
     * @param  list<string>  $abilities
     * @return array{User, StaffMember}
     */
    private function viewerWith(array $f, array $abilities, bool $teaches = false): array
    {
        $viewer = $this->memberOf($f['organization'], 'viewer');
        $person = $this->personFor($f['organization'], $viewer, $teaches);
        $this->inTenant($f['organization'], function () use ($f, $person, $abilities): void {
            foreach ($abilities as $ability) {
                StaffGrant::query()->create([
                    'staff_member_id' => $person->id, 'ability' => $ability, 'granted_by' => $f['owner']->id, 'granted_at' => now()->subDay(),
                ]);
            }
        });

        return [$viewer, $person];
    }

    /** @param array{organization: Organization} $f */
    private function teacherProfile(array $f, string $name): string
    {
        return $this->inTenant($f['organization'], function () use ($name): string {
            $person = StaffMember::query()->create(['full_name' => $name]);
            $profile = InstructorProfile::query()->create(['public_name' => $name]);
            $profile->forceFill(['staff_member_id' => $person->id])->save();

            return (string) $profile->id;
        });
    }

    /**
     * A draft activity with a draft group and one session; the teacher, if given, teaches the group now.
     *
     * @param  array{organization: Organization}  $f
     * @return array{activity: string, group: string, session: string}
     */
    private function draft(array $f, ?string $teacher): array
    {
        return $this->inTenant($f['organization'], function () use ($teacher): array {
            $activity = Activity::query()->create([
                'slug' => 'draft-'.Str::random(8), 'title' => 'Шах', 'category' => 'Наука', 'summary' => 'Описание', 'age_label' => '7+', 'status' => 'draft',
            ]);
            $group = $activity->offerings()->create([])->groups()->create(['name' => 'Неделя', 'status' => 'draft', 'capacity_max' => 10]);
            $session = $group->sessions()->create(['starts_at' => '2026-11-08T08:00:00Z', 'ends_at' => '2026-11-08T09:00:00Z', 'status' => 'scheduled']);
            if ($teacher !== null) {
                $group->assignments()->attach($teacher, ['role' => 'lead', 'version' => 1]);
                $session->instructors()->attach($teacher, ['role' => 'lead']);
            }

            return ['activity' => (string) $activity->id, 'group' => (string) $group->id, 'session' => (string) $session->id];
        });
    }

    private function postgresIsAvailable(): bool
    {
        try {
            DB::connection('pgsql_admin')->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, string> */
    private function activityBody(): array
    {
        return ['title' => 'Роботика', 'category' => 'Наука', 'summary' => 'Описание', 'age_label' => '8+'];
    }
}
