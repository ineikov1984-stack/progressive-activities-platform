<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\OpenDayCampaign;
use App\Domain\Catalog\Models\OrganizationCatalogProfile;
use App\Domain\Catalog\Models\VisitOffer;
use App\Domain\Enrollment\Models\Child;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\VisitOfferFixture;
use Tests\TestCase;

class PublicVisitOfferTest extends TestCase
{
    use RefreshDatabase, VisitOfferFixture;

    public function test_card_has_a_separate_audience_and_only_computed_availability_and_one_special_label(): void
    {
        $this->travelTo('2026-10-28T10:00:00Z');
        [$organization, $activity, $group, $session, $offer] = $this->visitFixture(
            ['visit_limit' => 8, 'audience_age_label' => '6–8 г.', 'audience_class_label' => 'I–II клас'],
            ['capacity_max' => 8], ['capacity' => 8],
        );
        $other = $this->offer($offer, ['type' => 'open_day', 'title' => 'Open doors']);
        $other->sessions()->attach($session->id);
        $campaign = OpenDayCampaign::query()->create([
            'slug' => 'autumn-campaign', 'title' => 'Autumn campaign', 'status' => 'published',
        ]);
        $other->update(['campaign_id' => $campaign->id]);

        $card = $this->getJson($this->base($organization->slug).'/catalog')->assertOk()->json('activities.0');
        $this->assertSame('6–8 г. · I–II клас', $card['audience']);
        $this->assertSame(['status' => 'available', 'remaining_places' => 8], $card['availability']);
        $this->assertSame('open_day', $card['special_offer']);
        $this->assertSame('/o/'.$organization->slug.'/open-days/autumn-campaign', $card['special_offer_url']);
        $this->assertCount(2, array_filter([$card['availability']['status'], $card['special_offer']]));
        $this->assertArrayNotHasKey('badges', $card);

        $detailRows = $this->getJson($this->base($organization->slug).'/activities/'.$activity->slug)
            ->assertOk()->json('activity.visit_offers');
        $this->assertNull(collect($detailRows)->firstWhere('visit_offer_id', $offer->id)['campaign_slug']);
        $this->assertSame('autumn-campaign', collect($detailRows)
            ->firstWhere('visit_offer_id', $other->id)['campaign_slug']);
        $campaign->update(['status' => 'closed']);
        $closedResponse = $this->getJson($this->base($organization->slug).'/activities/'.$activity->slug)->assertOk();
        $closedResponse->assertJsonPath('activity.special_offer_url', null);
        $this->assertStringNotContainsString('autumn-campaign', $closedResponse->getContent());

        $offer->update(['audience_class_label' => null]);
        $other->update(['status' => 'cancelled']);
        $card = $this->getJson($this->base($organization->slug).'/catalog')->assertOk()->json('activities.0');
        $this->assertSame('6–8 г.', $card['audience']);
        $this->assertSame('trial', $card['special_offer']);
    }

    public function test_detail_uses_all_five_capacity_statuses_and_retains_a_full_waitlist_offer(): void
    {
        $this->travelTo('2026-10-28T10:00:00Z');
        [$organization, $activity, $group, $session, $offer, $child] = $this->visitFixture(
            ['visit_limit' => 8, 'booking_deadline_hours' => 0], ['capacity_max' => 8], ['capacity' => 8],
        );
        $url = $this->base($organization->slug).'/activities/'.$activity->slug;
        $this->getJson($url)->assertOk()
            ->assertJsonPath('activity.visit_offers.0.visit_offer_id', $offer->id)
            ->assertJsonPath('activity.visit_offers.0.session_id', $session->id)
            ->assertJsonPath('activity.visit_offers.0.availability.status', 'available')
            ->assertJsonPath('activity.visit_offers.0.action', 'apply');

        app(TenantContext::class)->select($organization);
        for ($i = 0; $i < 5; $i++) {
            $this->visitEnrollment($this->visitor($child, $i), $group, $session, 'trial', $offer);
        }
        $this->getJson($url)->assertOk()->assertJsonPath('activity.visit_offers.0.availability.status', 'limited');
        app(TenantContext::class)->select($organization);
        for ($i = 5; $i < 7; $i++) {
            $this->visitEnrollment($this->visitor($child, $i), $group, $session, 'trial', $offer);
        }
        $this->getJson($url)->assertOk()->assertJsonPath('activity.visit_offers.0.availability.status', 'last_place');
        app(TenantContext::class)->select($organization);
        $this->visitEnrollment($child, $group, $session, 'trial', $offer);
        $this->getJson($url)->assertOk()
            ->assertJsonPath('activity.visit_offers.0.availability.status', 'full')
            ->assertJsonPath('activity.visit_offers.0.availability.waitlist_available', true)
            ->assertJsonPath('activity.visit_offers.0.action', 'waitlist');
        $offer->update(['booking_deadline_hours' => 200]);
        $this->getJson($url)->assertOk()
            ->assertJsonPath('activity.visit_offers.0.availability.status', 'closed')
            ->assertJsonPath('activity.visit_offers.0.action', 'closed');
    }

