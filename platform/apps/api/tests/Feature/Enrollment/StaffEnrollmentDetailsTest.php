<?php

namespace Tests\Feature\Enrollment;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\PriceOption;
use App\Domain\Enrollment\Models\Child;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Enrollment\Models\EnrollmentEvent;
use App\Domain\Enrollment\Models\EnrollmentTransition;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Tests\TestCase;

/** The "Подробности" panel (docs/product/enrollment-contacts-and-emails.md, т. 3, 8, 9.1). */
class StaffEnrollmentDetailsTest extends TestCase
{
    use RefreshDatabase;

    private const NOTE = 'Звъннете ми след 17:00, моля.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->travelTo('2026-10-28 10:00:00');
    }

    public function test_the_team_sees_the_child_the_parent_and_the_other_groups_here(): void
    {
        $f = $this->fixture();
        $coordinator = $this->member($f['organization'], 'coordinator');

        $response = $this->actAs($coordinator, $f['organization'])
            ->getJson("/api/v1/enrollment/applications/{$f['application']->id}")
            ->assertOk()
            ->assertJsonPath('kind', 'application')
            ->assertJsonPath('application.group.name', 'Група 1')
            ->assertJsonPath('application.parent_name', 'Мария Петрова')
            ->assertJsonPath('application.schedule', null)
            ->assertJsonPath('child.name', 'Ани Иванова')
            ->assertJsonPath('child.class_label', '2 клас')
            ->assertJsonPath('child.age', 8)
            ->assertJsonPath('child.school_name', 'СУ „Христо Ботев“')
            ->assertJsonPath('contact_status', 'shown')
            ->assertJsonPath('contact.name', 'Мария Петрова')
            ->assertJsonPath('contact.phone', '+359881234567')
            ->assertJsonPath('contact.phone_display', '088 123 4567')
            ->assertJsonPath('contact.email', $f['parent']->email)
            ->assertJsonPath('contact.email_verified', true)
            ->assertJsonPath('parent_note', self::NOTE);

        // "При нас": the other group here, with its next session — never the other organization's.
        $this->assertEquals([[
            'kind' => 'enrollment', 'group_name' => 'Група 2', 'status' => 'active',
            'starts_on' => '2026-11-01', 'next_session_at' => '2026-11-05T14:00:00+00:00',
        ]], array_map(fn (array $record): array => array_intersect_key($record, array_flip(['kind', 'group_name', 'status', 'starts_on', 'next_session_at'])), $response->json('records')));
        $this->assertStringNotContainsString('Плуване', (string) json_encode($response->json(), JSON_UNESCAPED_UNICODE));

        // History: what happened, by whom.
        $response->assertJsonPath('history.0.type', 'enrollment.application_submitted')
            ->assertJsonPath('history.0.actor.kind', 'parent');

        // The view is audited with identifiers and the role only.
        $audit = AuditEvent::query()->where('action', 'enrollment.contact_viewed')->sole();
        $this->assertSame($f['application']->id, $audit->subject_id);
        $this->assertSame(['role' => 'coordinator'], $audit->metadata);
        $stored = (string) json_encode($audit->getAttributes(), JSON_UNESCAPED_UNICODE);
        foreach (['881234567', $f['parent']->email, '17:00', 'Мария'] as $secret) {
            $this->assertStringNotContainsString($secret, $stored);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function readOnlyRoles(): iterable
    {
        yield 'finance' => ['finance'];
        yield 'viewer' => ['viewer'];
    }

    #[DataProvider('readOnlyRoles')]
    public function test_finance_and_viewers_see_the_groups_but_no_contacts_or_history(string $role): void
    {
        $f = $this->fixture();
        $member = $this->member($f['organization'], $role);

        $response = $this->actAs($member, $f['organization'])
            ->getJson("/api/v1/enrollment/applications/{$f['application']->id}")
            ->assertOk()
            ->assertJsonPath('contact_status', 'not_allowed')
            ->assertJsonPath('contact', null)
            ->assertJsonPath('parent_note', null)
            ->assertJsonPath('history', null)
            ->assertJsonMissingPath('application.parent_name')
            ->assertJsonMissingPath('child.age')
            ->assertJsonMissingPath('child.school_name')
            ->assertJsonCount(1, 'records');
        $this->assertStringNotContainsString('Мария', (string) json_encode($response->json(), JSON_UNESCAPED_UNICODE));
        $this->getJson("/api/v1/enrollment/enrollments/{$f['enrollment']->id}")
            ->assertOk()
            ->assertJsonPath('contact', null)
            ->assertJsonPath('history', null)
            ->assertJsonPath('enrollment.group.name', 'Група 2');
        $this->assertSame(0, AuditEvent::query()->where('action', 'enrollment.contact_viewed')->count());
    }

    public function test_the_platform_operator_sees_the_history_but_not_the_parent(): void
    {
        $f = $this->fixture();
        $operator = $this->member($f['organization'], 'platform_admin');

        $this->actAs($operator, $f['organization'])
            ->getJson("/api/v1/enrollment/applications/{$f['application']->id}")
            ->assertOk()
            ->assertJsonPath('contact_status', 'not_allowed')
            ->assertJsonPath('contact', null)
            ->assertJsonPath('parent_note', null)
            ->assertJsonPath('history.0.type', 'enrollment.application_submitted');
    }

    #[TestGroup('tenant-isolation')]
    public function test_instructors_are_refused_and_another_organization_is_not_found(): void
    {
        $f = $this->fixture();
        $this->actAs($this->member($f['organization'], 'instructor'), $f['organization'])
            ->getJson("/api/v1/enrollment/applications/{$f['application']->id}")->assertForbidden();

        $owner = $this->member($f['organization'], 'organization_owner');
        $this->actAs($owner, $f['organization'])
            ->getJson("/api/v1/enrollment/enrollments/{$f['foreignEnrollment']->id}")->assertNotFound();
        $this->getJson("/api/v1/enrollment/applications/{$f['foreignEnrollment']->application_id}")->assertNotFound();
        $this->assertSame(0, AuditEvent::query()->where('action', 'enrollment.contact_viewed')->count());
    }

    public function test_contacts_are_shown_only_inside_the_window(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['organization'], 'organization_owner');
        $this->actAs($owner, $f['organization']);
        $contactFor = fn (string $path): ?string => $this->getJson($path)->assertOk()->json('contact_status');

        $this->assertSame('shown', $contactFor("/api/v1/enrollment/enrollments/{$f['enrollment']->id}"));
        $this->withTenant($f['organization'], fn () => $f['application']->forceFill(['status' => 'rejected'])->save());
        $this->assertSame('outside_window', $contactFor("/api/v1/enrollment/applications/{$f['application']->id}"));
        $this->getJson("/api/v1/enrollment/applications/{$f['application']->id}")
            ->assertJsonPath('contact', null)->assertJsonPath('parent_note', null)->assertJsonMissingPath('application.parent_name');

        // Ended up to 90 days ago: still shown; longer ago or cancelled: not.
        $this->withTenant($f['organization'], fn () => $f['enrollment']->forceFill(['status' => 'ended', 'starts_on' => '2026-05-01', 'ends_on' => '2026-07-30'])->save());
        $this->assertSame('shown', $contactFor("/api/v1/enrollment/enrollments/{$f['enrollment']->id}"));
        $this->withTenant($f['organization'], fn () => $f['enrollment']->forceFill(['ends_on' => '2026-07-29'])->save());
        $this->assertSame('outside_window', $contactFor("/api/v1/enrollment/enrollments/{$f['enrollment']->id}"));
        $this->withTenant($f['organization'], fn () => $f['enrollment']->forceFill(['status' => 'cancelled', 'ends_on' => null])->save());
        $this->getJson("/api/v1/enrollment/enrollments/{$f['enrollment']->id}")
            ->assertJsonPath('contact_status', 'outside_window')->assertJsonPath('contact', null);

        $this->assertSame(2, AuditEvent::query()->where('action', 'enrollment.contact_viewed')->count());
    }

    public function test_a_visit_closes_the_window_with_its_session(): void
    {
        $f = $this->fixture();
        [$recent, $old, $stale] = $this->withTenant($f['organization'], function () use ($f): array {
            // A trial visit keeps its status after the outcome ("attended" = ended) and has no end date.
            $visit = function (string $day, string $status) use ($f): Enrollment {
                $session = $f['groupTwo']->sessions()->create(['starts_at' => "{$day}T14:00:00Z", 'ends_at' => "{$day}T15:00:00Z", 'status' => 'scheduled']);
                $application = EnrollmentApplication::query()->create([
                    'parent_user_id' => $f['parent']->id, 'child_id' => $f['child']->id, 'group_id' => $f['groupTwo']->id,
                    'price_option_id' => $f['priceTwo']->id, 'session_id' => $session->id, 'scope' => 'trial', 'source' => 'organization_catalog',
                    'status' => 'approved', 'idempotency_key' => fake()->uuid(), 'accepted_terms_version' => 'v1',
                    'accepted_terms_at' => now(), 'amount_cents_snapshot' => 1500, 'currency_snapshot' => 'EUR', 'unit_snapshot' => 'session',
                ]);

                return Enrollment::query()->create([
                    'application_id' => $application->id, 'child_id' => $f['child']->id, 'group_id' => $f['groupTwo']->id,
                    'price_option_id' => $f['priceTwo']->id, 'session_id' => $session->id, 'scope' => 'trial', 'status' => $status, 'starts_on' => $day,
                ]);
            };

            return [$visit('2026-08-15', 'ended'), $visit('2026-07-01', 'ended'), $visit('2026-06-01', 'active')];
        });
        $this->actAs($this->member($f['organization'], 'organization_owner'), $f['organization']);

        $this->getJson("/api/v1/enrollment/enrollments/{$recent->id}")->assertOk()->assertJsonPath('contact_status', 'shown');
        foreach ([$old, $stale] as $closed) {
            $this->getJson("/api/v1/enrollment/enrollments/{$closed->id}")->assertOk()
                ->assertJsonPath('contact_status', 'outside_window')->assertJsonPath('contact', null)->assertJsonPath('parent_note', null);
        }
        // "При нас" follows the same rule: the recent visit is there, the old ones are not.
        $ids = array_column($this->getJson("/api/v1/enrollment/enrollments/{$f['enrollment']->id}")->json('records'), 'id');
        $this->assertContains($recent->id, $ids);
        $this->assertNotContains($old->id, $ids);
        $this->assertNotContains($stale->id, $ids);
    }

    public function test_a_reason_the_parent_wrote_follows_the_contact_rules(): void
    {
        $f = $this->fixture();
        $this->withTenant($f['organization'], function () use ($f): void {
            $transition = EnrollmentTransition::query()->create([
                'enrollment_id' => $f['enrollment']->id, 'actor_user_id' => $f['parent']->id,
                'from_status' => 'active', 'to_status' => 'ended', 'public_reason' => 'Местим се в друг град.',
            ]);
            EnrollmentEvent::query()->create([
                'type' => 'enrollment.ended', 'application_id' => $f['enrollment']->application_id, 'enrollment_id' => $f['enrollment']->id,
                'payload' => ['last_day' => '2026-11-30', 'cancelled' => false, 'transition_id' => $transition->id], 'occurred_at' => now(),
            ]);
        });
        $path = "/api/v1/enrollment/enrollments/{$f['enrollment']->id}";

        $this->actAs($this->member($f['organization'], 'coordinator'), $f['organization'])->getJson($path)->assertOk()
            ->assertJsonPath('history.0.type', 'enrollment.ended')
            ->assertJsonPath('history.0.actor.kind', 'parent')
            ->assertJsonPath('history.0.details.last_day', '2026-11-30')
            ->assertJsonPath('history.0.public_reason', 'Местим се в друг град.');
        // The platform operator sees what happened, not the parent's words.
        $this->actAs($this->member($f['organization'], 'platform_admin'), $f['organization'])->getJson($path)->assertOk()
            ->assertJsonPath('history.0.type', 'enrollment.ended')
            ->assertJsonPath('history.0.public_reason', null);
    }

    public function test_an_enrollment_shows_what_comes_next_and_who_decided(): void
    {
        $f = $this->fixture();
        $coordinator = $this->member($f['organization'], 'coordinator');

        $this->actAs($coordinator, $f['organization'])
            ->postJson("/api/v1/enrollment/applications/{$f['application']->id}/review", ['decision' => 'approve', 'version' => 1])
            ->assertOk();
        $enrollmentId = $this->withTenant($f['organization'], fn () => Enrollment::query()->where('application_id', $f['application']->id)->value('id'));

        $response = $this->getJson("/api/v1/enrollment/enrollments/{$enrollmentId}")
            ->assertOk()
            ->assertJsonPath('kind', 'enrollment')
            ->assertJsonPath('enrollment.group.name', 'Група 1')
            ->assertJsonPath('enrollment.status', 'active')
            ->assertJsonPath('enrollment.next_session_at', '2026-11-03T14:00:00+00:00')
            ->assertJsonPath('enrollment.price.amount_cents', 4500)
            ->assertJsonPath('parent_note', self::NOTE)
            ->assertJsonPath('parent_note_at', '2026-10-28T10:00:00+00:00')
            ->assertJsonPath('history.0.type', 'enrollment.application_approved')
            ->assertJsonPath('history.0.actor', ['kind' => 'team', 'name' => $coordinator->name])
            ->assertJsonPath('history.1.type', 'enrollment.application_submitted');
        // The other group is "При нас"; this one is not repeated there.
        $this->assertSame(['Група 2'], array_column($response->json('records'), 'group_name'));
    }

    public function test_history_says_what_the_parent_was_told_and_keeps_the_internal_note_for_the_team(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['organization'], 'organization_owner');

        $this->actAs($owner, $f['organization'])
            ->postJson("/api/v1/enrollment/applications/{$f['application']->id}/review", [
                'decision' => 'reject', 'version' => 1,
                'public_reason' => 'Групата е за по-малки деца.', 'internal_note' => 'Предложи група 2 по телефона.',
            ])->assertOk();

        $this->getJson("/api/v1/enrollment/applications/{$f['application']->id}")
            ->assertOk()
            ->assertJsonPath('history.0.type', 'enrollment.application_rejected')
            ->assertJsonPath('history.0.public_reason', 'Групата е за по-малки деца.')
            ->assertJsonPath('history.0.internal_note', 'Предложи група 2 по телефона.')
            ->assertJsonPath('history.0.actor.kind', 'team');

        // An approval taken with the parent as actor is the group's automatic approval.
        $automatic = $this->withTenant($f['organization'], function () use ($f): EnrollmentApplication {
            $application = $this->application($f, $f['groupTwo'], $f['priceTwo'], 'approved');
            $transition = EnrollmentTransition::query()->create([
                'application_id' => $application->id, 'actor_user_id' => $f['parent']->id, 'from_status' => 'submitted', 'to_status' => 'approved',
            ]);
            EnrollmentEvent::query()->create([
                'type' => 'enrollment.application_approved', 'application_id' => $application->id,
                'payload' => ['status' => 'approved', 'transition_id' => $transition->id], 'occurred_at' => now(),
            ]);

            return $application;
        });
        $this->getJson("/api/v1/enrollment/applications/{$automatic->id}")
            ->assertOk()
            ->assertJsonPath('history.0.actor', ['kind' => 'automatic', 'name' => null]);
    }

    public function test_the_parents_name_is_listed_and_searched_only_for_those_who_see_contacts(): void
    {
        $f = $this->fixture();
        $coordinator = $this->member($f['organization'], 'coordinator');
        $finance = $this->member($f['organization'], 'finance');
        $search = '/api/v1/enrollment/applications?q='.urlencode('Петрова');

        $this->actAs($coordinator, $f['organization'])
            ->getJson('/api/v1/enrollment/applications')->assertOk()
            ->assertJsonPath('applications.0.parent_name', 'Мария Петрова');
        $this->getJson($search)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('applications.0.id', $f['application']->id);

        // For finance the field is missing and a parent's name finds nothing — the result would tell whose child it is.
        $this->actAs($finance, $f['organization'])
            ->getJson('/api/v1/enrollment/applications')->assertOk()
            ->assertJsonMissingPath('applications.0.parent_name');
        $this->getJson($search)->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/enrollment/applications?q='.urlencode('Ани'))->assertOk()->assertJsonPath('total', 1);

        // The same in a group's waiting list.
        $this->withTenant($f['organization'], fn () => $f['application']->forceFill(['status' => 'waitlisted'])->save());
        $this->actAs($coordinator, $f['organization'])
            ->getJson("/api/v1/enrollment/groups/{$f['groupOne']->id}/roster")->assertOk()
            ->assertJsonPath('waitlist.0.parent_name', 'Мария Петрова');
        $this->actAs($finance, $f['organization'])
            ->getJson("/api/v1/enrollment/groups/{$f['groupOne']->id}/roster")->assertOk()
            ->assertJsonMissingPath('waitlist.0.parent_name');
    }

    public function test_a_waiting_application_knows_its_place_in_the_queue(): void
    {
        $f = $this->fixture();
        $this->withTenant($f['organization'], function () use ($f): void {
            $this->travel(-1)->hours();
            $this->application($f, $f['groupOne'], $f['priceOne'], 'waitlisted', $this->otherChild($f['parent']));
            $this->travel(1)->hours();
            $f['application']->forceFill(['status' => 'waitlisted'])->save();
        });

        $this->actAs($this->member($f['organization'], 'coordinator'), $f['organization'])
            ->getJson("/api/v1/enrollment/applications/{$f['application']->id}")
            ->assertOk()
            ->assertJsonPath('application.queue_position', 2);
    }

    /**
     * Мария (synthetic) with Ани: an application with a note in Група 1, an enrollment in Група 2 from
     * 01.11, and an enrollment in another organization that must never show here.
     *
     * @return array{organization: Organization, parent: User, child: Child, groupOne: Group, groupTwo: Group, priceOne: PriceOption, priceTwo: PriceOption, application: EnrollmentApplication, enrollment: Enrollment, foreignEnrollment: Enrollment}
     */
    private function fixture(): array
    {
        $parent = User::factory()->create(['name' => 'Мария Петрова', 'phone' => '+359881234567']);
        $child = $parent->children()->create([
            'first_name' => 'Ани', 'last_name' => 'Иванова', 'class_label' => '2 клас',
            'school_name' => 'СУ „Христо Ботев“', 'birth_date' => '2018-05-14',
        ]);
        $organization = Organization::query()->withoutGlobalScopes()->create([
            'name' => 'Организация', 'slug' => fake()->unique()->slug(), 'status' => 'active',
        ]);
        $f = $this->withTenant($organization, function () use ($organization, $parent, $child): array {
            [$groupOne, $priceOne] = $this->group('Шах', 'Група 1', ['2026-10-27', '2026-11-03', '2026-11-10']);
            [$groupTwo, $priceTwo] = $this->group('Шах', 'Група 2', ['2026-10-29', '2026-11-05', '2026-11-12']);
            $f = compact('organization', 'parent', 'child', 'groupOne', 'groupTwo', 'priceOne', 'priceTwo');
            $application = $this->application($f, $groupOne, $priceOne, 'submitted');
            $application->forceFill(['parent_note' => self::NOTE])->save();
            EnrollmentEvent::query()->create([
                'type' => 'enrollment.application_submitted', 'application_id' => $application->id,
                'payload' => ['status' => 'submitted'], 'occurred_at' => now()->subDay(),
            ]);
            $approved = $this->application($f, $groupTwo, $priceTwo, 'approved');
            $enrollment = Enrollment::query()->create([
                'application_id' => $approved->id, 'child_id' => $child->id, 'group_id' => $groupTwo->id,
                'price_option_id' => $priceTwo->id, 'scope' => 'full_period', 'status' => 'active', 'starts_on' => '2026-11-01',
            ]);

            return [...$f, 'application' => $application, 'enrollment' => $enrollment];
        });

        $foreign = Organization::query()->withoutGlobalScopes()->create([
            'name' => 'Друга', 'slug' => fake()->unique()->slug(), 'status' => 'active',
        ]);
        $f['foreignEnrollment'] = $this->withTenant($foreign, function () use ($parent, $child): Enrollment {
            [$group, $price] = $this->group('Плуване', 'Басейн', ['2026-11-02']);
            $application = $this->application(['parent' => $parent, 'child' => $child], $group, $price, 'approved');

            return Enrollment::query()->create([
                'application_id' => $application->id, 'child_id' => $child->id, 'group_id' => $group->id,
                'price_option_id' => $price->id, 'scope' => 'full_period', 'status' => 'active', 'starts_on' => '2026-11-01',
            ]);
        });

        return $f;
    }

    /**
     * @param  list<string>  $dates
     * @return array{Group, PriceOption}
     */
    private function group(string $title, string $name, array $dates): array
    {
        $activity = Activity::query()->create([
            'slug' => fake()->unique()->slug(), 'title' => $title, 'category' => 'Наука',
            'summary' => 'Описание', 'age_label' => '1.–4. клас', 'status' => 'published',
        ]);
        $offering = $activity->offerings()->create([]);
        $group = $offering->groups()->create([
            'name' => $name, 'capacity_max' => 10, 'status' => 'published', 'waitlist_enabled' => true, 'enrollment_approval_mode' => 'manual',
        ]);
        foreach ($dates as $date) {
            $group->sessions()->create(['starts_at' => "{$date}T14:00:00Z", 'ends_at' => "{$date}T15:00:00Z", 'status' => 'scheduled']);
        }
        $price = $offering->priceOptions()->create([
            'group_id' => $group->id, 'enrollment_mode' => 'monthly', 'unit' => 'month', 'amount_cents' => 4500, 'currency' => 'EUR', 'is_available' => true,
        ]);

        return [$group, $price];
    }

    /** @param  array<string, mixed>  $f */
    private function application(array $f, Group $group, PriceOption $price, string $status, ?Child $child = null): EnrollmentApplication
    {
        return EnrollmentApplication::query()->create([
            'parent_user_id' => $f['parent']->id, 'child_id' => ($child ?? $f['child'])->id, 'group_id' => $group->id,
            'price_option_id' => $price->id, 'scope' => 'full_period', 'source' => 'organization_catalog',
            'status' => $status, 'idempotency_key' => fake()->uuid(), 'accepted_terms_version' => 'v1',
            'accepted_terms_at' => now(), 'amount_cents_snapshot' => 4500, 'currency_snapshot' => 'EUR', 'unit_snapshot' => 'month',
        ]);
    }

    private function otherChild(User $parent): Child
    {
        return $parent->children()->create(['first_name' => 'Боби', 'last_name' => 'Иванов']);
    }

    private function actAs(User $user, Organization $organization): static
    {
        return $this->actingAs($user)->withSession(['active_organization_id' => $organization->id]);
    }

    private function member(Organization $organization, string $role): User
    {
        $user = User::factory()->create();
        OrganizationMembership::query()->withoutGlobalScopes()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active',
        ]);

        return $user;
    }

    /**
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
