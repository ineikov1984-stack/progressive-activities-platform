<?php

namespace Tests\Feature\Organizations;

use App\Domain\Organizations\Models\Organization;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Tests\Support\TeamRightsFixture;
use Tests\TestCase;

/**
 * Rights in the team do not change what any role may do today (docs/product/team-rights.md, т. 2.1, 11): every role
 * against every route of a selected organization answers with the same status as before the rights. The requests are
 * built to stop right after the check of the right — at the validation, a stale version or a missing record — so that
 * nothing changes on the way. A new route of an organization fails this test until it is added here. The approved
 * changes since: the contacts (PR 2, т. 6) and the owner's decision of 31.10.2026 — the teacher has no catalog, the
 * payment terms are read by the owner, the administrator, the coordinator and the accountant, and the approval of a
 * group is a setting of the catalog. New since: the schedule (docs/product/schedule.md) and its changes, which the
 * platform operator no longer manages (docs/product/schedule-changes.md, decision 8).
 */
#[TestGroup('authorization')]
class TeamRightsCharacterizationTest extends TestCase
{
    use RefreshDatabase;
    use TeamRightsFixture;

    private const ALL = ['platform_admin', 'organization_owner', 'organization_admin', 'coordinator', 'instructor', 'finance', 'viewer'];

    private const TEAM_VIEW = ['organization_owner', 'organization_admin', 'coordinator', 'finance', 'viewer'];

    private const TEAM_MANAGE = ['organization_owner', 'organization_admin'];

    private const ENROLLMENT_VIEW = ['platform_admin', 'organization_owner', 'organization_admin', 'coordinator', 'finance', 'viewer'];

    private const ENROLLMENT_MANAGE = ['platform_admin', 'organization_owner', 'organization_admin', 'coordinator'];

    /** Who records how a visit went: the platform operator does not (RecordVisitOutcome). */
    private const VISIT_STAFF = ['organization_owner', 'organization_admin', 'coordinator'];

    /** Who sees the parents' contacts: the owner at once, the administrator and the coordinator with "Покажи телефона". */
    private const CONTACTS = ['organization_owner', 'organization_admin', 'coordinator'];

    /** 31.10.2026: no longer the teacher. */
    private const CATALOG_VIEW = ['platform_admin', 'organization_owner', 'organization_admin', 'coordinator', 'viewer'];

    private const CATALOG_MANAGE = ['platform_admin', 'organization_owner', 'organization_admin', 'coordinator'];

    private const CATALOG_PUBLISH = ['platform_admin', 'organization_owner', 'organization_admin'];

    /** Who changes the schedule: "schedule.manage" for the whole organization and of the team — not the platform operator (decision 8, 31.10.2026). */
    private const SCHEDULE_MANAGE = ['organization_owner', 'organization_admin', 'coordinator'];

    private const INVITE = ['platform_admin', 'organization_owner', 'organization_admin'];

    /** 31.10.2026: no longer the teacher and the viewer; the operator keeps them for the activities it edits. */
    private const TERMS_VIEW = ['platform_admin', 'organization_owner', 'organization_admin', 'coordinator', 'finance'];

    private const TERMS_MANAGE = ['organization_owner', 'organization_admin'];

    /** The organization's application to the platform: by role, not a right in the team. */
    private const APPLICATION = ['organization_owner', 'organization_admin'];

    /** Only the owner sends the application for review. */
    private const SUBMIT = ['organization_owner'];

