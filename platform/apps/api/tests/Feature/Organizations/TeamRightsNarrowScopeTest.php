<?php

namespace Tests\Feature\Organizations;

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
 * A narrow scope opens nothing that is not asked for its group (docs/product/team-rights.md, т. 2.2, 11): a viewer with
 * one selected group and every added right is refused on every route of enrollment, the team and the catalog's changes,
 * because the check for the whole organization of a right of a group holds only with the scope "all". The routes are
 * taken from the router; a route without an expected answer here fails. PR 2 turns the filtered ones to "only that group".
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

    public function test_a_viewer_with_one_group_is_refused_on_every_route_of_enrollment_the_team_and_catalog_changes(): void
    {
        $f = $this->rightsFixture();
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
                [$uri, $body] = $requests[$key];
                $status = $this->signInTo($viewer, $f['organization'])->json($method, $uri, $body)->status();
                if ($status !== 403) {
                    $answers[] = "{$key}: {$status}";
                }
            }
        }

        $this->assertSame([], $unlisted, 'Add the new routes to the narrow scope test.');
        $this->assertSame([], $answers, 'A narrow scope must not open these routes.');
    }

    private function covered(string $method, string $uri): bool
    {
        return str_starts_with($uri, 'api/v1/enrollment/')
            || $uri === 'api/v1/team' || str_starts_with($uri, 'api/v1/team/')
            || (str_starts_with($uri, 'api/v1/catalog/') && $method !== 'GET')
            || $uri === 'api/v1/enrollments/{enrollment}/visit-outcome';
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, array{string, array<string, mixed>}>
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
            'POST api/v1/enrollments/{enrollment}/visit-outcome' => ["/api/v1/enrollments/{$f['visit']->id}/visit-outcome", ['outcome' => 'attended']],
            'GET api/v1/team' => ['/api/v1/team', []],
            'GET api/v1/team/members/{member}' => [$member, []],
            'POST api/v1/team/members' => ['/api/v1/team/members', []],
            'PATCH api/v1/team/members/{member}' => [$member, []],
            'POST api/v1/team/members/{member}/archive' => ["{$member}/archive", []],
            'POST api/v1/team/members/{member}/instructor-profile' => ["{$member}/instructor-profile", []],
            'POST api/v1/team/members/{member}/role' => ["{$member}/role", []],
            'POST api/v1/team/members/{member}/invitation' => ["{$member}/invitation", []],
            'GET api/v1/enrollment/applications' => ['/api/v1/enrollment/applications', []],
            'GET api/v1/enrollment/groups' => ['/api/v1/enrollment/groups', []],
            'GET api/v1/enrollment/groups/{group}/roster' => ["/api/v1/enrollment/groups/{$tuesday}/roster", []],
            'GET api/v1/enrollment/enrollments/{enrollment}/transfer-options' => ["{$enrollment}/transfer-options", []],
            'GET api/v1/enrollment/applications/{application}' => [$application, []],
            'GET api/v1/enrollment/enrollments/{enrollment}' => [$enrollment, []],
            'POST api/v1/enrollment/applications/{application}/review' => ["{$application}/review", []],
            'PATCH api/v1/enrollment/groups/{group}/approval' => ["/api/v1/enrollment/groups/{$tuesday}/approval", []],
            'POST api/v1/enrollment/enrollments/{enrollment}/transfer' => ["{$enrollment}/transfer", []],
            'POST api/v1/enrollment/enrollments/{enrollment}/pauses' => ["{$enrollment}/pauses", []],
            'POST api/v1/enrollment/enrollments/{enrollment}/pauses/{pause}/end' => ["{$enrollment}/pauses/{$missing}/end", []],
            'POST api/v1/enrollment/enrollments/{enrollment}/end' => ["{$enrollment}/end", []],
            'POST api/v1/enrollment/enrollments/{enrollment}/reopen' => ["{$enrollment}/reopen", []],
            'POST api/v1/enrollment/enrollments/{enrollment}/skipped-sessions' => ["{$enrollment}/skipped-sessions", []],
            'DELETE api/v1/enrollment/enrollments/{enrollment}/skipped-sessions/{session}' => ["{$enrollment}/skipped-sessions/{$f['session']->id}", []],
            'POST api/v1/catalog/activities' => ['/api/v1/catalog/activities', []],
            'PATCH api/v1/catalog/activities/{activity}' => [$activity, []],
            'POST api/v1/catalog/activities/{activity}/publish' => ["{$activity}/publish", $version],
            'POST api/v1/catalog/activities/{activity}/groups' => ["{$activity}/groups", []],
            'PATCH api/v1/catalog/groups/{group}' => ["/api/v1/catalog/groups/{$tuesday}", []],
            'POST api/v1/catalog/groups/{group}/assignments' => ["/api/v1/catalog/groups/{$tuesday}/assignments", []],
            'POST api/v1/catalog/groups/{group}/assignments/{instructor}/end' => ["/api/v1/catalog/groups/{$tuesday}/assignments/{$missing}/end", []],
            'POST api/v1/catalog/media' => ['/api/v1/catalog/media', []],
            'PATCH api/v1/catalog/profile' => ['/api/v1/catalog/profile', []],
            'POST api/v1/catalog/locations' => ['/api/v1/catalog/locations', []],
            'PATCH api/v1/catalog/locations/{location}' => ["/api/v1/catalog/locations/{$f['location']->id}", []],
            'POST api/v1/catalog/instructors' => ['/api/v1/catalog/instructors', []],
            'PATCH api/v1/catalog/instructors/{instructor}' => ["/api/v1/catalog/instructors/{$missing}", []],
            'POST api/v1/catalog/visit-offers' => ['/api/v1/catalog/visit-offers', []],
            'PATCH api/v1/catalog/visit-offers/{visitOffer}' => [$offer, []],
            'POST api/v1/catalog/visit-offers/{visitOffer}/publish' => ["{$offer}/publish", $version],
            'POST api/v1/catalog/visit-offers/{visitOffer}/close' => ["{$offer}/close", $version],
            'POST api/v1/catalog/visit-offers/{visitOffer}/cancel' => ["{$offer}/cancel", $version],
            'POST api/v1/catalog/open-day-campaigns' => ['/api/v1/catalog/open-day-campaigns', []],
            'PATCH api/v1/catalog/open-day-campaigns/{campaign}' => [$campaign, []],
            'POST api/v1/catalog/open-day-campaigns/{campaign}/publish' => ["{$campaign}/publish", $version],
            'POST api/v1/catalog/open-day-campaigns/{campaign}/close' => ["{$campaign}/close", $version],
            'POST api/v1/catalog/open-day-campaigns/{campaign}/cancel' => ["{$campaign}/cancel", $version],
        ];
    }
}