    public function test_detail_excludes_draft_stale_cancelled_offers_and_private_fields(): void
    {
        $this->travelTo('2026-10-28T10:00:00Z');
        [$organization, $activity, $group, $session, $offer] = $this->visitFixture([
            'type' => 'single', 'fee_type' => 'paid', 'amount_cents' => 1250, 'fee_reason' => 'Materials',
            'cancellation_policy' => 'Credit before deadline', 'cancellation_outcome' => 'credit',
            'audience_age_label' => '7–9 г.',
        ]);
        $draft = $this->offer($offer, ['status' => 'draft', 'title' => 'Private draft']);
        $draft->sessions()->attach($session->id);
        $cancelled = $this->offer($offer, ['status' => 'cancelled', 'title' => 'Private cancelled']);
        $cancelled->sessions()->attach($session->id);
        $stale = $this->offer($offer, ['title' => 'Private stale']);
        $past = $group->sessions()->create([
            'starts_at' => '2026-10-01T14:00:00Z', 'ends_at' => '2026-10-01T15:00:00Z', 'status' => 'scheduled',
        ]);
        $stale->sessions()->attach($past->id);
        $response = $this->getJson($this->base($organization->slug).'/activities/'.$activity->slug)->assertOk()
            ->assertJsonCount(1, 'activity.visit_offers')
            ->assertJsonPath('activity.visit_offers.0.fee_reason', 'Materials')
            ->assertJsonPath('activity.visit_offers.0.cancellation_policy', 'Credit before deadline')
            ->assertJsonPath('activity.visit_offers.0.booking_deadline_hours', 12);
        $row = $response->json('activity.visit_offers.0');
        $this->assertSame($offer->id, $row['visit_offer_id']);
        $this->assertSame($session->id, $row['session_id']);
        $this->assertSame('2026-11-03T14:00:00+00:00', $row['starts_at']);
        foreach (['organization_id', 'version', 'applications', 'internal_note', 'audit_events', 'override_reason', 'location', 'image_path'] as $private) {
            $this->assertArrayNotHasKey($private, $row);
        }
        $this->assertStringNotContainsString('Private', $response->getContent());
    }

    public function test_campaign_groups_by_actual_session_day_and_activity_with_independent_prices_and_availability(): void
    {
        $this->travelTo('2026-10-28T10:00:00Z');
        [$organization, $activity, $group, $session, $offer] = $this->visitFixture([
            'type' => 'open_day', 'fee_type' => 'free', 'visit_limit' => 1,
        ], ['capacity_max' => 8], ['capacity' => 8]);
        $campaign = OpenDayCampaign::query()->create([
            'slug' => 'autumn-open-day', 'title' => 'Autumn open day', 'description' => 'Meet us',
            'starts_on' => '2026-11-01', 'ends_on' => '2026-11-02', 'status' => 'published',
        ]);
        $offer->update(['campaign_id' => $campaign->id]);
        $later = $group->sessions()->create([
            'starts_at' => '2026-11-04T14:00:00Z', 'ends_at' => '2026-11-04T15:00:00Z',
            'capacity' => 8, 'status' => 'scheduled',
        ]);
        $paid = $this->offer($offer, [
            'title' => 'Paid visit', 'type' => 'single', 'fee_type' => 'paid', 'amount_cents' => 1250,
            'fee_reason' => 'Materials', 'visit_limit' => 2,
        ]);
        $paid->sessions()->attach($later->id);
        $hidden = $this->offer($offer, ['title' => 'Hidden draft', 'status' => 'draft']);
        $hidden->sessions()->attach($session->id);

        $dance = Activity::query()->create([
            'slug' => 'dance', 'title' => 'Dance', 'category' => 'movement',
            'summary' => 'Dance classes', 'age_label' => '6+', 'status' => 'published',
        ]);
        $danceGroup = $dance->offerings()->create([])->groups()->create([
            'name' => 'Dance group', 'capacity_max' => 8, 'status' => 'published',
            'schedule_pattern' => ['timezone' => 'Europe/Sofia'],
        ]);
        $danceSession = $danceGroup->sessions()->create([
            'starts_at' => '2026-11-03T16:00:00Z', 'ends_at' => '2026-11-03T17:00:00Z',
            'capacity' => 8, 'status' => 'scheduled',
        ]);
        $danceOffer = $this->offer($offer, [
            'activity_id' => $dance->id, 'group_id' => $danceGroup->id, 'title' => 'Try dance',
            'visit_limit' => 1,
        ]);
        $danceOffer->sessions()->attach($danceSession->id);

        $url = $this->base($organization->slug).'/open-days/'.$campaign->slug;
        $response = $this->getJson($url)->assertOk()
            ->assertJsonPath('campaign.starts_on', '2026-11-01')
            ->assertJsonPath('campaign.ends_on', '2026-11-02')
            ->assertJsonCount(2, 'campaign.days')
            ->assertJsonPath('campaign.days.0.date', '2026-11-03')
            ->assertJsonPath('campaign.days.1.date', '2026-11-04')
            ->assertJsonPath('campaign.days.1.activities.0.visit_offers.0.visit_offer_id', $paid->id)
            ->assertJsonPath('campaign.days.1.activities.0.visit_offers.0.amount_cents', 1250)
            ->assertJsonPath('campaign.days.1.activities.0.visit_offers.0.availability.status', 'limited')
            ->assertJsonMissing(['title' => 'Hidden draft']);
        $firstDayActivities = $response->json('campaign.days.0.activities');
        $this->assertCount(2, $firstDayActivities);
        $this->assertEqualsCanonicalizing([$activity->slug, $dance->slug], array_map(
            fn (array $item): string => $item['activity']['slug'], $firstDayActivities,
        ));
        foreach ($firstDayActivities as $item) {
            $this->assertSame(
                $item['activity']['slug'] === $dance->slug ? $danceOffer->id : $offer->id,
                $item['visit_offers'][0]['visit_offer_id'],
            );
            $this->assertSame('free', $item['visit_offers'][0]['fee_type']);
            $this->assertSame('last_place', $item['visit_offers'][0]['availability']['status']);
        }
    }