    /** The rights each role had before the rights in the team; `GET /organizations` keeps giving them to the web. */
    private const PERMISSIONS_BEFORE = [
        'platform_admin' => ['create', 'update', 'invite', 'finance', 'view', 'catalog.view', 'catalog.manage', 'catalog.publish', 'enrollment.view', 'enrollment.manage'],
        'organization_owner' => ['create', 'update', 'invite', 'finance', 'view', 'catalog.view', 'catalog.manage', 'catalog.publish', 'enrollment.view', 'enrollment.manage', 'enrollment.contacts', 'terms.manage', 'team.view', 'team.manage'],
        'organization_admin' => ['create', 'update', 'invite', 'view', 'catalog.view', 'catalog.manage', 'catalog.publish', 'enrollment.view', 'enrollment.manage', 'enrollment.contacts', 'terms.manage', 'team.view', 'team.manage'],
        'coordinator' => ['create', 'update', 'view', 'catalog.view', 'catalog.manage', 'enrollment.view', 'enrollment.manage', 'enrollment.contacts', 'team.view'],
        // 31.10.2026: without "catalog.view".
        'instructor' => ['view', 'enrollment.view_own'],
        'finance' => ['finance', 'view', 'enrollment.view', 'team.view'],
        'viewer' => ['view', 'catalog.view', 'enrollment.view', 'team.view'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        // Monday 2 November 2026, 12:00 in Sofia: every session of the fixture is still ahead.
        $this->travelTo('2026-11-02 10:00:00');
    }

    /** @return iterable<string, array{string}> */
    public static function roles(): iterable
    {
        foreach (self::ALL as $role) {
            yield $role => [$role];
        }
    }

    #[DataProvider('roles')]
    public function test_every_route_of_an_organization_answers_each_role_as_before(string $role): void
    {
        $f = $this->rightsFixture();
        $actor = $this->memberOf($f['organization'], $role);
        if ($role !== 'platform_admin') {
            $person = $this->personFor($f['organization'], $actor, teaches: $role === 'instructor');
            if ($role === 'instructor') {
                // The teacher leads "Вторник": the roster of their own group is theirs.
                $profile = $this->profileOf($f['organization'], $person);
                $this->inTenant($f['organization'], fn () => $f['tuesday']->assignments()->attach($profile->id, ['role' => 'lead', 'version' => 1]));
            }
        }

        $mismatches = [];
        foreach ($this->routes($f) as $key => [$method, $uri, $body, $allowed, $status]) {
            $expected = in_array($role, $allowed, true) ? $status : 403;
            if (str_contains($key, 'api/v1/attendance/sessions/') && $role === 'platform_admin') {
                $expected = 404;
            }
            $actual = $this->signInTo($actor, $f['organization'])->json($method, $uri, $body)->status();
            if ($actual !== $expected) {
                $mismatches[] = "{$key}: expected {$expected}, got {$actual}";
            }
        }

        $this->assertSame([], $mismatches, "Role {$role} answers differently than before.");
    }

    /**
     * The one change of PR 2 (docs/product/team-rights.md, т. 6): the phone and the e-mail of a parent are shown at once
     * to the owner only; the administrator and the coordinator get them hidden and show them with "Покажи телефона".
     */
    #[DataProvider('roles')]
    public function test_only_the_owner_sees_the_parents_phone_at_once(string $role): void
    {
        $f = $this->rightsFixture();
        $actor = $this->memberOf($f['organization'], $role);
        $details = $this->signInTo($actor, $f['organization'])->getJson("/api/v1/enrollment/enrollments/{$f['enrollment']->id}");

        if (! in_array($role, self::ENROLLMENT_VIEW, true)) {
            $details->assertForbidden();

            return;
        }
        $details->assertOk()->assertJsonPath('contact_status', match ($role) {
            'organization_owner' => 'shown',
            'organization_admin', 'coordinator' => 'hidden',
            default => 'not_allowed',
        });
        if ($role === 'organization_owner') {
            $details->assertJsonPath('contact.name', 'Мария Иванова')->assertJsonPath('contact.hidden', null);
            $this->assertNotNull($details->json('contact.phone'));
        } elseif (in_array($role, self::CONTACTS, true)) {
            $details->assertJsonPath('contact.name', 'Мария Иванова')->assertJsonPath('contact.hidden', true)
                ->assertJsonPath('contact.phone', null)->assertJsonPath('contact.email', null);
        }
    }

    /**
     * "График" (docs/product/schedule.md, т. 2): the roles of the whole organization see every session, the teacher the
     * group they lead; the children of it every role — through "enrollment.view" or as their own group; the reason for a
     * change and the changes only with "schedule.manage"; the search of a child and by a child only with "enrollment.view";
     * the card of a child to every role, with the phone only for those who see contacts and "Пропусни" and "Пауза" only
     * for those who enrol (т. 4.1).
     */
    #[DataProvider('roles')]
    public function test_the_schedule_shows_each_role_the_sessions_of_its_scope(string $role): void
    {
        $f = $this->rightsFixture();
        $actor = $this->memberOf($f['organization'], $role);
        if ($role !== 'platform_admin') {
            $person = $this->personFor($f['organization'], $actor, teaches: $role === 'instructor');
            if ($role === 'instructor') {
                $profile = $this->profileOf($f['organization'], $person);
                $this->inTenant($f['organization'], fn () => $f['tuesday']->assignments()->attach($profile->id, ['role' => 'lead', 'version' => 1]));
            }
        }
        $manage = in_array($role, self::SCHEDULE_MANAGE, true);

        $this->signInTo($actor, $f['organization'])->getJson('/api/v1/schedule?from=2026-11-02&to=2026-11-29')->assertOk()
            ->assertJsonCount($role === 'instructor' ? 4 : 12, 'sessions')
            ->assertJsonPath("abilities.{$f['tuesday']->id}", ['children' => true, 'manage' => $manage]);
        $panel = $this->getJson("/api/v1/schedule/sessions/{$f['session']->id}")->assertOk()->assertJsonCount(2, 'session.children')
            ->assertJsonPath('abilities.manage', $manage)->assertJsonPath('actions', $manage ? ['move', 'cancel'] : []);
        // Why a session changed: with "schedule.manage", also for the platform operator, who changes nothing.
        if (in_array($role, self::CATALOG_MANAGE, true)) {
            $panel->assertJsonPath('session.change_reason', null);
        } else {
            $panel->assertJsonMissingPath('session.change_reason');
        }
        // The child for the filter comes from the search of the enrolled children (т. 3.5).
        $search = $this->getJson('/api/v1/enrollment/children?q='.urlencode('Ани'));
        $byChild = $this->getJson("/api/v1/schedule?from=2026-11-02&to=2026-11-29&child={$f['enrollment']->child_id}");
        if (in_array($role, self::ENROLLMENT_VIEW, true)) {
            $search->assertOk()->assertJsonPath('children', [['id' => $f['enrollment']->child_id, 'name' => 'Ани Иванова', 'class' => '3 клас']]);
            $byChild->assertOk()->assertJsonCount(4, 'sessions');
        } else {
            $search->assertForbidden();
            $byChild->assertForbidden();
        }
        $enrols = in_array($role, self::ENROLLMENT_MANAGE, true);
        $this->getJson("/api/v1/enrollment/children/{$f['enrollment']->child_id}")->assertOk()->assertJsonCount(1, 'groups')
            ->assertJsonPath('groups.0.group_id', $f['tuesday']->id)
            ->assertJsonPath('groups.0.abilities', ['contacts' => in_array($role, self::CONTACTS, true), 'skip' => $enrols, 'pause' => $enrols]);
    }

    #[DataProvider('roles')]
    public function test_the_organizations_list_still_gives_each_role_its_rights_from_before(string $role): void
    {
        $organization = Organization::query()->withoutGlobalScopes()->create(['name' => 'Школа', 'slug' => 'school-'.Str::random(8), 'status' => 'active']);
        $actor = $this->memberOf($organization, $role);

        $permissions = $this->actingAs($actor)->getJson('/api/v1/organizations')->assertOk()->json('organizations.0.permissions');

        $this->assertIsArray($permissions);
        $this->assertSame([], array_values(array_diff(self::PERMISSIONS_BEFORE[$role], $permissions)), 'A right from before is missing.');
    }

    #[DataProvider('roles')]
    public function test_session_attendance_actions_follow_each_role(string $role): void
    {
        $f = $this->rightsFixture();
        $actor = $this->memberOf($f['organization'], $role);
        if ($role === 'instructor') {
            $person = $this->personFor($f['organization'], $actor, true);
            $profile = $this->profileOf($f['organization'], $person);
            $this->inTenant($f['organization'], fn () => $f['tuesday']->assignments()->attach($profile->id, ['role' => 'lead']));
        }
        foreach (['topic', 'not_held', 'held'] as $action) {
            $allowed = in_array($role, self::SCHEDULE_MANAGE, true) || ($action === 'topic' && $role === 'instructor');
            $expected = $role === 'platform_admin' ? 404 : ($allowed ? 422 : 403);
            $this->signInTo($actor, $f['organization'])->postJson('/api/v1/attendance/sessions/'.$f['session']->id.'/changes',
                ['action' => $action, 'topic' => str_repeat('а', 201)])->assertStatus($expected);
        }
    }

    public function test_every_route_of_an_organization_has_an_expected_answer(): void
    {
        $f = $this->rightsFixture();
        $known = array_keys($this->routes($f));
        $missing = [];
        foreach (Router::getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            if (! in_array('tenant', $route->gatherMiddleware(), true)) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                if (! in_array($method.' '.$route->uri(), $known, true)) {
                    $missing[] = $method.' '.$route->uri();
                }
            }
        }

        $this->assertSame([], $missing, 'Add the new routes to the characterization of rights.');
    }

