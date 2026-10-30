<?php

namespace Tests\Feature\Enrollment;

use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\OrganizationCatalogProfile;
use App\Domain\Catalog\Models\PriceOption;
use App\Domain\Catalog\Models\Session;
use App\Domain\Enrollment\Actions\RecordVisitOutcome;
use App\Domain\Enrollment\Actions\ReviewEnrollmentApplication;
use App\Domain\Enrollment\Models\Child;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Enrollment\Models\EnrollmentTransition;
use App\Domain\Enrollment\Services\EnrollmentCapacity;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Domain\PaymentTerms\Enums\TermsPreset;
use App\Domain\PaymentTerms\Rules\Presets;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Tests\Support\AcceptsPaymentTerms;
use Tests\TestCase;

/** Trials and single visits booked from a price and a date, without an offer (docs/product/visits.md). */
class VisitFromPriceTest extends TestCase
{
    use AcceptsPaymentTerms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->travelTo('2026-10-28T10:00:00Z');
    }

    public function test_a_parent_books_a_free_trial_from_the_price_and_a_date(): void
    {
        $f = $this->fixture(['enrollment_approval_mode' => 'automatic']);
        $trial = $this->price($f, 'trial', 0);

        $response = $this->book($f['parent'], $f['child'], $f['group'], $trial, $f['sessions'][0], 'free-trial', ['early_start' => true])
            ->assertCreated()
            ->assertJsonPath('application.scope', 'trial')
            ->assertJsonPath('application.status', 'approved')
            ->assertJsonPath('application.session_id', $f['sessions'][0]->id)
            ->assertJsonPath('application.price.amount_cents', 0)
            ->assertJsonPath('application.payment_terms.billing_kind', 'visit');

        $application = EnrollmentApplication::query()->withoutGlobalScopes()->findOrFail($response->json('application.id'));
        $this->assertNull($application->visit_offer_id);
        $this->assertSame('visit', $application->payment_terms_snapshot['billing_kind']);
        // A dated visit has no right of withdrawal, so no early start either.
        $this->assertFalse($application->payment_terms_snapshot['early_start']);
        $this->assertSame('2026-11-02T14:00:00+00:00', EnrollmentTransition::query()->withoutGlobalScopes()
            ->where('application_id', $application->id)->whereNotNull('metadata->session_starts_at')->firstOrFail()->metadata['session_starts_at']);
        $this->assertDatabaseHas('enrollments', [
            'application_id' => $application->id, 'session_id' => $f['sessions'][0]->id, 'scope' => 'trial',
            'status' => 'active', 'starts_on' => '2026-11-02',
        ]);
    }

    public function test_only_a_way_the_group_takes_and_an_open_date_of_the_group(): void
    {
        $f = $this->fixture();
        $single = $this->price($f, 'single', 1500);
        $other = $this->fixture();

        // The group does not take single visits.
        $this->inTenant($f['organization'], fn () => $f['group']->update(['enrollment_modes' => ['trial']]));
        $this->book($f['parent'], $f['child'], $f['group'], $single, $f['sessions'][0], 'not-taken')
            ->assertUnprocessable()->assertJsonValidationErrors('price_option_id');
        $this->inTenant($f['organization'], fn () => $f['group']->update(['enrollment_modes' => null]));

        // Another group's session, another organization's session.
        $sibling = $this->inTenant($f['organization'], fn () => $this->addGroup($f['group']->offering_id));
        $siblingSession = $this->inTenant($f['organization'], fn () => $this->addSession($sibling, '2026-11-03T14:00:00Z'));
        $this->book($f['parent'], $f['child'], $f['group'], $single, $siblingSession, 'other-group')
            ->assertUnprocessable()->assertJsonValidationErrors('session_id');
        $this->book($f['parent'], $f['child'], $f['group'], $single, $other['sessions'][0], 'other-organization')
            ->assertUnprocessable()->assertJsonValidationErrors('session_id');

        // Booking opens 60 days ahead…
        $far = $this->inTenant($f['organization'], fn () => $this->addSession($f['group'], '2026-12-28T14:00:00Z'));
        $this->book($f['parent'], $f['child'], $f['group'], $single, $far, 'too-early')
            ->assertUnprocessable()->assertJsonValidationErrors('session_id');
        // …and closes 2 hours before the start.
        $this->travelTo('2026-11-02T12:00:00Z');
        $this->book($f['parent'], $f['child'], $f['group'], $single, $f['sessions'][0], 'closed')
            ->assertUnprocessable()->assertJsonValidationErrors('session_id');
        $this->travelTo('2026-11-02T11:59:00Z');
        $this->book($f['parent'], $f['child'], $f['group'], $single, $f['sessions'][0], 'open')->assertCreated();
        // One child, one place in one session.
        $this->book($f['parent'], $f['child'], $f['group'], $single, $f['sessions'][0], 'twice')
            ->assertUnprocessable()->assertJsonValidationErrors('session_id');
        $this->assertSame(1, EnrollmentApplication::query()->withoutGlobalScopes()->count());
    }

    public function test_a_trial_is_once_a_school_year_by_any_route(): void
    {
        $f = $this->fixture();
        $trial = $this->price($f, 'trial', 1000);
        [$first, $second] = $f['sessions'];

        // A waiting application already holds the trial.
        $waiting = $this->book($f['parent'], $f['child'], $f['group'], $trial, $first, 'trial-1')->assertCreated()->json('application.id');
        $this->book($f['parent'], $f['child'], $f['group'], $trial, $second, 'trial-2')
            ->assertUnprocessable()->assertJsonValidationErrors('trial_limit');
        // Withdrawn — free again; approved and then cancelled in time — free again.
        $this->actingAs($f['parent'])->postJson('/api/v1/parent/enrollment-applications/'.$waiting.'/withdraw', ['version' => 1])->assertOk();
        $booked = $this->book($f['parent'], $f['child'], $f['group'], $trial, $first, 'trial-3')->assertCreated()->json('application.id');
        $application = $this->approve($f, $booked);
        $this->book($f['parent'], $f['child'], $f['group'], $trial, $second, 'trial-4')
            ->assertUnprocessable()->assertJsonValidationErrors('trial_limit');
        $this->inTenant($f['organization'], fn () => app(RecordVisitOutcome::class)
            ->handle($f['parent'], $application->enrollment, 'cancelled_in_time', null));
        $this->book($f['parent'], $f['child'], $f['group'], $trial, $second, 'trial-5')->assertCreated();

        // Another child, or the same child in the next school year, may still take one.
        $sibling = $f['parent']->children()->create(['first_name' => 'Боби', 'last_name' => 'Иванов']);
        $nextYear = $this->inTenant($f['organization'], fn () => $this->addSession($f['group'], '2027-09-06T14:00:00Z'));
        $this->book($f['parent'], $sibling, $f['group'], $trial, $first, 'sibling')->assertCreated();
        $this->travelTo('2027-08-20T10:00:00Z');
        $this->book($f['parent'], $f['child'], $f['group'], $trial, $nextYear, 'next-year')->assertCreated();
    }

    public function test_a_session_the_organization_cancelled_does_not_use_up_the_trial(): void
    {
        $f = $this->fixture(['enrollment_approval_mode' => 'automatic']);
        $trial = $this->price($f, 'trial', 1000);
        [$first, $second] = $f['sessions'];
        $this->book($f['parent'], $f['child'], $f['group'], $trial, $first, 'booked')->assertCreated()->assertJsonPath('application.status', 'approved');

        $this->inTenant($f['organization'], fn () => $first->update(['status' => 'cancelled']));
        $this->book($f['parent'], $f['child'], $f['group'], $trial, $second, 'again')->assertCreated();

        // A waiting application for a cancelled session is not approved.
        $g = $this->fixture();
        $gTrial = $this->price($g, 'trial', 1000);
        $waiting = $this->book($g['parent'], $g['child'], $g['group'], $gTrial, $g['sessions'][0], 'waiting')->assertCreated()->json('application.id');
        $this->inTenant($g['organization'], fn () => $g['sessions'][0]->update(['status' => 'cancelled']));
        $this->assertValidationError('session_id', fn () => $this->approve($g, $waiting));
        $this->book($g['parent'], $g['child'], $g['group'], $gTrial, $g['sessions'][1], 'after-cancel')->assertCreated();
    }

    #[TestGroup('tenant-isolation')]
    public function test_another_organizations_price_group_or_session_is_refused(): void
    {
        $f = $this->fixture();
        $single = $this->price($f, 'single', 1500);
        $other = $this->fixture();
        $otherSingle = $this->price($other, 'single', 900);

        // Our group with their price, or their group with our price and session.
        $this->book($f['parent'], $f['child'], $f['group'], $otherSingle, $f['sessions'][0], 'their-price')
            ->assertUnprocessable()->assertJsonValidationErrors('price_option_id');
        $this->book($f['parent'], $f['child'], $other['group'], $single, $f['sessions'][0], 'their-group')
            ->assertUnprocessable()->assertJsonValidationErrors('price_option_id');
        $this->book($f['parent'], $f['child'], $other['group'], $otherSingle, $f['sessions'][0], 'our-session')
            ->assertUnprocessable()->assertJsonValidationErrors('session_id');
        $this->assertSame(0, EnrollmentApplication::query()->withoutGlobalScopes()->count());
    }

    public function test_a_trial_from_an_offer_counts_and_another_activity_does_not(): void
    {
        $f = $this->fixture();
        $trial = $this->price($f, 'trial', 1000);
        // A trial already used from an offer in another group of the same activity.
        $sibling = $this->inTenant($f['organization'], fn () => $this->addGroup($f['group']->offering_id));
        $this->inTenant($f['organization'], fn () => $this->usedTrial($f['child'], $sibling, '2026-10-20', offer: true));
        $this->book($f['parent'], $f['child'], $f['group'], $trial, $f['sessions'][0], 'used')
            ->assertUnprocessable()->assertJsonValidationErrors('trial_limit');

        // A trial from an offer still waiting for the team counts too.
        $w = $this->fixture();
        $wTrial = $this->price($w, 'trial', 1000);
        $this->inTenant($w['organization'], fn () => $this->usedTrial($w['child'], $w['group'], '2026-11-05', offer: true, status: 'submitted'));
        $this->book($w['parent'], $w['child'], $w['group'], $wTrial, $w['sessions'][0], 'pending-offer')
            ->assertUnprocessable()->assertJsonValidationErrors('trial_limit');

        $g = $this->fixture();
        $gTrial = $this->price($g, 'trial', 1000);
        // Another activity of the same organization does not count.
        $this->inTenant($g['organization'], function () use ($g): void {
            $activity = Activity::query()->create([
                'slug' => (string) Str::uuid(), 'title' => 'Рисуване', 'category' => 'Изкуство',
                'summary' => 'Рисуване.', 'age_label' => '7+', 'status' => 'published',
            ]);
            $this->usedTrial($g['child'], $this->addGroup($activity->offerings()->create([])->id), '2026-10-20');
        });
        $this->book($g['parent'], $g['child'], $g['group'], $gTrial, $g['sessions'][0], 'other-activity')->assertCreated();
    }

    public function test_the_team_checks_again_on_approval_without_a_waiting_list(): void
    {
        $f = $this->fixture();
        $trial = $this->price($f, 'trial', 1000);
        $sibling = $this->inTenant($f['organization'], fn () => $this->addGroup($f['group']->offering_id));
        $id = $this->book($f['parent'], $f['child'], $f['group'], $trial, $f['sessions'][0], 'pending')->assertCreated()->json('application.id');
        // Meanwhile the child used a trial elsewhere in the activity.
        $this->inTenant($f['organization'], fn () => $this->usedTrial($f['child'], $sibling, '2026-10-29'));

        $this->assertValidationError('trial_limit', fn () => $this->approve($f, $id));
        // No waiting list for a visit from a price.
        $this->assertValidationError('decision', fn () => $this->inTenant($f['organization'], fn () => app(ReviewEnrollmentApplication::class)
            ->handle($f['staff'], EnrollmentApplication::query()->findOrFail($id), 'waitlist', 1, null, null)));
        // A full date is not put on a waiting list either: the team turns the application down.
        $full = $this->fixture(['capacity_max' => 1]);
        $fullSingle = $this->price($full, 'single', 1500);
        $pending = $this->book($full['parent'], $full['child'], $full['group'], $fullSingle, $full['sessions'][0], 'full')->assertCreated()->json('application.id');
        $this->inTenant($full['organization'], fn () => $this->usedTrial($full['parent']->children()->create([
            'first_name' => 'Вики', 'last_name' => 'Иванова',
        ]), $full['group'], '2026-11-02', $full['sessions'][0]));
        $this->assertValidationError('capacity', fn () => $this->approve($full, $pending));
        $this->assertSame('submitted', EnrollmentApplication::query()->withoutGlobalScopes()->findOrFail($pending)->status->value);

        // A date that has started cannot be approved.
        $other = $this->fixture();
        $otherTrial = $this->price($other, 'trial', 1000);
        $late = $this->book($other['parent'], $other['child'], $other['group'], $otherTrial, $other['sessions'][0], 'late')->assertCreated()->json('application.id');
        $this->travelTo('2026-11-02T14:00:00Z');
        $this->assertValidationError('session_id', fn () => $this->approve($other, $late));
    }

    public function test_places_for_visits_follow_the_session_and_the_group_limit(): void
    {
        $f = $this->fixture(['enrollment_approval_mode' => 'automatic', 'capacity_max' => 3, 'visit_places' => 1]);
        $single = $this->price($f, 'single', 1500);
        $second = $f['parent']->children()->create(['first_name' => 'Боби', 'last_name' => 'Иванов']);
        [$first, $later] = $f['sessions'];

        $this->book($f['parent'], $f['child'], $f['group'], $single, $first, 'first')->assertCreated();
        // One visitor a session: the next one picks another date.
        $this->book($f['parent'], $second, $f['group'], $single, $first, 'second')
            ->assertUnprocessable()->assertJsonValidationErrors('capacity');
        $this->book($f['parent'], $second, $f['group'], $single, $later, 'later')->assertCreated();

        // Every free place: the session's places are the limit.
        $this->inTenant($f['organization'], function () use ($f, $first): void {
            $f['group']->update(['visit_places' => null]);
            $this->assertSame(2, app(EnrollmentCapacity::class)->remainingForVisit($first));
            $f['group']->update(['visit_places' => 2]);
            $this->assertSame(1, app(EnrollmentCapacity::class)->remainingForVisit($first));
            // Visits from an offer have their own limit and do not count here.
            $this->usedTrial($f['parent']->children()->create([
                'first_name' => 'Вики', 'last_name' => 'Иванова',
            ]), $f['group'], '2026-11-02', $first, offer: true);
            $this->assertSame(1, app(EnrollmentCapacity::class)->remainingForVisit($first));
        });
    }

    public function test_cancellation_and_no_show_follow_the_copy_of_the_terms(): void
    {
        // The standard visit terms: at least 24 h before — nothing; 12–24 h — half; later — all; no-show — all.
        $f = $this->fixture(['enrollment_approval_mode' => 'automatic']);
        $single = $this->price($f, 'single', 1500);
        [$first, $second] = $f['sessions'];
        $sibling = $f['parent']->children()->create(['first_name' => 'Боби', 'last_name' => 'Иванов']);
        $key = $this->standardTermsVersion($single->id);

        $early = $this->enrollmentOf($this->book($f['parent'], $f['child'], $f['group'], $single, $first, 'early'));
        $late = $this->enrollmentOf($this->book($f['parent'], $sibling, $f['group'], $single, $first, 'late'));
        $absent = $this->enrollmentOf($this->book($f['parent'], $f['child'], $f['group'], $single, $second, 'absent'));

        $this->travelTo('2026-11-01T13:00:00Z'); // 25 h before
        $this->actingAs($f['parent'])->postJson('/api/v1/parent/enrollments/'.$early->id.'/visit-outcome', ['outcome' => 'cancelled_in_time'])
            ->assertOk()->assertJsonPath('outcome', 'cancelled_in_time');
        $this->assertOutcome($early, 'cancelled', $key);

        $this->travelTo('2026-11-02T00:00:00Z'); // 14 h before
        $this->actingAs($f['parent'])->postJson('/api/v1/parent/enrollments/'.$late->id.'/visit-outcome', ['outcome' => 'cancelled_in_time'])
            ->assertUnprocessable()->assertJsonValidationErrors('outcome');
        $this->actingAs($f['parent'])->postJson('/api/v1/parent/enrollments/'.$late->id.'/visit-outcome', ['outcome' => 'late_cancelled'])->assertOk();
        $this->assertOutcome($late, 'ended', $key);

        // After the start: only the team.
        $this->travelTo('2026-11-09T15:30:00Z');
        $this->actingAs($f['parent'])->postJson('/api/v1/parent/enrollments/'.$absent->id.'/visit-outcome', ['outcome' => 'late_cancelled'])
            ->assertUnprocessable();
        $this->inTenant($f['organization'], fn () => app(RecordVisitOutcome::class)->handle($f['staff'], $absent, 'no_show', null));
        $this->assertOutcome($absent, 'ended', $key);
    }

    public function test_free_cancellation_ends_with_the_last_step_that_keeps_nothing(): void
    {
        $f = $this->fixture(['enrollment_approval_mode' => 'automatic']);
        $single = $this->price($f, 'single', 1500);
        $sibling = $f['parent']->children()->create(['first_name' => 'Боби', 'last_name' => 'Иванов']);
        // Free until 24 h before (two free steps); afterwards everything is kept.
        $twoFree = $this->visitTerms($f, [['min_hours' => 48, 'kept' => 0], ['min_hours' => 24, 'kept' => 0], ['min_hours' => 0, 'kept' => 100]]);
        $booked = $this->enrollmentOf($this->book($f['parent'], $f['child'], $f['group'], $single, $f['sessions'][0], 'two-free', ['accepted_terms_version' => $twoFree]));
        // No free step at all: every cancellation is late.
        $neverFree = $this->visitTerms($f, [['min_hours' => 24, 'kept' => 50], ['min_hours' => 0, 'kept' => 100]]);
        $strict = $this->enrollmentOf($this->book($f['parent'], $sibling, $f['group'], $single, $f['sessions'][0], 'never-free', ['accepted_terms_version' => $neverFree]));

        $this->travelTo('2026-11-01T10:00:00Z'); // 28 h before
        $this->actingAs($f['parent'])->postJson('/api/v1/parent/enrollments/'.$booked->id.'/visit-outcome', ['outcome' => 'cancelled_in_time'])->assertOk();
        $this->assertOutcome($booked, 'cancelled', $twoFree);
        $this->actingAs($f['parent'])->postJson('/api/v1/parent/enrollments/'.$strict->id.'/visit-outcome', ['outcome' => 'cancelled_in_time'])
            ->assertUnprocessable()->assertJsonValidationErrors('outcome');
        $this->actingAs($f['parent'])->postJson('/api/v1/parent/enrollments/'.$strict->id.'/visit-outcome', ['outcome' => 'late_cancelled'])->assertOk();
        $this->assertOutcome($strict, 'ended', $neverFree);

        // A visit from a price booked before the copy existed follows the platform's standard terms (24 h).
        $legacy = $this->inTenant($f['organization'], fn () => $this->usedTrial(
            $f['parent']->children()->create(['first_name' => 'Вики', 'last_name' => 'Иванова']), $f['group'], '2026-11-02', $f['sessions'][0], status: 'active',
        ));
        $this->actingAs($f['parent'])->postJson('/api/v1/parent/enrollments/'.$legacy->id.'/visit-outcome', ['outcome' => 'cancelled_in_time'])->assertOk();
        $this->assertOutcome($legacy, 'cancelled', null);
    }

    public function test_a_changed_set_of_terms_asks_the_parent_to_look_again(): void
    {
        $f = $this->fixture();
        $single = $this->price($f, 'single', 1500);

        $this->book($f['parent'], $f['child'], $f['group'], $single, $f['sessions'][0], 'stale', ['accepted_terms_version' => 'v-old'])
            ->assertUnprocessable()->assertJsonValidationErrors('accepted_terms_version');
        $this->assertSame(0, EnrollmentApplication::query()->withoutGlobalScopes()->count());
    }

    public function test_the_public_page_lists_the_next_dates_with_places(): void
    {
        $f = $this->fixture(['capacity_max' => 2, 'visit_places' => 1, 'enrollment_approval_mode' => 'automatic']);
        $this->price($f, 'monthly', 6000, 'month');
        $trial = $this->price($f, 'trial', 0);
        [$first, $second] = $f['sessions'];
        // Closed: less than 2 hours to the start.
        $soon = $this->inTenant($f['organization'], fn () => $this->addSession($f['group'], '2026-10-28T11:30:00Z'));
        $this->book($f['parent'], $f['child'], $f['group'], $trial, $first, 'taken')->assertCreated();
        // A group without visits has no dates.
        $plain = $this->inTenant($f['organization'], function () use ($f): Group {
            $group = $this->addGroup($f['group']->offering_id, ['enrollment_modes' => ['monthly']]);
            $this->addSession($group, '2026-11-04T14:00:00Z');

            return $group;
        });

        $groups = collect($this->getJson('/api/v1/public/organizations/'.$f['organization']->slug.'/activities/'.$f['activity']->slug)
            ->assertOk()->assertJsonPath('activity.price.label', "60,00\u{00A0}€ / месец")->json('activity.groups'))->keyBy('id');
        $dates = collect($groups[$f['group']->id]['visit_dates']);
        $this->assertNotContains($soon->id, $dates->pluck('session_id'));
        $this->assertSame(0, $dates->firstWhere('session_id', $first->id)['remaining']);
        $this->assertSame(1, $dates->firstWhere('session_id', $second->id)['remaining']);
        $this->assertSame('Безплатно', collect($groups[$f['group']->id]['prices'])->firstWhere('enrollment_mode', 'trial')['label']);
        $this->assertSame([], $groups[$plain->id]['visit_dates']);
    }

    public function test_only_a_trial_may_be_free_and_visit_places_fit_the_group(): void
    {
        $f = $this->fixture();
        // Only a draft is edited.
        $this->inTenant($f['organization'], fn () => $f['activity']->update(['status' => 'draft']));
        $this->actingAs($f['staff'])->withSession(['active_organization_id' => $f['organization']->id]);
        $activityVersion = fn (): int => (int) $this->inTenant($f['organization'], fn () => $f['activity']->refresh()->version);
        $prices = fn (string $mode): array => ['offering' => ['price_options' => [
            ['enrollment_mode' => $mode, 'unit' => 'session', 'amount_cents' => 0],
        ]]];

        $this->patchJson('/api/v1/catalog/activities/'.$f['activity']->id, ['version' => $activityVersion(), ...$prices('single')])
            ->assertUnprocessable()->assertJsonValidationErrors('offering.price_options.0.amount_cents');
        $this->patchJson('/api/v1/catalog/activities/'.$f['activity']->id, ['version' => $activityVersion(), ...$prices('trial')])->assertOk();

        $groupVersion = fn (): int => (int) $this->inTenant($f['organization'], fn () => $f['group']->refresh()->version);
        $this->patchJson('/api/v1/catalog/groups/'.$f['group']->id, ['version' => $groupVersion(), 'visit_places' => 13])
            ->assertUnprocessable()->assertJsonValidationErrors('visit_places');
        $this->patchJson('/api/v1/catalog/groups/'.$f['group']->id, ['version' => $groupVersion(), 'visit_places' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('visit_places');
        $this->patchJson('/api/v1/catalog/groups/'.$f['group']->id, ['version' => $groupVersion(), 'visit_places' => 2])
            ->assertOk()->assertJsonPath('group.visit_places', 2);
        $this->patchJson('/api/v1/catalog/groups/'.$f['group']->id, ['version' => $groupVersion(), 'visit_places' => null])
            ->assertOk()->assertJsonPath('group.visit_places', null);
    }

    /** @return array{organization: Organization, activity: Activity, group: Group, sessions: list<Session>, parent: User, child: Child, staff: User} */
    private function fixture(array $groupAttributes = []): array
    {
        $organization = Organization::query()->withoutGlobalScopes()->create([
            'name' => 'Школа', 'slug' => (string) Str::uuid(), 'status' => 'active',
        ]);
        $staff = User::factory()->create();
        OrganizationMembership::query()->withoutGlobalScopes()->create([
            'organization_id' => $organization->id, 'user_id' => $staff->id, 'role' => 'organization_owner', 'status' => 'active',
        ]);

        return $this->inTenant($organization, function () use ($organization, $staff, $groupAttributes): array {
            OrganizationCatalogProfile::create(['public_name' => 'Школа', 'is_published' => true]);
            $activity = Activity::query()->create([
                'slug' => (string) Str::uuid(), 'title' => 'Шахмат', 'category' => 'Игри',
                'summary' => 'Шахмат.', 'age_label' => '7–10 г.', 'status' => 'published',
            ]);
            $group = $this->addGroup($activity->offerings()->create([])->id, $groupAttributes);
            $sessions = [$this->addSession($group, '2026-11-02T14:00:00Z'), $this->addSession($group, '2026-11-09T14:00:00Z')];
            $parent = User::factory()->create();
            $child = $parent->children()->create(['first_name' => 'Ния', 'last_name' => 'Иванова']);

            return compact('organization', 'activity', 'group', 'sessions', 'parent', 'child', 'staff');
        });
    }

    private function addGroup(string $offeringId, array $attributes = []): Group
    {
        return Group::query()->create([
            'offering_id' => $offeringId, 'name' => 'Група '.Str::random(4), 'capacity_min' => 1, 'capacity_max' => 12,
            'status' => 'published', 'schedule_pattern' => ['timezone' => 'Europe/Sofia'], ...$attributes,
        ]);
    }

    private function addSession(Group $group, string $startsAt): Session
    {
        return $group->sessions()->create([
            'starts_at' => $startsAt, 'ends_at' => date('c', strtotime($startsAt.' +1 hour')), 'status' => 'scheduled',
        ]);
    }

    /** A price of the activity (step 6), for every group that takes its way of enrolling. */
    private function price(array $f, string $mode, int $amount, string $unit = 'session'): PriceOption
    {
        return $this->inTenant($f['organization'], fn () => $f['group']->offering->priceOptions()->create([
            'enrollment_mode' => $mode, 'unit' => $unit, 'amount_cents' => $amount, 'currency' => 'EUR', 'is_available' => true,
        ]));
    }

    /**
     * A trial the child already has in the group, without a copy of the terms (from an offer, when asked): a used or
     * held place, or — with status "submitted" — an application waiting for the team.
     */
    private function usedTrial(Child $child, Group $group, string $day, ?Session $session = null, bool $offer = false, string $status = 'ended'): ?Enrollment
    {
        $session ??= $this->addSession($group, $day.'T14:00:00Z');
        $price = $group->offering->priceOptions()->create([
            'group_id' => $group->id, 'enrollment_mode' => 'trial', 'unit' => 'session', 'amount_cents' => 0, 'currency' => 'EUR', 'is_available' => true,
        ]);
        $visitOffer = $offer ? $group->offering->activity->visitOffers()->create([
            'group_id' => $group->id, 'type' => 'trial', 'status' => 'published', 'title' => 'Пробно', 'visit_limit' => 5, 'terms_version' => 'v1',
        ]) : null;
        $application = EnrollmentApplication::query()->create([
            'parent_user_id' => $child->owner_user_id, 'child_id' => $child->id, 'group_id' => $group->id,
            'price_option_id' => $price->id, 'session_id' => $session->id, 'visit_offer_id' => $visitOffer?->id, 'scope' => 'trial',
            'source' => 'organization_catalog', 'status' => $status === 'submitted' ? 'submitted' : 'approved', 'idempotency_key' => (string) Str::uuid(),
            'accepted_terms_version' => 'v1', 'accepted_terms_at' => now(), 'amount_cents_snapshot' => 0,
            'currency_snapshot' => 'EUR', 'unit_snapshot' => 'session',
        ]);
        if ($status === 'submitted') {
            return null;
        }

        return Enrollment::query()->create([
            'application_id' => $application->id, 'child_id' => $child->id, 'group_id' => $group->id,
            'price_option_id' => $price->id, 'session_id' => $session->id, 'scope' => 'trial', 'status' => $status, 'starts_on' => $day,
        ]);
    }

    /**
     * The organization's own default terms for visits; returns the version a parent accepts.
     *
     * @param  list<array{min_hours: int, kept: int}>  $tiers
     */
    private function visitTerms(array $f, array $tiers): string
    {
        return $this->actingAs($f['staff'])->withSession(['active_organization_id' => $f['organization']->id])
            ->postJson('/api/v1/payment-terms', [
                'billing_kind' => 'visit', 'name' => 'Условията на организацията '.Str::random(4), 'is_default' => true,
                'rules' => ['cancellation' => ['tiers' => $tiers], 'no_show_kept' => 100, 'refund' => 'parent_choice'],
                'self_service' => Presets::selfService(TermsPreset::Standard),
            ])->assertCreated()->json('payment_terms.current_version.id');
    }

    /** @param array<string, mixed> $extra */
    private function book(User $parent, Child $child, Group $group, PriceOption $price, Session $session, string $key, array $extra = []): TestResponse
    {
        return $this->actingAs($parent)->withHeader('Idempotency-Key', $key)->postJson('/api/v1/parent/enrollment-applications', [
            'child_id' => $child->id, 'group_id' => $group->id, 'price_option_id' => $price->id, 'session_id' => $session->id,
            'accepted_terms_version' => $this->standardTermsVersion($price->id), ...$extra,
        ]);
    }

    private function approve(array $f, string $applicationId): EnrollmentApplication
    {
        return $this->inTenant($f['organization'], fn () => app(ReviewEnrollmentApplication::class)
            ->handle($f['staff'], EnrollmentApplication::query()->findOrFail($applicationId), 'approve', 1, null, null)->load('enrollment'));
    }

    private function enrollmentOf(TestResponse $response): Enrollment
    {
        $response->assertCreated()->assertJsonPath('application.status', 'approved');

        return Enrollment::query()->withoutGlobalScopes()->where('application_id', $response->json('application.id'))->firstOrFail();
    }

    private function assertOutcome(Enrollment $enrollment, string $status, ?string $key): void
    {
        $this->assertSame($status, Enrollment::query()->withoutGlobalScopes()->findOrFail($enrollment->id)->status->value);
        $metadata = EnrollmentTransition::query()->withoutGlobalScopes()->where('enrollment_id', $enrollment->id)
            ->whereNotNull('metadata->outcome')->firstOrFail()->metadata;
        $this->assertSame($key, $metadata['terms_key']);
        $this->assertFalse($metadata['refund_performed']);
        $this->assertArrayNotHasKey('cancellation_outcome', $metadata);
    }

    private function assertValidationError(string $key, callable $action): void
    {
        try {
            $action();
            $this->fail("Expected a validation error for {$key}.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function inTenant(Organization $organization, callable $callback): mixed
    {
        app(TenantContext::class)->select($organization);
        try {
            return $callback();
        } finally {
            app(TenantContext::class)->clear();
        }
    }
}
