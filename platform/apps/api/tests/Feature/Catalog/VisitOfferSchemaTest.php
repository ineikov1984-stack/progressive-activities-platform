<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Enums\VisitFeeType;
use App\Domain\Catalog\Enums\VisitOfferStatus;
use App\Domain\Catalog\Enums\VisitOfferType;
use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\OpenDayCampaign;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Models\VisitOffer;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class VisitOfferSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_visit_offer_enums_expose_the_approved_values(): void
    {
        $this->assertSame(['trial', 'single', 'open_day'], array_column(VisitOfferType::cases(), 'value'));
        $this->assertSame(['draft', 'published', 'closed', 'cancelled'], array_column(VisitOfferStatus::cases(), 'value'));
        $this->assertSame(['free', 'paid', 'from_price'], array_column(VisitFeeType::cases(), 'value'));
    }

    public function test_campaign_offer_and_session_relationships_round_trip_with_safe_defaults(): void
    {
        [$activity, $group, $session] = $this->catalogFixture();
        $campaign = OpenDayCampaign::query()->create([
            'slug' => 'autumn-open-day',
            'title' => 'Autumn open day',
            'starts_on' => '2026-11-03',
            'ends_on' => '2026-11-04',
        ]);
        $offer = VisitOffer::query()->create([
            'activity_id' => $activity->id,
            'group_id' => $group->id,
            'campaign_id' => $campaign->id,
            'type' => VisitOfferType::OpenDay,
            'title' => 'Try chess',
            'visit_limit' => 3,
        ]);
        $offer->sessions()->attach($session->id);

        $this->assertSame($activity->organization_id, $offer->organization_id);
        $this->assertSame($activity->id, $offer->activity->id);
        $this->assertSame($group->id, $offer->group->id);
        $this->assertSame($offer->id, $activity->visitOffers->sole()->id);
        $this->assertSame($offer->id, $group->visitOffers->sole()->id);
        $this->assertSame($offer->id, $session->visitOffers->sole()->id);
        $this->assertSame($session->id, $offer->sessions->sole()->id);
        $this->assertSame($offer->id, $campaign->visitOffers->sole()->id);
        $this->assertSame($campaign->id, $offer->campaign->id);
        $this->assertSame(VisitOfferStatus::Draft, $offer->status);
        $this->assertSame(VisitFeeType::Free, $offer->fee_type);
        $this->assertSame(0, $offer->amount_cents);
        $this->assertSame('EUR', $offer->currency);
        $this->assertSame(12, $offer->booking_deadline_hours);
        $this->assertSame(24, $offer->cancellation_deadline_hours);
        $this->assertSame(1, $offer->trial_limit_per_academic_year);
        $this->assertSame(2, $offer->single_visit_limit);
        $this->assertSame(30, $offer->single_visit_window_days);
        $this->assertSame(1, $offer->version);
        $this->assertTrue($offer->waitlist_enabled);
        $this->assertSame(VisitOfferStatus::Draft, $campaign->status);
        $this->assertSame(1, $campaign->version);

        $campaign->delete();
        $this->assertNull($offer->fresh()->campaign_id);
        $this->assertNotNull($offer->fresh());
    }

    public function test_database_rejects_invalid_limits_prices_and_missing_paid_explanations(): void
    {
        [$activity, $group] = $this->catalogFixture();
        $base = [
            'id' => (string) Str::uuid(),
            'organization_id' => $activity->organization_id,
            'activity_id' => $activity->id,
            'group_id' => $group->id,
            'type' => 'trial',
            'title' => 'Trial',
            'visit_limit' => 1,
        ];

        foreach ([
            ['visit_limit' => 0],
            ['amount_cents' => -1],
            ['fee_type' => 'paid', 'amount_cents' => 100, 'fee_reason' => null],
            ['fee_type' => 'from_price', 'amount_cents' => 100, 'fee_reason' => '  '],
            ['fee_type' => 'paid', 'amount_cents' => 0, 'fee_reason' => 'Materials'],
            ['type' => 'invented'],
            ['status' => 'invented'],
            ['version' => 0],
        ] as $invalid) {
            try {
                DB::table('visit_offers')->insert([...$base, ...$invalid, 'id' => (string) Str::uuid()]);
                $this->fail('Invalid visit-offer data was accepted: '.json_encode($invalid));
            } catch (QueryException) {
                $this->assertDatabaseCount('visit_offers', 0);
            }
        }
    }

    public function test_database_rejects_mismatched_activity_group_and_session(): void
    {
        [$activity, $group, $session] = $this->catalogFixture();
        [$otherActivity, $otherGroup, $otherSession] = $this->catalogFixture();
        app(TenantContext::class)->select(Organization::query()->withoutGlobalScopes()->findOrFail($activity->organization_id));

        try {
            VisitOffer::query()->create([
                'activity_id' => $activity->id,
                'group_id' => $otherGroup->id,
                'type' => 'trial',
                'title' => 'Invalid group',
                'visit_limit' => 1,
            ]);
            $this->fail('A group from another activity was accepted.');
        } catch (QueryException) {
            $this->assertDatabaseCount('visit_offers', 0);
        }

        $offer = VisitOffer::query()->create([
            'activity_id' => $activity->id,
            'group_id' => $group->id,
            'type' => 'trial',
            'title' => 'Valid offer',
            'visit_limit' => 1,
        ]);
        $offer->sessions()->attach($session->id);
        $this->assertCount(1, $offer->sessions);

        $this->expectException(QueryException::class);
        $offer->sessions()->attach($otherSession->id);
    }

    public function test_offer_cannot_change_groups_while_a_session_from_the_old_group_is_attached(): void
    {
        [$activity, $group, $session] = $this->catalogFixture();
        $otherGroup = $group->offering->groups()->create(['name' => 'Thursday']);
        $otherSession = $otherGroup->sessions()->create([
            'starts_at' => '2026-11-05 16:00:00+02',
            'ends_at' => '2026-11-05 17:00:00+02',
        ]);
        $offer = VisitOffer::query()->create([
            'activity_id' => $activity->id,
            'group_id' => $group->id,
            'type' => 'trial',
            'title' => 'Trial',
            'visit_limit' => 1,
        ]);
        $offer->sessions()->attach($session->id);

        try {
            $offer->update(['group_id' => $otherGroup->id]);
            $this->fail('A group change must not leave sessions from the old group attached.');
        } catch (QueryException) {
            $this->assertSame($group->id, $offer->fresh()->group_id);
            $this->assertSame($session->id, $offer->fresh()->sessions->sole()->id);
        }

        $offer->sessions()->detach($session->id);
        $offer->update(['group_id' => $otherGroup->id]);
        $offer->sessions()->attach($otherSession->id);
        $this->assertSame($otherSession->id, $offer->fresh()->sessions->sole()->id);
    }

    public function test_application_snapshot_columns_are_available(): void
    {
        foreach (['visit_offer_id', 'visit_offer_title_snapshot', 'fee_reason_snapshot', 'cancellation_policy_snapshot'] as $column) {
            $this->assertTrue(Schema::hasColumn('enrollment_applications', $column), $column);
        }
        $this->assertTrue(method_exists(EnrollmentApplication::class, 'visitOffer'));
    }

    /** @return array{Activity, Group, Session} */
    private function catalogFixture(): array
    {
        $organization = Organization::query()->withoutGlobalScopes()->create([
            'name' => 'Catalog organization',
            'slug' => (string) Str::uuid(),
            'status' => 'active',
        ]);
        app(TenantContext::class)->select($organization);
        $activity = Activity::query()->create([
            'slug' => (string) Str::uuid(),
            'title' => 'Chess',
            'category' => 'games',
            'summary' => 'Chess classes',
            'age_label' => '7+',
        ]);
        $offering = $activity->offerings()->create([]);
        $group = $offering->groups()->create(['name' => 'Tuesday']);
        $session = $group->sessions()->create([
            'starts_at' => '2026-11-03 16:00:00+02',
            'ends_at' => '2026-11-03 17:00:00+02',
        ]);

        return [$activity, $group, $session];
    }
}
