<?php

namespace Tests\Feature\Enrollment;

use App\Domain\Catalog\Models\OrganizationCatalogProfile;
use App\Domain\Enrollment\Actions\ReviewEnrollmentApplication;
use App\Domain\Enrollment\Actions\SubmitEnrollmentApplication;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Enrollment\Models\EnrollmentTransition;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\VisitOfferFixture;
use Tests\TestCase;

class ParentVisitOfferApplicationTest extends TestCase
{
    use RefreshDatabase, VisitOfferFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->travelTo('2026-10-28T10:00:00Z');
    }

    public static function submissionModes(): array
    {
        return [['visit'], ['full_period']];
    }

    #[DataProvider('submissionModes')]
    public function test_postgresql_application_role_can_submit_without_an_existing_tenant(string $mode): void
    {
        try {
            DB::connection('pgsql_admin')->getPdo();
        } catch (\Throwable) {
            $this->markTestSkipped('PostgreSQL is required for the application-role submission check.');
        }
        Artisan::call('migrate', ['--database' => 'pgsql_admin', '--force' => true]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('pgsql_admin');
        DB::beginTransaction();
        try {
            $migration = require database_path('migrations/2026_10_28_000620_require_published_enrollment_catalog.php');
            $definition = fn () => DB::selectOne("SELECT pg_get_functiondef('public.enrollment_catalog_tenant(uuid,uuid,uuid)'::regprocedure) AS definition")->definition;
            $migration->down();
            $this->assertStringNotContainsString('profile.is_published', $definition());
            $migration->up();
            $this->assertStringContainsString('profile.is_published', $definition());
            $this->assertStringContainsString('SECURITY DEFINER', $definition());
            $this->assertStringContainsString("SET search_path TO 'pg_catalog', 'public'", $definition());
            $this->assertStringContainsString('RETURNS uuid', $definition());
            $this->assertSame(0, (int) DB::selectOne("SELECT count(*) AS grants FROM pg_proc p, aclexplode(p.proacl) acl WHERE p.oid = 'public.enrollment_catalog_tenant(uuid,uuid,uuid)'::regprocedure AND acl.grantee = 0 AND acl.privilege_type = 'EXECUTE'")->grants);
            [$org, , $group, $session, $offer, $child] = $this->visitFixture([], ['enrollment_approval_mode' => 'automatic']);
            $secondChild = $child->owner->children()->create(['first_name' => 'Ben', 'last_name' => 'Smith']);
            $price = $group->offering->priceOptions()->create(['group_id' => $group->id, 'enrollment_mode' => 'monthly', 'unit' => 'month', 'amount_cents' => 4500, 'currency' => 'EUR', 'is_available' => true]);
            $payload = ['child_id' => $child->id, 'accepted_terms_version' => 'v1'];
            $payload += $mode === 'visit'
                ? ['visit_offer_id' => $offer->id, 'session_id' => $session->id]
                : ['group_id' => $group->id, 'price_option_id' => $price->id];
            [, , $draftGroup, , $draftOffer] = $this->visitFixture(['status' => 'draft'], ['status' => 'draft']);
            [$inactiveOrg, , $inactiveGroup, , $inactiveOffer] = $this->visitFixture();
            $inactiveOrg->update(['status' => 'suspended']);
            $foreignParent = User::factory()->create();
            [$hiddenOrg, , $hiddenGroup, $hiddenSession, $hiddenOffer] = $this->visitFixture();
            OrganizationCatalogProfile::query()->update(['is_published' => false]);
            app(TenantContext::class)->clear();
            DB::statement('SET LOCAL ROLE progressive_app');
            DB::select("SELECT set_config('app.current_organization_id', '', true)");
            $this->assertSame('progressive_app', DB::selectOne('SELECT current_user AS role')->role);
            $this->assertSame(0, DB::table('visit_offers')->where('id', $offer->id)->count());
            DB::select("SELECT set_config('app.current_user_id', ?, true)", [$child->owner_user_id]);
            foreach ([[$draftGroup->id, null], [null, $draftOffer->id], [$inactiveGroup->id, null], [null, $inactiveOffer->id], [$hiddenGroup->id, null], [null, $hiddenOffer->id], [(string) Str::uuid(), null], [null, (string) Str::uuid()]] as [$groupId, $offerId]) {
                $this->assertNull(DB::selectOne('SELECT public.enrollment_catalog_tenant(?::uuid, ?::uuid, ?::uuid) AS id', [$groupId, $offerId, $child->owner_user_id])->id);
            }
            $this->actingAs($child->owner)->withHeader('Idempotency-Key', 'hidden-catalog')
                ->postJson('/api/v1/parent/enrollment-applications', [
                    'child_id' => $child->id, 'visit_offer_id' => $hiddenOffer->id,
                    'session_id' => $hiddenSession->id, 'accepted_terms_version' => 'v1',
                ])->assertUnprocessable();
            $response = $this->actingAs($child->owner)->withHeader('Idempotency-Key', 'pg-'.$mode)
                ->postJson('/api/v1/parent/enrollment-applications', $payload)->assertCreated()
                ->assertJsonPath('application.organization_id', $org->id)->assertJsonPath('application.status', 'approved');
            if ($mode === 'visit') {
                DB::table('visit_offers')->where('id', $offer->id)->update(['status' => 'closed']);
            }
            DB::table('organization_catalog_profiles')->where('organization_id', $org->id)->update(['is_published' => false]);
            DB::select("SELECT set_config('app.current_organization_id', '', true)");
            $this->postJson('/api/v1/parent/enrollment-applications', $payload)->assertOk()
                ->assertJsonPath('application.id', $response->json('application.id'))
                ->assertJsonPath('application.enrollment_id', $response->json('application.enrollment_id'));
            $enrollmentId = $response->json('application.enrollment_id');
            $this->assertNotNull($enrollmentId);
            $this->assertSame($enrollmentId, DB::table('enrollments')->where('application_id', $response->json('application.id'))->value('id'));
            if ($mode === 'visit') {
                DB::table('visit_offers')->where('id', $offer->id)->update(['status' => 'published']);
            }
            // Existing ownership can resolve the tenant, but must not authorize a new unpublished booking.
            $this->withHeader('Idempotency-Key', 'new-unpublished-'.$mode)
                ->postJson('/api/v1/parent/enrollment-applications', [...$payload, 'child_id' => $secondChild->id])->assertUnprocessable();
            DB::select("SELECT set_config('app.current_organization_id', '', true)");
            $this->assertSame(0, DB::table('enrollments')->where('id', $enrollmentId)->count());
            if ($mode === 'visit') {
                $this->postJson('/api/v1/parent/enrollments/'.$enrollmentId.'/visit-outcome', ['outcome' => 'cancelled_in_time'])->assertOk();
                DB::select("SELECT set_config('app.current_organization_id', '', true)");
                DB::select("SELECT set_config('app.current_user_id', ?, true)", [$foreignParent->id]);
                $this->assertNull(DB::selectOne('SELECT public.enrollment_owned_tenant(?::uuid, ?::uuid) AS id', [$enrollmentId, $child->owner_user_id])->id);
                $this->assertNull(DB::selectOne('SELECT public.enrollment_owned_tenant(?::uuid, ?::uuid) AS id', [$enrollmentId, $foreignParent->id])->id);
            }
        } finally {
            DB::rollBack();
            DB::setDefaultConnection($original);
            app(TenantContext::class)->clear();
        }
    }

    public function test_hidden_or_missing_catalog_rejects_new_applications_but_preserves_owned_replay(): void
    {
        [$org, , , $session, $offer, $child] = $this->visitFixture();
        $payload = ['child_id' => $child->id, 'visit_offer_id' => $offer->id, 'session_id' => $session->id, 'accepted_terms_version' => 'v1'];
        $first = $this->actingAs($child->owner)->withHeader('Idempotency-Key', 'published')->postJson('/api/v1/parent/enrollment-applications', $payload)->assertCreated();
        app(TenantContext::class)->select($org);
        OrganizationCatalogProfile::query()->update(['is_published' => false]);
        $this->withHeader('Idempotency-Key', 'hidden')->postJson('/api/v1/parent/enrollment-applications', $payload)->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'published')->postJson('/api/v1/parent/enrollment-applications', $payload)->assertOk()->assertJsonPath('application.id', $first->json('application.id'));
        app(TenantContext::class)->select($org);
        OrganizationCatalogProfile::query()->delete();
        $this->withHeader('Idempotency-Key', 'missing')->postJson('/api/v1/parent/enrollment-applications', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('enrollment_applications', 1);
    }

    public function test_approved_response_replay_and_owned_listing_expose_only_the_owned_enrollment_id(): void
    {
        [$org, , , $session, $offer, $child] = $this->visitFixture([], ['enrollment_approval_mode' => 'automatic']);
        $payload = ['child_id' => $child->id, 'visit_offer_id' => $offer->id, 'session_id' => $session->id, 'accepted_terms_version' => 'v1'];
        $response = $this->actingAs($child->owner)->withHeader('Idempotency-Key', 'discover')->postJson('/api/v1/parent/enrollment-applications', $payload)->assertCreated();
        $id = $response->json('application.enrollment_id');
        $this->assertNotNull($id);
        $this->assertDatabaseHas('enrollments', ['id' => $id, 'application_id' => $response->json('application.id')]);
        $this->postJson('/api/v1/parent/enrollment-applications', $payload)->assertOk()->assertJsonPath('application.enrollment_id', $id);
        [, , $foreignGroup, $foreignSession, $foreignOffer, $foreignChild] = $this->visitFixture();
        $foreignEnrollment = $this->visitEnrollment($foreignChild, $foreignGroup, $foreignSession, 'trial', $foreignOffer);
        app(TenantContext::class)->clear();
        $this->getJson('/api/v1/parent/enrollment-applications')->assertOk()->assertJsonCount(1, 'applications')
            ->assertJsonPath('applications.0.enrollment_id', $id)->assertDontSee($foreignEnrollment->id);
        $this->actingAs($foreignChild->owner)->postJson('/api/v1/parent/enrollment-applications', $payload)->assertNotFound()->assertDontSee($id);
    }

    public function test_type_change_cannot_approve_two_submitted_trials_under_new_rules(): void
    {
        [$org, , , $session, $offer, $child] = $this->visitFixture(['visit_limit' => 1]);
        $payload = ['child_id' => $child->id, 'visit_offer_id' => $offer->id, 'session_id' => $session->id, 'accepted_terms_version' => 'v1'];
        $secondSession = $session->replicate();
        $secondSession->starts_at = $session->starts_at->addWeek();
        $secondSession->ends_at = $session->ends_at->addWeek();
        $secondSession->save();
        $offer->sessions()->attach($secondSession);
        $first = app(SubmitEnrollmentApplication::class)->handle($child->owner, $payload, 'first-trial');
        $second = app(SubmitEnrollmentApplication::class)->handle($child->owner, [...$payload, 'session_id' => $secondSession->id], 'second-trial');
        app(TenantContext::class)->select($org);
        $offer->update(['type' => 'single']);
        foreach ([$first, $second] as $application) {
            try {
                app(ReviewEnrollmentApplication::class)->handle($child->owner, $application, 'approve', 1, null, null);
                $this->fail('Review must reject a changed accepted offer type.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('visit_offer_id', $exception->errors());
            }
        }
        $this->assertDatabaseCount('enrollments', 0);
        $offer->update(['type' => 'trial']);
        app(ReviewEnrollmentApplication::class)->handle($child->owner, $first, 'approve', 1, null, null);
        try {
            app(ReviewEnrollmentApplication::class)->handle($child->owner, $second, 'approve', 1, null, null);
            $this->fail('Restoring the type must still enforce the original trial rule.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('trial_limit', $exception->errors());
        }
        $this->assertDatabaseCount('enrollments', 1);
    }

    public function test_single_and_open_day_types_are_distinct_accepted_snapshots(): void
    {
        [$org, , , $session, $offer, $child] = $this->visitFixture(['type' => 'single']);
        $application = app(SubmitEnrollmentApplication::class)->handle($child->owner, [
            'child_id' => $child->id, 'visit_offer_id' => $offer->id, 'session_id' => $session->id, 'accepted_terms_version' => 'v1',
        ], 'single-to-open-day');
        app(TenantContext::class)->select($org);
        $this->assertSame('single', $application->transitions()->firstOrFail()->metadata['visit_offer_type']);
        $offer->update(['type' => 'open_day']);
        $this->expectException(ValidationException::class);
        app(ReviewEnrollmentApplication::class)->handle($child->owner, $application, 'approve', 1, null, null);
    }

    public function test_manual_approval_enrollment_id_is_discoverable_by_owned_replay_and_listing(): void
    {
        [$org, , , $session, $offer, $child] = $this->visitFixture();
        $payload = ['child_id' => $child->id, 'visit_offer_id' => $offer->id, 'session_id' => $session->id, 'accepted_terms_version' => 'v1'];
        $response = $this->actingAs($child->owner)->withHeader('Idempotency-Key', 'manual-discover')
            ->postJson('/api/v1/parent/enrollment-applications', $payload)->assertCreated()->assertJsonPath('application.enrollment_id', null);
        app(TenantContext::class)->select($org);
        $application = EnrollmentApplication::findOrFail($response->json('application.id'));
        $approved = app(ReviewEnrollmentApplication::class)->handle($child->owner, $application, 'approve', 1, null, null);
        $id = $approved->enrollment->id;
        app(TenantContext::class)->clear();
        $this->postJson('/api/v1/parent/enrollment-applications', $payload)->assertOk()->assertJsonPath('application.enrollment_id', $id);
        $this->getJson('/api/v1/parent/enrollment-applications')->assertOk()->assertJsonPath('applications.0.enrollment_id', $id);
    }

    public function test_offer_alone_derives_scope_tenant_group_price_and_immutable_terms(): void
    {
        [$organization, , $group, $session, $offer, $child] = $this->visitFixture([
            'type' => 'single', 'title' => 'One chess class', 'fee_type' => 'paid',
            'amount_cents' => 1250, 'fee_reason' => 'Materials', 'terms_version' => 'visit-v2',
            'cancellation_policy' => 'Credit for another date', 'cancellation_outcome' => 'credit',
        ]);
        $payload = ['child_id' => $child->id, 'visit_offer_id' => $offer->id, 'session_id' => $session->id,
            'accepted_terms_version' => 'visit-v2', 'group_id' => (string) Str::uuid(),
            'price_option_id' => (string) Str::uuid(), 'scope' => 'full_period', 'amount_cents_snapshot' => 1];
        $first = $this->actingAs($child->owner)->withHeader('Idempotency-Key', 'offer-snapshot')
            ->postJson('/api/v1/parent/enrollment-applications', $payload)->assertCreated()
            ->assertJsonPath('application.organization_id', $organization->id)
            ->assertJsonPath('application.group_id', $group->id)
            ->assertJsonPath('application.scope', 'single')
            ->assertJsonPath('application.price_option_id', null)
            ->assertJsonPath('application.visit_offer_id', $offer->id)
            ->assertJsonPath('application.price.amount_cents', 1250)
            ->assertJsonPath('application.price.currency', 'EUR')
            ->assertJsonPath('application.price.unit', 'session')
            ->assertJsonPath('application.visit_offer_title_snapshot', 'One chess class')
            ->assertJsonPath('application.fee_reason_snapshot', 'Materials')
            ->assertJsonPath('application.cancellation_policy_snapshot', 'Credit for another date');
        app(TenantContext::class)->select($organization);
        $offer->update(['title' => 'Changed', 'amount_cents' => 2200, 'status' => 'closed']);
        $this->actingAs($child->owner)->withHeader('Idempotency-Key', 'offer-snapshot')
            ->postJson('/api/v1/parent/enrollment-applications', $payload)->assertOk()
            ->assertJsonPath('application.id', $first->json('application.id'))
            ->assertJsonPath('application.price.amount_cents', 1250);
        $this->assertDatabaseCount('enrollment_applications', 1);
        $transition = EnrollmentTransition::withoutGlobalScopes()->where('application_id', $first->json('application.id'))->firstOrFail();
        $this->assertSame(24, $transition->metadata['cancellation_deadline_hours']);
        $this->assertSame('credit', $transition->metadata['cancellation_outcome']);
    }

    public function test_unpublished_unselected_foreign_and_deadline_sessions_are_rejected(): void
    {
        [$org, , $group, $session, $offer, $child] = $this->visitFixture(['terms_version' => 'v1']);
        $payload = ['child_id' => $child->id, 'visit_offer_id' => $offer->id, 'session_id' => $session->id, 'accepted_terms_version' => 'v1'];
        foreach (['draft', 'closed', 'cancelled'] as $status) {
            app(TenantContext::class)->select($org);
            $offer->update(['status' => $status]);
            $this->actingAs($child->owner)->withHeader('Idempotency-Key', $status)->postJson('/api/v1/parent/enrollment-applications', $payload)
                ->assertUnprocessable()->assertJsonValidationErrors('visit_offer_id');
        }
        app(TenantContext::class)->select($org);
        $offer->update(['status' => 'published']);
        $otherSession = $group->sessions()->create(['starts_at' => '2026-11-04T14:00:00Z', 'ends_at' => '2026-11-04T15:00:00Z', 'status' => 'scheduled']);
        $this->actingAs($child->owner)->withHeader('Idempotency-Key', 'unselected')->postJson('/api/v1/parent/enrollment-applications', [...$payload, 'session_id' => $otherSession->id])->assertUnprocessable();
        [, , , $foreignSession] = $this->visitFixture();
        $this->actingAs($child->owner)->withHeader('Idempotency-Key', 'foreign')->postJson('/api/v1/parent/enrollment-applications', [...$payload, 'session_id' => $foreignSession->id])->assertUnprocessable();
        $this->travelTo('2026-11-03T02:00:00Z');
        $this->actingAs($child->owner)->withHeader('Idempotency-Key', 'deadline')->postJson('/api/v1/parent/enrollment-applications', $payload)->assertUnprocessable()->assertJsonValidationErrors('session_id');
        $this->travelTo('2026-11-03T01:59:59Z');
        $this->actingAs($child->owner)->withHeader('Idempotency-Key', 'before-deadline')->postJson('/api/v1/parent/enrollment-applications', $payload)->assertCreated();
    }

    public function test_offer_capacity_and_both_waitlist_flags_apply_to_automatic_and_manual_approval(): void
    {
        [$org, , $group, $session, $offer, $child] = $this->visitFixture(['visit_limit' => 1], ['enrollment_approval_mode' => 'automatic']);
        $this->visitEnrollment($child, $group, $session, 'trial', $offer);
        $other = $child->owner->children()->create(['first_name' => 'Ben', 'last_name' => 'Smith']);
        $payload = ['child_id' => $other->id, 'visit_offer_id' => $offer->id, 'session_id' => $session->id, 'accepted_terms_version' => 'v1'];
        $this->actingAs($child->owner)->withHeader('Idempotency-Key', 'full-offer')->postJson('/api/v1/parent/enrollment-applications', $payload)->assertCreated()->assertJsonPath('application.status', 'waitlisted');
        app(TenantContext::class)->select($org);
        $offer->update(['waitlist_enabled' => false]);
        $third = $child->owner->children()->create(['first_name' => 'Cora', 'last_name' => 'Smith']);
        $payload['child_id'] = $third->id;
        $this->actingAs($child->owner)->withHeader('Idempotency-Key', 'no-waitlist')->postJson('/api/v1/parent/enrollment-applications', $payload)->assertUnprocessable()->assertJsonValidationErrors('capacity');
        app(TenantContext::class)->select($org);
        $offer->update(['waitlist_enabled' => true]);
        $group->update(['enrollment_approval_mode' => 'manual']);
        // A submitted request does not reserve a place: the review must check again.
        $offer->update(['visit_limit' => 2]);
        $response = $this->actingAs($child->owner)->withHeader('Idempotency-Key', 'manual')->postJson('/api/v1/parent/enrollment-applications', $payload)->assertCreated()->assertJsonPath('application.status', 'submitted');
        app(TenantContext::class)->select($org);
        $offer->update(['visit_limit' => 1]);
        $application = EnrollmentApplication::findOrFail($response->json('application.id'));
        $reviewed = app(ReviewEnrollmentApplication::class)->handle($child->owner, $application, 'approve', 1, null, null);
        $this->assertSame('waitlisted', $reviewed->status->value);
        $this->assertDatabaseCount('enrollments', 1);
    }

    public function test_manual_approval_rechecks_eligibility_and_reasoned_override_is_audited(): void
    {
        [$org, , $group, $session, $offer, $child] = $this->visitFixture();
        $application = app(SubmitEnrollmentApplication::class)->handle($child->owner, [
            'child_id' => $child->id, 'visit_offer_id' => $offer->id, 'session_id' => $session->id, 'accepted_terms_version' => 'v1',
        ], 'manual-eligibility');
        app(TenantContext::class)->select($org);
        $past = $session->replicate();
        $past->starts_at = $session->starts_at->subWeek();
        $past->ends_at = $session->ends_at->subWeek();
        $past->save();
        $this->visitEnrollment($child, $group, $past, 'trial', $offer, 'ended');
        try {
            app(ReviewEnrollmentApplication::class)->handle($child->owner, $application, 'approve', 1, null, null);
            $this->fail('A second trial must be rejected at approval.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('trial_limit', $exception->errors());
        }
        $staff = User::factory()->create();
        OrganizationMembership::create(['organization_id' => $org->id, 'user_id' => $staff->id, 'role' => 'coordinator', 'status' => 'active']);
        $reviewed = app(ReviewEnrollmentApplication::class)->handle($staff, $application, 'approve', 1, null, null, 'Try the advanced class');
        $this->assertSame('approved', $reviewed->status->value);
        $transition = $reviewed->transitions()->where('to_status', 'approved')->firstOrFail();
        $this->assertSame('Try the advanced class', $transition->metadata['eligibility_override_reason']);
        $this->assertSame($staff->id, $transition->actor_user_id);
    }

    public function test_full_session_rejects_when_group_waitlist_is_disabled_and_stale_terms_are_rejected(): void
    {
        [$org, , $group, $session, $offer, $child] = $this->visitFixture(['terms_version' => 'v2'], ['waitlist_enabled' => false], ['capacity' => 1]);
        $other = $child->owner->children()->create(['first_name' => 'Ben', 'last_name' => 'Smith']);
        $this->visitEnrollment($other, $group, null, 'full_period', startsOn: '2026-11-01');
        $payload = ['child_id' => $child->id, 'visit_offer_id' => $offer->id, 'session_id' => $session->id, 'accepted_terms_version' => 'v1'];
        $this->actingAs($child->owner)->withHeader('Idempotency-Key', 'stale')->postJson('/api/v1/parent/enrollment-applications', $payload)->assertUnprocessable()->assertJsonValidationErrors('accepted_terms_version');
        $this->withHeader('Idempotency-Key', 'full')->postJson('/api/v1/parent/enrollment-applications', [...$payload, 'accepted_terms_version' => 'v2'])->assertUnprocessable()->assertJsonValidationErrors('capacity');
    }

    public function test_manual_eligibility_override_is_retained_when_capacity_requires_waitlisting(): void
    {
        [$org, , $group, $session, $offer, $child] = $this->visitFixture(['visit_limit' => 1]);
        $application = app(SubmitEnrollmentApplication::class)->handle($child->owner, [
            'child_id' => $child->id, 'visit_offer_id' => $offer->id, 'session_id' => $session->id, 'accepted_terms_version' => 'v1',
        ], 'waitlisted-override');
        app(TenantContext::class)->select($org);
        $past = $session->replicate();
        $past->starts_at = $session->starts_at->subWeek();
        $past->ends_at = $session->ends_at->subWeek();
        $past->save();
        $this->visitEnrollment($child, $group, $past, 'trial', $offer, 'ended');
        $other = $child->owner->children()->create(['first_name' => 'Ben', 'last_name' => 'Smith']);
        $this->visitEnrollment($other, $group, $session, 'trial', $offer);
        $staff = User::factory()->create();
        OrganizationMembership::create(['organization_id' => $org->id, 'user_id' => $staff->id, 'role' => 'coordinator', 'status' => 'active']);
        $reviewed = app(ReviewEnrollmentApplication::class)->handle($staff, $application, 'approve', 1, null, null, 'Another level');
        $this->assertSame('waitlisted', $reviewed->status->value);
        $transition = $reviewed->transitions()->where('to_status', 'waitlisted')->firstOrFail();
        $this->assertSame('Another level', $transition->metadata['eligibility_override_reason'] ?? null);
    }

    public function test_manual_approval_does_not_create_a_past_visit(): void
    {
        [$org, , , $session, $offer, $child] = $this->visitFixture();
        $application = app(SubmitEnrollmentApplication::class)->handle($child->owner, [
            'child_id' => $child->id, 'visit_offer_id' => $offer->id, 'session_id' => $session->id, 'accepted_terms_version' => 'v1',
        ], 'past-review');
        app(TenantContext::class)->select($org);
        $this->travelTo('2026-11-03T15:00:00Z');
        $this->expectException(ValidationException::class);
        app(ReviewEnrollmentApplication::class)->handle($child->owner, $application, 'approve', 1, null, null);
    }

    public function test_price_migration_round_trip_preserves_guards_and_refuses_data_loss(): void
    {
        $migration = require database_path('migrations/2026_10_28_000400_allow_offer_backed_enrollment_prices.php');
        $migration->down();
        $migration->up();
        [$org, , , $session, $offer, $child] = $this->visitFixture();
        $application = app(SubmitEnrollmentApplication::class)->handle($child->owner, [
            'child_id' => $child->id, 'visit_offer_id' => $offer->id, 'session_id' => $session->id, 'accepted_terms_version' => 'v1',
        ], 'migration');
        try {
            $migration->down();
            $this->fail('Rollback must preserve existing offer-backed applications.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Cannot roll back', $exception->getMessage());
        }
        $this->assertDatabaseHas('enrollment_applications', ['id' => $application->id, 'price_option_id' => null]);
        $this->expectException(QueryException::class);
        $application->update(['version' => 0]);
    }
}
