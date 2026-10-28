<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\OpenDayCampaign;
use App\Domain\Catalog\Models\Session;
use App\Domain\Enrollment\Models\Child;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class VisitOfferAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_creates_lists_and_reads_a_draft_with_deadline_defaults(): void
    {
        [$organization, $activity, $group, $session] = $this->fixture();
        $this->login($organization);
        $created = $this->postJson('/api/v1/catalog/visit-offers', $this->offerPayload($activity, $group, $session))
            ->assertCreated()->assertJsonPath('visit_offer.status', 'draft')
            ->assertJsonPath('visit_offer.version', 1)
            ->assertJsonPath('visit_offer.booking_deadline_hours', 12)
            ->assertJsonPath('visit_offer.cancellation_deadline_hours', 24)
            ->assertJsonPath('visit_offer.sessions.0.id', $session->id);
        $id = $created->json('visit_offer.id');
        $this->getJson('/api/v1/catalog/visit-offers')->assertOk()->assertJsonCount(1, 'visit_offers');
        $this->getJson('/api/v1/catalog/visit-offers/'.$id)->assertOk()->assertJsonPath('visit_offer.id', $id);
        $this->assertDatabaseHas('visit_offers', ['id' => $id, 'organization_id' => $organization->id]);
    }

    public function test_write_roles_are_enforced_and_foreign_records_are_hidden(): void
    {
        [$organization, $activity, $group, $session] = $this->fixture();
        $this->login($organization, 'viewer');
        $this->postJson('/api/v1/catalog/visit-offers', $this->offerPayload($activity, $group, $session))->assertForbidden();
        $this->getJson('/api/v1/catalog/visit-offers')->assertOk();
        $this->login($organization, 'coordinator');
        $this->postJson('/api/v1/catalog/visit-offers', $this->offerPayload($activity, $group, $session))->assertCreated();
        $foreign = $this->organization();
        app(TenantContext::class)->select($foreign);
        $campaign = OpenDayCampaign::query()->create(['slug' => 'foreign', 'title' => 'Foreign']);
        $this->login($organization);
        $this->getJson('/api/v1/catalog/open-day-campaigns/'.$campaign->id)->assertNotFound();
    }

    public function test_price_and_related_record_validation_are_exact(): void
    {
        [$organization, $activity, $group, $session] = $this->fixture();
        $this->login($organization);
        $base = $this->offerPayload($activity, $group, $session);
        $this->postJson('/api/v1/catalog/visit-offers', [...$base, 'fee_type' => 'paid', 'amount_cents' => 1.5, 'fee_reason' => 'Materials'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount_cents');
        $this->postJson('/api/v1/catalog/visit-offers', [...$base, 'fee_type' => 'paid', 'amount_cents' => 1250])
            ->assertUnprocessable()->assertJsonValidationErrors('fee_reason');
        $this->postJson('/api/v1/catalog/visit-offers', [...$base, 'fee_type' => 'free', 'amount_cents' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('amount_cents');
        $paidId = $this->postJson('/api/v1/catalog/visit-offers', [
            ...$base, 'fee_type' => 'paid', 'amount_cents' => 1250, 'fee_reason' => 'Materials',
        ])->assertCreated()->json('visit_offer.id');
        $this->patchJson("/api/v1/catalog/visit-offers/{$paidId}", ['version' => 1, 'fee_reason' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('fee_reason');
        app(TenantContext::class)->select($organization);
        $other = $activity->offerings()->firstOrFail()->groups()->create(['name' => 'Other']);
        $this->postJson('/api/v1/catalog/visit-offers', [...$base, 'group_id' => $other->id])
            ->assertUnprocessable()->assertJsonValidationErrors('session_ids');
    }

    public function test_publish_requires_a_future_scheduled_session_and_records_history(): void
    {
        [$organization, $activity, $group, $session] = $this->fixture();
        $this->login($organization);
        $id = $this->postJson('/api/v1/catalog/visit-offers', $this->offerPayload($activity, $group, $session))
            ->assertCreated()->json('visit_offer.id');
        $session->update(['status' => 'cancelled']);
        $this->postJson("/api/v1/catalog/visit-offers/{$id}/publish", ['version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('session_ids');
        $session->update(['status' => 'scheduled']);
        $this->postJson("/api/v1/catalog/visit-offers/{$id}/publish", ['version' => 1])
            ->assertOk()->assertJsonPath('visit_offer.status', 'published')->assertJsonPath('visit_offer.version', 2);
        $this->assertDatabaseHas('audit_events', ['organization_id' => $organization->id, 'subject_id' => $id, 'action' => 'catalog.visit_offer_published']);
        $this->postJson("/api/v1/catalog/visit-offers/{$id}/publish", ['version' => 1])->assertConflict();
    }

    public function test_update_is_versioned_and_audits_each_material_change(): void
    {
        [$organization, $activity, $group, $session] = $this->fixture();
        $this->login($organization);
        $id = $this->postJson('/api/v1/catalog/visit-offers', $this->offerPayload($activity, $group, $session))
            ->assertCreated()->json('visit_offer.id');
        $this->patchJson("/api/v1/catalog/visit-offers/{$id}", [
            'version' => 1, 'visit_limit' => 2, 'fee_type' => 'paid', 'amount_cents' => 1250,
            'fee_reason' => 'Materials', 'booking_deadline_hours' => 6,
            'cancellation_policy' => 'Refund before deadline',
        ])->assertOk()->assertJsonPath('visit_offer.version', 2);
        $this->patchJson("/api/v1/catalog/visit-offers/{$id}", ['version' => 1, 'title' => 'Lost update'])->assertConflict();
        $actions = DB::table('audit_events')->where('subject_id', $id)->pluck('action')->all();
        foreach (['catalog.visit_offer_capacity_changed', 'catalog.visit_offer_price_changed', 'catalog.visit_offer_deadline_changed', 'catalog.visit_offer_policy_changed'] as $action) {
            $this->assertContains($action, $actions);
        }
    }

    public function test_close_and_cancel_are_versioned_and_preserve_offer_history(): void
    {
        [$organization, $activity, $group, $session] = $this->fixture();
        $this->login($organization);
        $id = $this->postJson('/api/v1/catalog/visit-offers', $this->offerPayload($activity, $group, $session))
            ->assertCreated()->json('visit_offer.id');
        $this->postJson("/api/v1/catalog/visit-offers/{$id}/publish", ['version' => 1])->assertOk();
        $this->postJson("/api/v1/catalog/visit-offers/{$id}/close", ['version' => 2])
            ->assertOk()->assertJsonPath('visit_offer.status', 'closed');
        $this->postJson("/api/v1/catalog/visit-offers/{$id}/cancel", ['version' => 3])
            ->assertOk()->assertJsonPath('visit_offer.status', 'cancelled');
        $this->assertDatabaseHas('visit_offers', ['id' => $id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('audit_events', ['subject_id' => $id, 'action' => 'catalog.visit_offer_closed']);
        $this->assertDatabaseHas('audit_events', ['subject_id' => $id, 'action' => 'catalog.visit_offer_cancelled']);
    }

    public function test_campaign_composes_offers_without_deleting_them_on_removal(): void
    {
        [$organization, $activity, $group, $session] = $this->fixture();
        $this->login($organization);
        $id = $this->postJson('/api/v1/catalog/visit-offers', $this->offerPayload($activity, $group, $session))
            ->assertCreated()->json('visit_offer.id');
        $campaign = $this->postJson('/api/v1/catalog/open-day-campaigns', [
            'title' => 'Open doors', 'starts_on' => '2030-11-03', 'ends_on' => '2030-11-04', 'visit_offer_ids' => [$id],
        ])->assertCreated()->assertJsonPath('campaign.visit_offers.0.id', $id);
        $campaignId = $campaign->json('campaign.id');
        $this->getJson('/api/v1/catalog/open-day-campaigns')->assertOk()->assertJsonCount(1, 'campaigns');
        $this->patchJson("/api/v1/catalog/open-day-campaigns/{$campaignId}", ['version' => 1, 'visit_offer_ids' => []])
            ->assertOk()->assertJsonPath('campaign.version', 2)->assertJsonCount(0, 'campaign.visit_offers');
        $this->assertDatabaseHas('visit_offers', ['id' => $id, 'campaign_id' => null]);
        $this->postJson("/api/v1/catalog/open-day-campaigns/{$campaignId}/publish", ['version' => 2])
            ->assertUnprocessable();
    }

    public function test_campaign_publication_requires_a_published_offer_and_audits_lifecycle(): void
    {
        [$organization, $activity, $group, $session] = $this->fixture();
        $this->login($organization);
        $offerId = $this->postJson('/api/v1/catalog/visit-offers', $this->offerPayload($activity, $group, $session))
            ->assertCreated()->json('visit_offer.id');
        $campaignId = $this->postJson('/api/v1/catalog/open-day-campaigns', [
            'title' => 'Open doors', 'starts_on' => '2030-11-03', 'ends_on' => '2030-11-04',
            'visit_offer_ids' => [$offerId],
        ])->assertCreated()->json('campaign.id');
        $this->postJson("/api/v1/catalog/open-day-campaigns/{$campaignId}/publish", ['version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('visit_offer_ids');
        $this->postJson("/api/v1/catalog/visit-offers/{$offerId}/publish", ['version' => 1])->assertOk();
        $this->postJson("/api/v1/catalog/open-day-campaigns/{$campaignId}/publish", ['version' => 1])
            ->assertOk()->assertJsonPath('campaign.status', 'published');
        $this->postJson("/api/v1/catalog/open-day-campaigns/{$campaignId}/close", ['version' => 2])
            ->assertOk()->assertJsonPath('campaign.status', 'closed');
        $this->postJson("/api/v1/catalog/open-day-campaigns/{$campaignId}/cancel", ['version' => 3])
            ->assertOk()->assertJsonPath('campaign.status', 'cancelled');
        foreach (['created', 'published', 'closed', 'cancelled'] as $transition) {
            $this->assertDatabaseHas('audit_events', [
                'subject_id' => $campaignId, 'action' => 'catalog.open_day_campaign_'.$transition,
            ]);
        }
    }

    public function test_campaign_title_can_be_reused_and_date_updates_validate_the_resulting_period(): void
    {
        [$organization] = $this->fixture();
        $this->login($organization);
        $first = $this->postJson('/api/v1/catalog/open-day-campaigns', [
            'title' => 'Open doors', 'starts_on' => '2030-11-03', 'ends_on' => '2030-11-04',
        ])->assertCreated()->assertJsonPath('campaign.slug', 'open-doors');
        $this->postJson('/api/v1/catalog/open-day-campaigns', ['title' => 'Open doors'])
            ->assertCreated()->assertJsonPath('campaign.slug', 'open-doors-2');
        $id = $first->json('campaign.id');
        $this->patchJson("/api/v1/catalog/open-day-campaigns/{$id}", [
            'version' => 1, 'starts_on' => '2030-11-05',
        ])->assertUnprocessable()->assertJsonValidationErrors('ends_on');
        $this->assertDatabaseHas('open_day_campaigns', ['id' => $id, 'version' => 1]);
    }

    public function test_confirmed_application_blocks_incompatible_offer_edits(): void
    {
        [$organization, $activity, $group, $session] = $this->fixture();
        $this->login($organization);
        $id = $this->postJson('/api/v1/catalog/visit-offers', $this->offerPayload($activity, $group, $session))
            ->assertCreated()->json('visit_offer.id');
        app(TenantContext::class)->select($organization);
        $parent = User::factory()->create();
        $child = Child::query()->create(['owner_user_id' => $parent->id, 'first_name' => 'Ani', 'last_name' => 'Ivanova']);
        $price = $group->offering->priceOptions()->create([
            'group_id' => $group->id, 'enrollment_mode' => 'single', 'unit' => 'session',
            'amount_cents' => 1000, 'currency' => 'EUR',
        ]);
        EnrollmentApplication::query()->create([
            'parent_user_id' => $parent->id, 'child_id' => $child->id, 'group_id' => $group->id,
            'price_option_id' => $price->id, 'session_id' => $session->id,
            'visit_offer_id' => $id, 'scope' => 'trial', 'source' => 'organization_catalog',
            'status' => 'approved', 'idempotency_key' => 'approved-visit',
            'accepted_terms_version' => 'v1', 'accepted_terms_at' => now(),
            'amount_cents_snapshot' => 0, 'currency_snapshot' => 'EUR', 'unit_snapshot' => 'session',
            'visit_offer_title_snapshot' => 'Trial chess',
        ]);
        $this->patchJson("/api/v1/catalog/visit-offers/{$id}", ['version' => 1, 'visit_limit' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('visit_limit');
        $this->assertDatabaseHas('visit_offers', ['id' => $id, 'visit_limit' => 3, 'version' => 1]);
    }

    public function test_draft_can_change_groups_when_its_selected_sessions_change_together(): void
    {
        [$organization, $activity, $group, $session] = $this->fixture();
        $this->login($organization);
        $id = $this->postJson('/api/v1/catalog/visit-offers', $this->offerPayload($activity, $group, $session))
            ->assertCreated()->json('visit_offer.id');
        app(TenantContext::class)->select($organization);
        $other = $group->offering->groups()->create(['name' => 'Thursday']);
        $otherSession = $other->sessions()->create([
            'starts_at' => '2030-11-05 16:00:00+02', 'ends_at' => '2030-11-05 17:00:00+02',
        ]);
        $this->patchJson("/api/v1/catalog/visit-offers/{$id}", [
            'version' => 1, 'group_id' => $other->id, 'session_ids' => [$otherSession->id],
        ])->assertOk()->assertJsonPath('visit_offer.group_id', $other->id)
            ->assertJsonPath('visit_offer.sessions.0.id', $otherSession->id);
        $this->assertDatabaseMissing('visit_offer_sessions', ['visit_offer_id' => $id, 'session_id' => $session->id]);
    }

    public function test_published_offer_keeps_a_future_session_on_update(): void
    {
        [$organization, $activity, $group, $session] = $this->fixture();
        $this->login($organization);
        $id = $this->postJson('/api/v1/catalog/visit-offers', $this->offerPayload($activity, $group, $session))
            ->assertCreated()->json('visit_offer.id');
        $this->postJson("/api/v1/catalog/visit-offers/{$id}/publish", ['version' => 1])->assertOk();
        $this->patchJson("/api/v1/catalog/visit-offers/{$id}", ['version' => 2, 'session_ids' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('session_ids');
        $this->getJson("/api/v1/catalog/visit-offers/{$id}")
            ->assertJsonPath('visit_offer.version', 2)
            ->assertJsonPath('visit_offer.sessions.0.id', $session->id);
    }

    /** @return array{Organization, Activity, Group, Session} */
    private function fixture(): array
    {
        $organization = $this->organization();
        app(TenantContext::class)->select($organization);
        $activity = Activity::query()->create([
            'slug' => (string) Str::uuid(), 'title' => 'Chess', 'category' => 'games',
            'summary' => 'Chess lessons', 'age_label' => '7+',
        ]);
        $offering = $activity->offerings()->create([]);
        $group = $offering->groups()->create(['name' => 'Tuesday', 'capacity_max' => 12]);
        $session = $group->sessions()->create([
            'starts_at' => '2030-11-03 16:00:00+02', 'ends_at' => '2030-11-03 17:00:00+02',
        ]);

        return [$organization, $activity, $group, $session];
    }

    private function organization(): Organization
    {
        return Organization::query()->withoutGlobalScopes()->create([
            'name' => 'Chess club', 'slug' => (string) Str::uuid(), 'status' => 'active',
        ]);
    }

    private function login(Organization $organization, string $role = 'organization_owner'): void
    {
        $user = User::factory()->create();
        OrganizationMembership::query()->withoutGlobalScopes()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id,
            'role' => $role, 'status' => 'active',
        ]);
        $this->actingAs($user)->withSession(['active_organization_id' => $organization->id]);
    }

    /** @return array<string, mixed> */
    private function offerPayload(Activity $activity, Group $group, Session $session): array
    {
        return [
            'activity_id' => $activity->id, 'group_id' => $group->id, 'session_ids' => [$session->id],
            'type' => 'trial', 'title' => 'Trial chess', 'visit_limit' => 3,
        ];
    }
}
