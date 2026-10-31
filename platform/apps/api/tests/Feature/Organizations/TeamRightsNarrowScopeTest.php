<?php

namespace Tests\Feature\Organizations;

use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Organizations\Models\StaffGrant;
use App\Domain\Organizations\Models\StaffScopeGroup;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Tests\Support\TeamRightsFixture;
use Tests\TestCase;

/**
 * A narrow scope opens only its group (docs/product/team-rights.md, т. 2.2, 9, 11): a viewer with one selected group and
 * every added right works there — the lists hold only that group, a record of it answers, a change of it reaches its
 * validation — and is refused anywhere else, on the team outside the group, and on every change of the catalog (rights
 * for the whole organization only fall away). The routes are taken from the router; a route without an expected answer
 * here fails.
 */
#[TestGroup('authorization')]
class TeamRightsNarrowScopeTest extends TestCase
{
    use RefreshDatabase;
    use TeamRightsFixture;

    /** Every right that may be added, also those for the whole organization only — put straight into the database. */
    private const GRANTS = [
        'enrollment.enroll', 'attendance.mark', 'attendance.view_month', 'schedule.view_all', 'schedule.manage',
        'contacts.view', 'contacts.message', 'team.assign', 'catalog.manage', 'catalog.publish',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->travelTo('2026-11-02 10:00:00');
    }

    public function test_a_viewer_with_one_group_works_only_in_that_group(): void
    {
        $f = $this->rightsFixture();
        // An application in another group of the same activity.
        $outside = $this->inTenant($f['organization'], fn (): EnrollmentApplication => $this->rightsApplication(
            $f['owner'], $f['owner']->children()->create(['first_name' => 'Дани', 'last_name' => 'Колев'])->id, $f['thursday']->id, $f['price']->id, 'waitlisted',
        ));
        // A child enrolled in another group.
        $this->inTenant($f['organization'], function () use ($f): void {
            $childId = $f['owner']->children()->create(['first_name' => 'Ана', 'last_name' => 'Колева'])->id;
            $application = $this->rightsApplication($f['owner'], $childId, $f['thursday']->id, $f['price']->id, 'approved');
            Enrollment::query()->create([
                'application_id' => $application->id, 'child_id' => $childId, 'group_id' => $f['thursday']->id,
                'price_option_id' => $f['price']->id, 'scope' => 'full_period', 'status' => 'active', 'starts_on' => '2026-11-05',
            ]);
        });
        $viewer = $this->memberOf($f['organization'], 'viewer');
        $person = $this->personFor($f['organization'], $viewer);
        $this->inTenant($f['organization'], function () use ($f, $person): void {
            $person->forceFill(['access_scope' => 'groups'])->save();
            StaffScopeGroup::query()->create([
                'staff_member_id' => $person->id, 'group_id' => $f['tuesday']->id, 'added_by' => $f['owner']->id, 'added_at' => now(),
            ]);
            foreach (self::GRANTS as $ability) {
                StaffGrant::query()->create([
                    'staff_member_id' => $person->id, 'ability' => $ability, 'granted_by' => $f['owner']->id, 'granted_at' => now()->subDay(),
                ]);
            }
        });

        $requests = $this->requests($f);
        $unlisted = [];
        $answers = [];
        foreach (Router::getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $key = $method.' '.$route->uri();
                if (! $this->covered($method, $route->uri())) {
                    continue;
                }
                if (! isset($requests[$key])) {
                    $unlisted[] = $key;

                    continue;
                }
                [$uri, $body, $expected] = $requests[$key];
                $status = $this->signInTo($viewer, $f['organization'])->json($method, $uri, $body)->status();
                if ($status !== $expected) {
                    $answers[] = "{$key}: expected {$expected}, got {$status}";
                }
            }
        }

        $this->assertSame([], $unlisted, 'Add the new routes to the narrow scope test.');
        $this->assertSame([], $answers, 'A narrow scope answers only for its group.');

