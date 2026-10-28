<?php

namespace Tests\Support;

use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Models\VisitOffer;
use App\Domain\Enrollment\Models\Child;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Support\Str;

trait VisitOfferFixture
{
    /** @return array{Organization, Activity, Group, Session, VisitOffer, Child} */
    private function visitFixture(array $offerAttributes = [], array $groupAttributes = [], array $sessionAttributes = []): array
    {
        $organization = Organization::query()->withoutGlobalScopes()->create([
            'name' => 'Visit organization', 'slug' => (string) Str::uuid(), 'status' => 'active',
        ]);
        app(TenantContext::class)->select($organization);
        $activity = Activity::query()->create([
            'slug' => (string) Str::uuid(), 'title' => 'Chess', 'category' => 'games',
            'summary' => 'Chess classes', 'age_label' => '7+', 'status' => 'published',
        ]);
        $offering = $activity->offerings()->create([]);
        $group = $offering->groups()->create(array_merge([
            'name' => 'Tuesday', 'capacity_max' => 12, 'status' => 'published',
            'waitlist_enabled' => true, 'schedule_pattern' => ['timezone' => 'Europe/Sofia'],
        ], $groupAttributes));
        $session = $group->sessions()->create(array_merge([
            'starts_at' => '2026-11-03T14:00:00Z', 'ends_at' => '2026-11-03T15:00:00Z',
            'status' => 'scheduled',
        ], $sessionAttributes));
        $offer = VisitOffer::query()->create(array_merge([
            'activity_id' => $activity->id, 'group_id' => $group->id,
            'type' => 'trial', 'status' => 'published', 'title' => 'Try chess', 'visit_limit' => 3,
        ], $offerAttributes));
        $offer->sessions()->attach($session->id);
        $parent = User::factory()->create();
        $child = $parent->children()->create(['first_name' => 'Ana', 'last_name' => 'Ivanova']);

        return [$organization, $activity, $group, $session, $offer, $child];
    }

    private function visitEnrollment(
        Child $child,
        Group $group,
        ?Session $session,
        string $scope,
        ?VisitOffer $offer = null,
        string $status = 'active',
        ?string $startsOn = null,
    ): Enrollment {
        $price = $group->offering->priceOptions()->create([
            'group_id' => $group->id, 'enrollment_mode' => $scope === 'full_period' ? 'monthly' : $scope,
            'unit' => 'session', 'amount_cents' => 0, 'currency' => 'EUR', 'is_available' => true,
        ]);
        $application = EnrollmentApplication::query()->create([
            'parent_user_id' => $child->owner_user_id, 'child_id' => $child->id,
            'group_id' => $group->id, 'price_option_id' => $price->id,
            'session_id' => $session?->id, 'visit_offer_id' => $offer?->id,
            'scope' => $scope, 'source' => 'organization_catalog', 'status' => 'approved',
            'idempotency_key' => (string) Str::uuid(), 'accepted_terms_version' => 'v1',
            'accepted_terms_at' => now(), 'amount_cents_snapshot' => 0,
            'currency_snapshot' => 'EUR', 'unit_snapshot' => 'session',
        ]);

        return Enrollment::query()->create([
            'application_id' => $application->id, 'child_id' => $child->id,
            'group_id' => $group->id, 'price_option_id' => $price->id,
            'session_id' => $session?->id, 'scope' => $scope,
            'status' => $status, 'starts_on' => $startsOn ?? $session?->starts_at->toDateString() ?? '2026-11-03',
        ]);
    }
}
