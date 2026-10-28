<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\OpenDayCampaign;
use App\Domain\Enrollment\Actions\ReviewEnrollmentApplication;
use App\Domain\Enrollment\Actions\SubmitEnrollmentApplication;
use App\Domain\Enrollment\Services\EnrollmentCapacity;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\VisitOfferFixture;
use Tests\TestCase;

class VisitOfferFinalReviewTest extends TestCase
{
    use RefreshDatabase, VisitOfferFixture;

    public static function priceRows(): array
    {
        return [['lone', 1250], ['identical', 1250], ['mixed', 0]];
    }

    #[DataProvider('priceRows')]
    public function test_public_card_and_hero_preserve_from_price_semantics(string $rows, int $minimum): void
    {
        $this->travelTo('2026-10-28T10:00:00Z');
        [$org, $activity, $group, $session, $offer] = $this->visitFixture([
            'fee_type' => 'from_price', 'amount_cents' => 1250, 'fee_reason' => 'Materials',
        ]);
        if ($rows === 'identical') {
            $next = $session->replicate();
            $next->starts_at = $session->starts_at->addWeek();
            $next->ends_at = $session->ends_at->addWeek();
            $next->save();
            $offer->sessions()->attach($next);
        } elseif ($rows === 'mixed') {
            foreach ([['free', 0], ['paid', 2200]] as [$fee, $amount]) {
                $other = $offer->replicate();
                $other->fill(['fee_type' => $fee, 'amount_cents' => $amount]);
                $other->save();
                $other->sessions()->attach($session);
            }
        }
        $base = '/api/v1/public/organizations/'.$org->slug;
        $label = $minimum === 0 ? 'От 0,00 € / посещение' : 'От 12,50 € / посещение';
        $this->getJson($base.'/catalog')->assertOk()->assertJsonPath('activities.0.price.from', true)
            ->assertJsonPath('activities.0.price.amount_cents', $minimum)->assertJsonPath('activities.0.price.label', $label);
        $this->getJson($base.'/activities/'.$activity->slug)->assertOk()->assertJsonPath('activity.price.from', true)
            ->assertJsonPath('activity.price.label', $label);
    }

    public static function instants(): array
    {
        return [
            ['Europe/Sofia', '2026-11-03T13:00:00Z', '2026-11-03T08:00:00Z', '2026-11-03T09:00:00Z'],
            ['Asia/Tokyo', '2026-11-04T00:30:00Z', '2026-11-03T23:00:00Z', '2026-11-04T00:00:00Z'],
        ];
    }

    #[DataProvider('instants')]
    public function test_ended_session_today_does_not_block_current_group_capacity_or_full_period_approval(string $timezone, string $now, string $start, string $end): void
    {
        $this->travelTo($now);
        [$org, $activity, $group, $past, $offer, $child] = $this->visitFixture(
            [], ['capacity_max' => 1, 'schedule_pattern' => ['timezone' => $timezone]],
            ['starts_at' => $start, 'ends_at' => $end],
        );
        $this->visitEnrollment($child, $group, $past, 'single', $offer, 'ended');
        $future = $past->replicate();
        $future->starts_at = $past->starts_at->addDays(2);
        $future->ends_at = $past->ends_at->addDays(2);
        $future->save();
        $this->assertSame(1, app(EnrollmentCapacity::class)->remainingForGroup($group, CarbonImmutable::parse($now)));
        $this->getJson('/api/v1/public/organizations/'.$org->slug.'/catalog')->assertOk()
            ->assertJsonPath('activities.0.availability.status', 'last_place');
        app(TenantContext::class)->select($org);
        $price = $group->offering->priceOptions()->create(['group_id' => $group->id, 'enrollment_mode' => 'monthly',
            'unit' => 'month', 'amount_cents' => 5000, 'currency' => 'EUR', 'is_available' => true]);
        $other = $child->owner->children()->create(['first_name' => 'New', 'last_name' => 'Child']);
        $application = app(SubmitEnrollmentApplication::class)->handle($child->owner, ['child_id' => $other->id,
            'group_id' => $group->id, 'price_option_id' => $price->id, 'accepted_terms_version' => 'v1'], 'period');
        app(TenantContext::class)->select($org);
        $this->assertSame('approved', app(ReviewEnrollmentApplication::class)
            ->handle($child->owner, $application, 'approve', 1, null, null)->status->value);
    }