        // The lists hold only the group; the team, only the person themselves (nobody teaches "Вторник").
        $this->signInTo($viewer, $f['organization']);
        $this->assertSame([$f['tuesday']->id], array_column($this->getJson('/api/v1/enrollment/groups')->assertOk()->json('groups'), 'id'));
        $waiting = $this->getJson('/api/v1/enrollment/applications?status=waitlisted')->assertOk()->json('applications');
        $this->assertSame([$f['tuesday']->id], array_values(array_unique(array_column(array_column($waiting, 'group'), 'id'))));
        // The search of the schedule's filter finds the children enrolled in the group, not Ана in "Четвъртък".
        $this->assertSame(['Ани Иванова'], array_column($this->getJson('/api/v1/enrollment/children?q='.urlencode('Ан'))->assertOk()->json('children'), 'name'));
        $this->assertSame([$person->id], array_column($this->getJson('/api/v1/team')->assertOk()->json('members'), 'id'));
        $this->assertSame([], $this->getJson("/api/v1/enrollment/enrollments/{$f['enrollment']->id}/transfer-options")->assertOk()->json('groups'));
        // Outside the group: refused.
        $this->getJson("/api/v1/enrollment/groups/{$f['thursday']->id}/roster")->assertForbidden();
        $this->getJson("/api/v1/enrollment/applications/{$outside->id}")->assertForbidden();
        $this->postJson("/api/v1/enrollment/applications/{$outside->id}/review", ['decision' => 'approve', 'version' => 1])->assertForbidden();
        $this->postJson("/api/v1/enrollment/applications/{$outside->id}/contact/reveal")->assertForbidden();
        $this->patchJson("/api/v1/enrollment/groups/{$f['thursday']->id}/approval", ['enrollment_approval_mode' => 'automatic'])->assertForbidden();
        $this->postJson("/api/v1/enrollment/enrollments/{$f['enrollment']->id}/transfer", [
            'target_group_id' => $f['thursday']->id, 'price_option_id' => $f['price']->id, 'effective_date' => '2026-11-12', 'reason' => 'Смяна', 'version' => 1,
        ])->assertForbidden();
        $this->inTenant($f['organization'], fn () => $this->assertSame('waitlisted', EnrollmentApplication::query()->findOrFail($outside->id)->status->value));
    }

    /**
     * A cancellation of a session of the selected group itself.
     *
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    private function cancelling(array $f): array
    {
        return ['action' => 'cancel', 'sessions' => [['id' => $f['session']->id, 'version' => 1]], 'reason' => 'Ремонт'];
    }

    private function covered(string $method, string $uri): bool
    {
        return str_starts_with($uri, 'api/v1/enrollment/')
            || $uri === 'api/v1/team' || str_starts_with($uri, 'api/v1/team/')
            || (str_starts_with($uri, 'api/v1/catalog/') && $method !== 'GET')
            || $uri === 'api/v1/enrollments/{enrollment}/visit-outcome'
            || str_starts_with($uri, 'api/v1/schedule/changes');
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, array{string, array<string, mixed>, int}> address, body, the status in the selected group
     */
    private function requests(array $f): array
    {
        $member = "/api/v1/team/members/{$f['person']->id}";
        $enrollment = "/api/v1/enrollment/enrollments/{$f['enrollment']->id}";
        $application = "/api/v1/enrollment/applications/{$f['waiting']->id}";
        $tuesday = $f['tuesday']->id;
        $activity = "/api/v1/catalog/activities/{$f['activity']->id}";
        $offer = "/api/v1/catalog/visit-offers/{$f['offer']->id}";
        $campaign = "/api/v1/catalog/open-day-campaigns/{$f['campaign']->id}";
        $missing = (string) Str::uuid();
        $version = ['version' => 1];

        return [
            'POST api/v1/enrollments/{enrollment}/visit-outcome' => ["/api/v1/enrollments/{$f['visit']->id}/visit-outcome", ['outcome' => 'attended'], 422],
            'GET api/v1/team' => ['/api/v1/team', [], 200],
            // "Петър" teaches no group of the scope.
            'GET api/v1/team/members/{member}' => [$member, [], 403],
            'GET api/v1/team/members/{member}/access' => ["{$member}/access", [], 403],
            'PUT api/v1/team/members/{member}/access' => ["{$member}/access", [], 403],
            'POST api/v1/team/members' => ['/api/v1/team/members', [], 403],
            'PATCH api/v1/team/members/{member}' => [$member, [], 403],
            'POST api/v1/team/members/{member}/archive' => ["{$member}/archive", [], 403],
            'POST api/v1/team/members/{member}/instructor-profile' => ["{$member}/instructor-profile", [], 403],
            'POST api/v1/team/members/{member}/role' => ["{$member}/role", [], 403],
            'POST api/v1/team/members/{member}/invitation' => ["{$member}/invitation", [], 403],
            'GET api/v1/enrollment/applications' => ['/api/v1/enrollment/applications', [], 200],
            // Only the children of the group (asserted below).
            'GET api/v1/enrollment/children' => ['/api/v1/enrollment/children?q='.urlencode('Ани'), [], 200],
            'GET api/v1/enrollment/groups' => ['/api/v1/enrollment/groups', [], 200],
            'GET api/v1/enrollment/groups/{group}/roster' => ["/api/v1/enrollment/groups/{$tuesday}/roster", [], 200],
            'GET api/v1/enrollment/enrollments/{enrollment}/transfer-options' => ["{$enrollment}/transfer-options", [], 200],
            'GET api/v1/enrollment/applications/{application}' => [$application, [], 200],
            'GET api/v1/enrollment/enrollments/{enrollment}' => [$enrollment, [], 200],
            'POST api/v1/enrollment/applications/{application}/contact/reveal' => ["{$application}/contact/reveal", [], 200],
            'POST api/v1/enrollment/enrollments/{enrollment}/contact/reveal' => ["{$enrollment}/contact/reveal", [], 200],
            'POST api/v1/enrollment/applications/{application}/review' => ["{$application}/review", [], 422],
            // A setting of the group, changed with the activities — for the whole organization only (31.10.2026).
            'PATCH api/v1/enrollment/groups/{group}/approval' => ["/api/v1/enrollment/groups/{$tuesday}/approval", [], 403],
            'POST api/v1/enrollment/enrollments/{enrollment}/transfer' => ["{$enrollment}/transfer", [], 422],
            'POST api/v1/enrollment/enrollments/{enrollment}/pauses' => ["{$enrollment}/pauses", [], 422],
            'POST api/v1/enrollment/enrollments/{enrollment}/pauses/{pause}/end' => ["{$enrollment}/pauses/{$missing}/end", [], 422],
            'POST api/v1/enrollment/enrollments/{enrollment}/end' => ["{$enrollment}/end", [], 422],
            'POST api/v1/enrollment/enrollments/{enrollment}/reopen' => ["{$enrollment}/reopen", [], 422],
            'POST api/v1/enrollment/enrollments/{enrollment}/skipped-sessions' => ["{$enrollment}/skipped-sessions", [], 422],
            'DELETE api/v1/enrollment/enrollments/{enrollment}/skipped-sessions/{session}' => ["{$enrollment}/skipped-sessions/{$f['session']->id}", [], 422],
            'POST api/v1/catalog/activities' => ['/api/v1/catalog/activities', [], 403],
            'PATCH api/v1/catalog/activities/{activity}' => [$activity, [], 403],
            'POST api/v1/catalog/activities/{activity}/publish' => ["{$activity}/publish", $version, 403],
            'POST api/v1/catalog/activities/{activity}/gallery/remove' => ["{$activity}/gallery/remove", [], 403],
            'POST api/v1/catalog/activities/{activity}/groups' => ["{$activity}/groups", [], 403],
            'PATCH api/v1/catalog/groups/{group}' => ["/api/v1/catalog/groups/{$tuesday}", [], 403],
            'POST api/v1/catalog/groups/{group}/assignments' => ["/api/v1/catalog/groups/{$tuesday}/assignments", [], 403],
            'POST api/v1/catalog/groups/{group}/assignments/{instructor}/end' => ["/api/v1/catalog/groups/{$tuesday}/assignments/{$missing}/end", [], 403],
            'POST api/v1/catalog/media' => ['/api/v1/catalog/media', [], 403],
            'PATCH api/v1/catalog/profile' => ['/api/v1/catalog/profile', [], 403],
            'POST api/v1/catalog/locations' => ['/api/v1/catalog/locations', [], 403],
            'PATCH api/v1/catalog/locations/{location}' => ["/api/v1/catalog/locations/{$f['location']->id}", [], 403],
            'POST api/v1/catalog/instructors' => ['/api/v1/catalog/instructors', [], 403],
            'PATCH api/v1/catalog/instructors/{instructor}' => ["/api/v1/catalog/instructors/{$missing}", [], 403],
            'POST api/v1/catalog/visit-offers' => ['/api/v1/catalog/visit-offers', [], 403],
            'PATCH api/v1/catalog/visit-offers/{visitOffer}' => [$offer, [], 403],
            'POST api/v1/catalog/visit-offers/{visitOffer}/publish' => ["{$offer}/publish", $version, 403],
            'POST api/v1/catalog/visit-offers/{visitOffer}/close' => ["{$offer}/close", $version, 403],
            'POST api/v1/catalog/visit-offers/{visitOffer}/cancel' => ["{$offer}/cancel", $version, 403],
            'POST api/v1/catalog/open-day-campaigns' => ['/api/v1/catalog/open-day-campaigns', [], 403],
            'PATCH api/v1/catalog/open-day-campaigns/{campaign}' => [$campaign, [], 403],
            'POST api/v1/catalog/open-day-campaigns/{campaign}/publish' => ["{$campaign}/publish", $version, 403],
            'POST api/v1/catalog/open-day-campaigns/{campaign}/close' => ["{$campaign}/close", $version, 403],
            'POST api/v1/catalog/open-day-campaigns/{campaign}/cancel' => ["{$campaign}/cancel", $version, 403],
            // "schedule.manage" holds for the whole organization only: added with a narrow scope, it gives nothing.
            'POST api/v1/schedule/changes/preview' => ['/api/v1/schedule/changes/preview', $this->cancelling($f), 403],
            'POST api/v1/schedule/changes' => ['/api/v1/schedule/changes', $this->cancelling($f), 403],
        ];
    }
}