    /**
     * Every route under the organization: method, address, body, the roles that pass the check of the right and the
     * status they get. Every other role gets 403.
     *
     * @param  array<string, mixed>  $f
     * @return array<string, array{string, string, array<string, mixed>, list<string>, int}>
     */
    private function routes(array $f): array
    {
        $member = "/api/v1/team/members/{$f['person']->id}";
        $enrollment = "/api/v1/enrollment/enrollments/{$f['enrollment']->id}";
        $application = "/api/v1/enrollment/applications/{$f['waiting']->id}";
        $tuesday = $f['tuesday']->id;
        $activity = "/api/v1/catalog/activities/{$f['activity']->id}";
        $offer = "/api/v1/catalog/visit-offers/{$f['offer']->id}";
        $campaign = "/api/v1/catalog/open-day-campaigns/{$f['campaign']->id}";
        $missing = (string) Str::uuid();
        $stale = ['version' => 999];

        return [
            'GET api/v1/me/teaching' => ['GET', '/api/v1/me/teaching', [], self::ALL, 200],
            'POST api/v1/enrollments/{enrollment}/visit-outcome' => ['POST', "/api/v1/enrollments/{$f['visit']->id}/visit-outcome", ['outcome' => 'attended'], self::VISIT_STAFF, 422],
            'GET api/v1/context/organization' => ['GET', '/api/v1/context/organization', [], self::ALL, 200],
            // The old form: a valid invitation reaches the check of the right; who may, invites.
            'POST api/v1/invitations' => ['POST', '/api/v1/invitations', ['email' => 'new-teacher@example.test', 'role' => 'instructor'], self::INVITE, 201],
            'GET api/v1/invitations' => ['GET', '/api/v1/invitations', [], self::INVITE, 200],
            'GET api/v1/audit-events' => ['GET', '/api/v1/audit-events', [], self::TEAM_VIEW, 200], // PR #80: only those who see the team,

            'GET api/v1/team' => ['GET', '/api/v1/team', [], self::TEAM_VIEW, 200],
            'GET api/v1/team/members/{member}' => ['GET', $member, [], self::TEAM_VIEW, 200],
            // New in PR 2 (docs/product/team-rights.md, т. 9): the card "Права"; a person without a login has none yet.
            'GET api/v1/team/members/{member}/access' => ['GET', "{$member}/access", [], self::TEAM_VIEW, 200],
            'PUT api/v1/team/members/{member}/access' => ['PUT', "{$member}/access", [], self::TEAM_MANAGE, 422],
            'POST api/v1/team/members' => ['POST', '/api/v1/team/members', [], self::TEAM_MANAGE, 422],
            'PATCH api/v1/team/members/{member}' => ['PATCH', $member, [], self::TEAM_MANAGE, 422],
            'POST api/v1/team/members/{member}/archive' => ['POST', "{$member}/archive", [], self::TEAM_MANAGE, 422],
            'POST api/v1/team/members/{member}/instructor-profile' => ['POST', "{$member}/instructor-profile", ['instructor_profile_id' => $missing], self::TEAM_MANAGE, 422],
            'POST api/v1/team/members/{member}/role' => ['POST', "{$member}/role", [], self::TEAM_MANAGE, 422],
            'POST api/v1/team/members/{member}/invitation' => ['POST', "{$member}/invitation", [], self::TEAM_MANAGE, 422],

            'GET api/v1/enrollment/applications' => ['GET', '/api/v1/enrollment/applications', [], self::ENROLLMENT_VIEW, 200],
            // New with "График" (docs/product/schedule.md, т. 3.5): the enrolled children by name, for the filter "Дете".
            'GET api/v1/enrollment/children' => ['GET', '/api/v1/enrollment/children?q='.urlencode('Ани'), [], self::ENROLLMENT_VIEW, 200],
            // New with the card of a child (docs/product/schedule.md, т. 4.1): every role that sees the children of the
            // group — the teacher as their own group. Without them it is 404, never 403 (asserted in ChildCardTest).
            'GET api/v1/enrollment/children/{child}' => ['GET', "/api/v1/enrollment/children/{$f['enrollment']->child_id}", [], self::ALL, 200],
            'GET api/v1/enrollment/groups' => ['GET', '/api/v1/enrollment/groups', [], self::ALL, 200],
            'GET api/v1/enrollment/groups/{group}/roster' => ['GET', "/api/v1/enrollment/groups/{$tuesday}/roster", [], self::ALL, 200],
            'GET api/v1/enrollment/enrollments/{enrollment}/transfer-options' => ['GET', "{$enrollment}/transfer-options", [], self::ENROLLMENT_MANAGE, 200],
            'GET api/v1/enrollment/applications/{application}' => ['GET', $application, [], self::ENROLLMENT_VIEW, 200],
            'GET api/v1/enrollment/enrollments/{enrollment}' => ['GET', $enrollment, [], self::ENROLLMENT_VIEW, 200],
            // New in PR 2 (т. 6): "Покажи телефона" for those who see contacts.
            'POST api/v1/enrollment/applications/{application}/contact/reveal' => ['POST', "{$application}/contact/reveal", [], self::CONTACTS, 200],
            'POST api/v1/enrollment/enrollments/{enrollment}/contact/reveal' => ['POST', "{$enrollment}/contact/reveal", [], self::CONTACTS, 200],
            'POST api/v1/enrollment/applications/{application}/review' => ['POST', "{$application}/review", [], self::ENROLLMENT_MANAGE, 422],
            // 31.10.2026: "catalog.manage" in the group instead of "enrollment.enroll" — the same roles.
            'PATCH api/v1/enrollment/groups/{group}/approval' => ['PATCH', "/api/v1/enrollment/groups/{$tuesday}/approval", [], self::CATALOG_MANAGE, 422],
            'POST api/v1/enrollment/enrollments/{enrollment}/transfer' => ['POST', "{$enrollment}/transfer", [], self::ENROLLMENT_MANAGE, 422],
            'POST api/v1/enrollment/enrollments/{enrollment}/pauses' => ['POST', "{$enrollment}/pauses", [], self::ENROLLMENT_MANAGE, 422],
            'POST api/v1/enrollment/enrollments/{enrollment}/pauses/{pause}/end' => ['POST', "{$enrollment}/pauses/{$missing}/end", [], self::ENROLLMENT_MANAGE, 422],
            'POST api/v1/enrollment/enrollments/{enrollment}/end' => ['POST', "{$enrollment}/end", [], self::ENROLLMENT_MANAGE, 422],
            'POST api/v1/enrollment/enrollments/{enrollment}/reopen' => ['POST', "{$enrollment}/reopen", [], self::ENROLLMENT_MANAGE, 422],
            'POST api/v1/enrollment/enrollments/{enrollment}/skipped-sessions' => ['POST', "{$enrollment}/skipped-sessions", [], self::ENROLLMENT_MANAGE, 422],
            'DELETE api/v1/enrollment/enrollments/{enrollment}/skipped-sessions/{session}' => ['DELETE', "{$enrollment}/skipped-sessions/{$f['session']->id}", [], self::ENROLLMENT_MANAGE, 422],

            // New with "График" (docs/product/schedule.md, т. 2, 6): every role sees the schedule of its scope — the teacher
            // the group they lead, which holds this session.
            'POST api/v1/attendance/sessions/{session}/changes' => ['POST', "/api/v1/attendance/sessions/{$f['session']->id}/changes", [], [...self::SCHEDULE_MANAGE, 'instructor'], 422],
            'GET api/v1/attendance/today' => ['GET', '/api/v1/attendance/today', [], array_values(array_diff(self::ALL, ['platform_admin'])), 200],
            'GET api/v1/attendance/pending' => ['GET', '/api/v1/attendance/pending', [], [...self::SCHEDULE_MANAGE, 'instructor'], 200],
            'GET api/v1/attendance/sessions/{session}' => ['GET', "/api/v1/attendance/sessions/{$f['session']->id}", [], array_values(array_diff(self::ALL, ['platform_admin'])), 200],
            'GET api/v1/schedule' => ['GET', '/api/v1/schedule?from=2026-11-02&to=2026-11-29', [], self::ALL, 200],
            'GET api/v1/schedule/sessions/{session}' => ['GET', "/api/v1/schedule/sessions/{$f['session']->id}", [], self::ALL, 200],
            // New with the changes of the schedule (docs/product/schedule-changes.md, т. 7.1): the right first, then the form.
            'POST api/v1/schedule/changes/preview' => ['POST', '/api/v1/schedule/changes/preview', [], self::SCHEDULE_MANAGE, 422],
            'POST api/v1/schedule/changes' => ['POST', '/api/v1/schedule/changes', [], self::SCHEDULE_MANAGE, 422],

            'GET api/v1/payment-terms' => ['GET', '/api/v1/payment-terms', [], self::TERMS_VIEW, 200],
            'POST api/v1/payment-terms' => ['POST', '/api/v1/payment-terms', [], self::TERMS_MANAGE, 422],
            'PATCH api/v1/payment-terms/{terms}' => ['PATCH', "/api/v1/payment-terms/{$missing}", [], self::TERMS_MANAGE, 404],

            'GET api/v1/catalog/activities' => ['GET', '/api/v1/catalog/activities', [], self::CATALOG_VIEW, 200],
            'GET api/v1/catalog/activities/{activity}' => ['GET', $activity, [], self::CATALOG_VIEW, 200],
            'GET api/v1/catalog/activities/{activity}/groups' => ['GET', "{$activity}/groups", [], self::CATALOG_VIEW, 200],
            'GET api/v1/catalog/media/{filename}' => ['GET', "/api/v1/catalog/media/{$missing}.jpg", [], self::CATALOG_VIEW, 404],
            'GET api/v1/catalog/profile' => ['GET', '/api/v1/catalog/profile', [], self::CATALOG_VIEW, 200],
            'GET api/v1/catalog/locations' => ['GET', '/api/v1/catalog/locations', [], self::CATALOG_VIEW, 200],
            'GET api/v1/catalog/instructors' => ['GET', '/api/v1/catalog/instructors', [], self::CATALOG_VIEW, 200],
            'GET api/v1/catalog/visit-offers' => ['GET', '/api/v1/catalog/visit-offers', [], self::CATALOG_VIEW, 200],
            'GET api/v1/catalog/visit-offers/{visitOffer}' => ['GET', $offer, [], self::CATALOG_VIEW, 200],
            'GET api/v1/catalog/open-day-campaigns' => ['GET', '/api/v1/catalog/open-day-campaigns', [], self::CATALOG_VIEW, 200],
            'GET api/v1/catalog/open-day-campaigns/{campaign}' => ['GET', $campaign, [], self::CATALOG_VIEW, 200],
            'POST api/v1/catalog/activities' => ['POST', '/api/v1/catalog/activities', [], self::CATALOG_MANAGE, 422],
            'PATCH api/v1/catalog/activities/{activity}' => ['PATCH', $activity, [], self::CATALOG_MANAGE, 422],
            'POST api/v1/catalog/activities/{activity}/publish' => ['POST', "{$activity}/publish", $stale, self::CATALOG_PUBLISH, 409],
            // New (docs/product/teacher-content.md, т. 5.6): a photo out of the gallery of a published activity.
            'POST api/v1/catalog/activities/{activity}/gallery/remove' => ['POST', "{$activity}/gallery/remove", [], self::CATALOG_MANAGE, 422],
            // The activity is published: its schedule is not edited here.
            'POST api/v1/catalog/activities/{activity}/groups' => ['POST', "{$activity}/groups", [], self::CATALOG_MANAGE, 409],
            'PATCH api/v1/catalog/groups/{group}' => ['PATCH', "/api/v1/catalog/groups/{$tuesday}", [], self::CATALOG_MANAGE, 409],
            'POST api/v1/catalog/groups/{group}/assignments' => ['POST', "/api/v1/catalog/groups/{$tuesday}/assignments", [], self::CATALOG_MANAGE, 422],
            'POST api/v1/catalog/groups/{group}/assignments/{instructor}/end' => ['POST', "/api/v1/catalog/groups/{$tuesday}/assignments/{$missing}/end", [], self::CATALOG_MANAGE, 422],
            'POST api/v1/catalog/media' => ['POST', '/api/v1/catalog/media', [], self::CATALOG_MANAGE, 422],
            'PATCH api/v1/catalog/profile' => ['PATCH', '/api/v1/catalog/profile', ['accent_color' => 'red'], self::CATALOG_MANAGE, 422],
            'POST api/v1/catalog/locations' => ['POST', '/api/v1/catalog/locations', [], self::CATALOG_MANAGE, 422],
            'PATCH api/v1/catalog/locations/{location}' => ['PATCH', "/api/v1/catalog/locations/{$f['location']->id}", ['type' => 'nowhere'], self::CATALOG_MANAGE, 422],
            'POST api/v1/catalog/instructors' => ['POST', '/api/v1/catalog/instructors', [], self::CATALOG_MANAGE, 422],
            'PATCH api/v1/catalog/instructors/{instructor}' => ['PATCH', "/api/v1/catalog/instructors/{$missing}", [], self::CATALOG_MANAGE, 404],
            'POST api/v1/catalog/visit-offers' => ['POST', '/api/v1/catalog/visit-offers', [], self::CATALOG_MANAGE, 422],
            'PATCH api/v1/catalog/visit-offers/{visitOffer}' => ['PATCH', $offer, [], self::CATALOG_MANAGE, 422],
            'POST api/v1/catalog/visit-offers/{visitOffer}/publish' => ['POST', "{$offer}/publish", $stale, self::CATALOG_PUBLISH, 409],
            'POST api/v1/catalog/visit-offers/{visitOffer}/close' => ['POST', "{$offer}/close", $stale, self::CATALOG_MANAGE, 409],
            'POST api/v1/catalog/visit-offers/{visitOffer}/cancel' => ['POST', "{$offer}/cancel", $stale, self::CATALOG_MANAGE, 409],
            'POST api/v1/catalog/open-day-campaigns' => ['POST', '/api/v1/catalog/open-day-campaigns', [], self::CATALOG_MANAGE, 422],
            'PATCH api/v1/catalog/open-day-campaigns/{campaign}' => ['PATCH', $campaign, [], self::CATALOG_MANAGE, 422],
            'POST api/v1/catalog/open-day-campaigns/{campaign}/publish' => ['POST', "{$campaign}/publish", $stale, self::CATALOG_PUBLISH, 409],
            'POST api/v1/catalog/open-day-campaigns/{campaign}/close' => ['POST', "{$campaign}/close", $stale, self::CATALOG_MANAGE, 409],
            'POST api/v1/catalog/open-day-campaigns/{campaign}/cancel' => ['POST', "{$campaign}/cancel", $stale, self::CATALOG_MANAGE, 409],

            // An active organization's application is no longer edited.
            'GET api/v1/organization-application' => ['GET', '/api/v1/organization-application', [], self::APPLICATION, 200],
            'PATCH api/v1/organization-application' => ['PATCH', '/api/v1/organization-application', [], self::APPLICATION, 409],
            'POST api/v1/organization-application/submit' => ['POST', '/api/v1/organization-application/submit', ['review_version' => 1], self::SUBMIT, 409],
        ];
    }
}
