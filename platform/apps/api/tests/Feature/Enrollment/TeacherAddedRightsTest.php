<?php

namespace Tests\Feature\Enrollment;

use App\Domain\Organizations\Models\StaffGrant;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TeamRightsFixture;
use Tests\TestCase;

/**
 * A teacher with an added right to enroll (which brings "enrollment.view") keeps their own groups — the current and the
 * coming ones — on the teacher's list and roster; only the other groups of the scope come the team's way
 * (docs/product/team.md, т. 8; team-rights.md, т. 4.4, 9 — the security review of PR #82).
 */
class TeacherAddedRightsTest extends TestCase
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

    public function test_the_own_groups_stay_the_teachers_and_the_others_come_the_teams_way(): void
    {
        $f = $this->rightsFixture();
        $teacher = $this->memberOf($f['organization'], 'instructor');
        $person = $this->personFor($f['organization'], $teacher, teaches: true);
        $profile = $this->profileOf($f['organization'], $person);
        $this->inTenant($f['organization'], function () use ($f, $person, $profile): void {
            $f['tuesday']->assignments()->attach($profile->id, ['role' => 'lead', 'version' => 1]);
            // "Четвъртък" is theirs from next week.
            $f['thursday']->assignments()->attach($profile->id, ['role' => 'assistant', 'starts_on' => '2026-11-10', 'version' => 1]);
            StaffGrant::query()->create(['staff_member_id' => $person->id, 'ability' => 'enrollment.enroll', 'granted_by' => $f['owner']->id, 'granted_at' => now()->subDay()]);
        });
        $this->signInTo($teacher, $f['organization']);

        // The scope "own": only their groups, the coming one too, the teacher's way (no waiting list).
        $groups = collect($this->getJson('/api/v1/enrollment/groups')->assertOk()->json('groups'))->keyBy('id');
        $this->assertEqualsCanonicalizing([$f['tuesday']->id, $f['thursday']->id], $groups->keys()->all());
        $this->assertFalse(array_key_exists('waitlist_count', $groups[$f['thursday']->id]));
        $this->assertFalse(array_key_exists('waitlist_count', $groups[$f['tuesday']->id]));
        $this->assertSame(['group', 'enrollments'], array_keys($this->getJson("/api/v1/enrollment/groups/{$f['tuesday']->id}/roster")->assertOk()->json()));
        $this->getJson("/api/v1/enrollment/groups/{$f['studio']->id}/roster")->assertForbidden();

        // The scope "all": the other groups come the team's way, their own stay the teacher's.
        $this->inTenant($f['organization'], fn () => $person->forceFill(['access_scope' => 'all'])->save());
        $groups = collect($this->getJson('/api/v1/enrollment/groups')->assertOk()->json('groups'))->keyBy('id');
        $this->assertEqualsCanonicalizing([$f['tuesday']->id, $f['thursday']->id, $f['studio']->id], $groups->keys()->all());
        $this->assertSame(0, $groups[$f['studio']->id]['waitlist_count']);
        $this->assertFalse(array_key_exists('waitlist_count', $groups[$f['thursday']->id]));
        $this->assertSame(['group', 'enrollments'], array_keys($this->getJson("/api/v1/enrollment/groups/{$f['thursday']->id}/roster")->assertOk()->json()));
        $this->getJson("/api/v1/enrollment/groups/{$f['studio']->id}/roster")->assertOk()->assertJsonPath('waitlist', []);
    }
}