    public function test_published_multidate_offer_accepts_content_edits_with_approved_historical_attachment(): void
    {
        $this->travelTo('2026-11-04T12:00:00Z');
        [$org, , $group, $past, $offer, $child] = $this->visitFixture();
        $next = $past->replicate();
        $next->starts_at = $past->starts_at->addWeek();
        $next->ends_at = $past->ends_at->addWeek();
        $next->save();
        $offer->sessions()->attach($next);
        $enrollment = $this->visitEnrollment($child, $group, $past, 'trial', $offer, 'ended');
        $snapshot = $enrollment->application->getAttributes();
        $this->login($org->id);
        $this->patchJson('/api/v1/catalog/visit-offers/'.$offer->id, ['version' => 1, 'title' => 'Corrected title',
            'session_ids' => [$past->id, $next->id]])->assertOk()->assertJsonPath('visit_offer.version', 2);
        app(TenantContext::class)->select($org);
        $this->assertEqualsCanonicalizing([$past->id, $next->id], $offer->sessions()->pluck('activity_sessions.id')->all());
        $this->assertSame($snapshot, $enrollment->application->fresh()->getAttributes());
    }

    public function test_published_offer_rejects_new_past_attachments_but_keeps_existing_past_dates(): void
    {
        $this->travelTo('2026-11-04T12:00:00Z');
        [$org, , , $past, $offer] = $this->visitFixture();
        $otherPast = $past->replicate();
        $otherPast->save();
        $this->login($org->id);
        $url = '/api/v1/catalog/visit-offers/'.$offer->id;
        $this->patchJson($url, ['version' => 1, 'session_ids' => [$past->id, $otherPast->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('session_ids');
        $this->patchJson($url, ['version' => 1, 'title' => 'History correction', 'session_ids' => [$past->id]])->assertOk();
    }

    public static function targets(): array
    {
        return [['create'], ['update']];
    }

    #[DataProvider('targets')]
    public function test_cancelled_source_cannot_be_mutated_through_target_campaign_and_rejection_is_atomic(string $operation): void
    {
        [$org, , , , $offer] = $this->visitFixture();
        $source = OpenDayCampaign::create(['slug' => 'source', 'title' => 'Source', 'status' => 'cancelled']);
        $target = OpenDayCampaign::create(['slug' => 'target', 'title' => 'Target']);
        $offer->update(['campaign_id' => $source->id]);
        $this->login($org->id);
        $before = DB::table('audit_events')->count();
        $payload = ['title' => 'Changed target', 'visit_offer_ids' => [$offer->id],
            'visit_offer_versions' => [$offer->id => 1], 'source_campaign_versions' => [$source->id => 1]];
        if ($operation === 'create') {
            $this->postJson('/api/v1/catalog/open-day-campaigns', $payload)->assertConflict();
        } else {
            $this->patchJson('/api/v1/catalog/open-day-campaigns/'.$target->id, ['version' => 1, ...$payload])->assertConflict();
        }
        $this->assertDatabaseCount('open_day_campaigns', 2);
        $this->assertDatabaseHas('open_day_campaigns', ['id' => $target->id, 'title' => 'Target', 'version' => 1]);
        $this->assertDatabaseHas('open_day_campaigns', ['id' => $source->id, 'status' => 'cancelled', 'version' => 1]);
        $this->assertDatabaseHas('visit_offers', ['id' => $offer->id, 'campaign_id' => $source->id, 'version' => 1]);
        $this->assertSame($before, DB::table('audit_events')->count());
    }

    private function login(string $organizationId): void
    {
        $user = User::factory()->create();
        OrganizationMembership::create(['organization_id' => $organizationId, 'user_id' => $user->id,
            'role' => 'organization_owner', 'status' => 'active']);
        $this->actingAs($user)->withSession(['active_organization_id' => $organizationId]);
    }
}
