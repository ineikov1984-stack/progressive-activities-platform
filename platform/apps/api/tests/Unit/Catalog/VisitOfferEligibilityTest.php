<?php

namespace Tests\Unit\Catalog;

use App\Domain\Catalog\Services\VisitOfferEligibility;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\VisitOfferFixture;
use Tests\TestCase;

class VisitOfferEligibilityTest extends TestCase
{
    use RefreshDatabase, VisitOfferFixture;

    public function test_one_trial_per_activity_academic_year_with_organization_override(): void
    {
        [, , $group, $session, $offer, $child] = $this->visitFixture();
        $otherGroup = $group->offering->groups()->create(['name' => 'Thursday']);
        $otherSession = $otherGroup->sessions()->create([
            'starts_at' => '2026-09-12T14:00:00Z', 'ends_at' => '2026-09-12T15:00:00Z',
            'status' => 'scheduled',
        ]);
        $this->visitEnrollment($child, $otherGroup, $otherSession, 'trial', startsOn: '2026-09-12');

        try {
            app(VisitOfferEligibility::class)->assertEligible($child, $offer, $session);
            $this->fail('Second trial in the same academic year was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('trial_limit', $exception->errors());
        }

        $offer->update(['trial_limit_per_academic_year' => 2]);
        app(VisitOfferEligibility::class)->assertEligible($child, $offer->refresh(), $session);
    }

    public function test_trial_from_previous_academic_year_does_not_block_current_year(): void
    {
        [, , $group, $session, $offer, $child] = $this->visitFixture();
        $this->visitEnrollment($child, $group, $session, 'trial', $offer, startsOn: '2026-08-31');

        $this->expectNotToPerformAssertions();
        app(VisitOfferEligibility::class)->assertEligible($child, $offer, $session);
    }

    public function test_single_visits_use_a_rolling_30_day_window_and_configurable_limit(): void
    {
        [, , $group, $session, $offer, $child] = $this->visitFixture(['type' => 'single']);
        $this->visitEnrollment($child, $group, $session, 'single', $offer, startsOn: '2026-10-03');
        $this->visitEnrollment($child, $group, $session, 'single', $offer, startsOn: '2026-10-05');
        $this->visitEnrollment($child, $group, $session, 'single', $offer, startsOn: '2026-10-20');

        try {
            app(VisitOfferEligibility::class)->assertEligible($child, $offer, $session);
            $this->fail('Third single visit within 30 days was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('single_visit_limit', $exception->errors());
        }

        $offer->update(['single_visit_limit' => 3]);
        app(VisitOfferEligibility::class)->assertEligible($child, $offer->refresh(), $session);
        $offer->update(['single_visit_limit' => 2, 'unlimited_single_visits' => true]);
        app(VisitOfferEligibility::class)->assertEligible($child, $offer->refresh(), $session);
    }

    public function test_staff_override_requires_active_membership_and_a_nonblank_reason(): void
    {
        [$organization, , $group, $session, $offer, $child] = $this->visitFixture();
        $this->visitEnrollment($child, $group, $session, 'trial', $offer);
        $actor = $child->owner;

        foreach ([null, '', '  '] as $reason) {
            try {
                app(VisitOfferEligibility::class)->assertEligible($child, $offer, $session, $actor, $reason);
                $this->fail('An unreasoned override was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('trial_limit', $exception->errors());
            }
        }

        try {
            app(VisitOfferEligibility::class)->assertEligible($child, $offer, $session, $actor, 'Different level approved');
            $this->fail('A non-staff actor was allowed to override the trial limit.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('trial_limit', $exception->errors());
        }

        $organization->memberships()->create([
            'user_id' => $actor->id, 'role' => 'coordinator', 'status' => 'active',
        ]);
        app(VisitOfferEligibility::class)->assertEligible($child, $offer, $session, $actor, 'Different level approved');
    }

    public function test_foreign_tenant_history_does_not_block_or_expose_a_child(): void
    {
        [$organization, , , $session, $offer, $child] = $this->visitFixture();
        [, , $foreignGroup, $foreignSession, $foreignOffer] = $this->visitFixture();
        $this->visitEnrollment($child, $foreignGroup, $foreignSession, 'trial', $foreignOffer);

        app(TenantContext::class)->select($organization);
        $this->expectNotToPerformAssertions();
        app(VisitOfferEligibility::class)->assertEligible($child, $offer, $session);
    }
}