    public function test_campaign_and_offers_fail_closed_for_unpublished_profile_campaign_and_foreign_tenant(): void
    {
        $this->travelTo('2026-10-28T10:00:00Z');
        [$organization, , , $session, $offer] = $this->visitFixture();
        $campaign = OpenDayCampaign::query()->create(['slug' => 'hidden', 'title' => 'Hidden', 'status' => 'draft']);
        $offer->update(['campaign_id' => $campaign->id]);
        $base = $this->base($organization->slug);
        $this->getJson($base.'/open-days/hidden')->assertNotFound();
        $campaign->update(['status' => 'published']);
        $this->getJson($base.'/open-days/hidden')->assertOk();
        $session->update(['status' => 'cancelled']);
        $this->getJson($base.'/open-days/hidden')->assertNotFound();
        $session->update(['status' => 'scheduled']);

        [$foreignOrganization, , , $foreignSession, $foreignOffer] = $this->visitFixture();
        $foreignCampaign = OpenDayCampaign::query()->create([
            'slug' => 'foreign-only', 'title' => 'Foreign only', 'status' => 'published',
        ]);
        $foreignOffer->update(['campaign_id' => $foreignCampaign->id]);
        $this->getJson($base.'/open-days/foreign-only')->assertNotFound();
        $this->getJson($this->base($foreignOrganization->slug).'/open-days/foreign-only')->assertOk()
            ->assertJsonPath('campaign.days.0.activities.0.visit_offers.0.session_id', $foreignSession->id);

        app(TenantContext::class)->select($organization);
        OrganizationCatalogProfile::query()->where('organization_id', $organization->id)->update(['is_published' => false]);
        $this->getJson($base.'/open-days/hidden')->assertNotFound();
    }

    public function test_only_media_on_a_current_published_campaign_is_downloadable(): void
    {
        $this->travelTo('2026-10-28T10:00:00Z');
        Storage::fake('local');
        [$organization, , , , $offer] = $this->visitFixture();
        $filename = Str::uuid().'.png';
        $path = 'catalog/'.$filename;
        $campaign = OpenDayCampaign::query()->create([
            'slug' => 'media-open-day', 'title' => 'Media open day', 'status' => 'published',
            'image_path' => $path,
        ]);
        $offer->update(['campaign_id' => $campaign->id]);
        $mediaId = pathinfo($filename, PATHINFO_FILENAME);
        Storage::disk('local')->put('catalog/'.$organization->id.'/'.$filename, 'public-image');
        Storage::disk('local')->put('catalog/'.$organization->id.'/'.$mediaId.'.json', json_encode([
            'organization_id' => $organization->id, 'activity_id' => null,
            'path' => $path, 'mime' => 'image/png',
        ], JSON_THROW_ON_ERROR));

        $url = $this->base($organization->slug).'/media/'.$filename;
        $this->getJson($this->base($organization->slug).'/open-days/media-open-day')->assertOk()
            ->assertJsonPath('campaign.image_url', $url);
        $this->get($url)->assertOk();
        $campaign->update(['status' => 'draft']);
        $this->get($url)->assertNotFound();
    }

    private function base(string $slug): string
    {
        return '/api/v1/public/organizations/'.$slug;
    }

    private function offer(VisitOffer $original, array $attributes): VisitOffer
    {
        return VisitOffer::query()->create(array_merge([
            'activity_id' => $original->activity_id, 'group_id' => $original->group_id,
            'campaign_id' => $original->campaign_id, 'type' => $original->type->value,
            'status' => 'published', 'title' => 'Another visit', 'visit_limit' => 8,
        ], $attributes));
    }

    private function visitor(Child $child, int $index): Child
    {
        return $child->owner->children()->create(['first_name' => 'Visitor'.$index, 'last_name' => 'Ivanov']);
    }
}
