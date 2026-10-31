<?php

namespace Tests\Support;

use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Catalog\Models\Location;
use App\Domain\Catalog\Models\Offering;
use App\Domain\Catalog\Models\OpenDayCampaign;
use App\Domain\Catalog\Models\PriceOption;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Models\VisitOffer;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Models\StaffMember;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * An organization with everything the team works on, for the tests of rights in the team (docs/product/team-rights.md):
 * two published groups of "Шах" with sessions on Tuesdays and Thursdays at 17:00 in Sofia (15:00 UTC, 3–26 November 2026),
 * a group of "Рисуване", a child enrolled for the period, one waiting, one booked for a single visit, a place, a draft
 * visit offer and open day, and a person of the team without a login. Every name and number is synthetic.
 */
trait TeamRightsFixture
{
    /**
     * @return array{organization: Organization, owner: User, activity: Activity, chess: Offering, tuesday: Group, thursday: Group, studio: Group, painting: Activity, session: Session, price: PriceOption, enrollment: Enrollment, waiting: EnrollmentApplication, visit: Enrollment, location: Location, offer: VisitOffer, campaign: OpenDayCampaign, person: StaffMember}
     */
    private function rightsFixture(): array
    {
        $organization = Organization::query()->withoutGlobalScopes()->create(['name' => 'Школа', 'slug' => 'school-'.Str::random(12), 'status' => 'active']);
        $owner = $this->memberOf($organization, 'organization_owner');

        return $this->inTenant($organization, function () use ($organization, $owner): array {
            StaffMember::query()->create(['full_name' => 'Собственик', 'user_id' => $owner->id]);
            $activity = Activity::query()->create([
                'slug' => 'chess-'.Str::random(8), 'title' => 'Шах', 'category' => 'Наука', 'summary' => 'Описание', 'age_label' => '2.–4. клас', 'status' => 'published',
            ]);
            $chess = $activity->offerings()->create([]);
            $tuesday = $chess->groups()->create(['name' => 'Вторник', 'capacity_max' => 10, 'status' => 'published', 'waitlist_enabled' => true, 'enrollment_approval_mode' => 'manual']);
            $thursday = $chess->groups()->create(['name' => 'Четвъртък', 'capacity_max' => 10, 'status' => 'published', 'waitlist_enabled' => true, 'enrollment_approval_mode' => 'manual']);
            $painting = Activity::query()->create([
                'slug' => 'painting-'.Str::random(8), 'title' => 'Рисуване', 'category' => 'Изкуство', 'summary' => 'Описание', 'age_label' => '1.–4. клас', 'status' => 'published',
            ]);
            $studio = $painting->offerings()->create([])->groups()->create(['name' => 'Ателие', 'capacity_max' => 8, 'status' => 'published', 'enrollment_approval_mode' => 'manual']);
            foreach ([[$tuesday, ['03', '10', '17', '24']], [$studio, ['04', '11', '18', '25']], [$thursday, ['05', '12', '19', '26']]] as [$group, $days]) {
                foreach ($days as $day) {
                    $group->sessions()->create(['starts_at' => "2026-11-{$day}T15:00:00Z", 'ends_at' => "2026-11-{$day}T16:00:00Z", 'status' => 'scheduled']);
                }
            }
            $session = Session::query()->where('group_id', $tuesday->id)->orderBy('starts_at')->firstOrFail();
            $price = $chess->priceOptions()->create(['group_id' => $tuesday->id, 'enrollment_mode' => 'monthly', 'unit' => 'month', 'amount_cents' => 4500, 'currency' => 'EUR', 'is_available' => true]);

            $parent = User::factory()->create(['name' => 'Мария Иванова']);
            $child = $parent->children()->create(['first_name' => 'Ани', 'last_name' => 'Иванова', 'class_label' => '3 клас']);
            $application = $this->rightsApplication($parent, $child->id, $tuesday->id, $price->id, 'approved');
            $enrollment = Enrollment::query()->create([
                'application_id' => $application->id, 'child_id' => $child->id, 'group_id' => $tuesday->id,
                'price_option_id' => $price->id, 'scope' => 'full_period', 'status' => 'active', 'starts_on' => '2026-10-20',
            ]);
            $otherParent = User::factory()->create(['name' => 'Петя Петрова']);
            $otherChild = $otherParent->children()->create(['first_name' => 'Боби', 'last_name' => 'Петров']);
            $waiting = $this->rightsApplication($otherParent, $otherChild->id, $tuesday->id, $price->id, 'waitlisted');

            // A single visit on the first Tuesday, booked from a price of the group.
            $single = $chess->priceOptions()->create(['group_id' => $tuesday->id, 'enrollment_mode' => 'single', 'unit' => 'session', 'amount_cents' => 1500, 'currency' => 'EUR', 'is_available' => false]);
            $visitor = $otherParent->children()->create(['first_name' => 'Вики', 'last_name' => 'Петрова']);
            $visitApplication = EnrollmentApplication::query()->create([
                'parent_user_id' => $otherParent->id, 'child_id' => $visitor->id, 'group_id' => $tuesday->id, 'price_option_id' => $single->id,
                'session_id' => $session->id, 'scope' => 'single', 'source' => 'organization_catalog', 'status' => 'approved',
                'idempotency_key' => (string) Str::uuid(), 'accepted_terms_version' => 'v1', 'accepted_terms_at' => now(),
                'amount_cents_snapshot' => 1500, 'currency_snapshot' => 'EUR', 'unit_snapshot' => 'session',
            ]);
            $visit = Enrollment::query()->create([
                'application_id' => $visitApplication->id, 'child_id' => $visitor->id, 'group_id' => $tuesday->id,
                'price_option_id' => $single->id, 'session_id' => $session->id, 'scope' => 'single', 'status' => 'active', 'starts_on' => '2026-11-03',
            ]);

            $location = Location::query()->create(['name' => 'Зала 1', 'type' => 'physical', 'space_usage' => 'exclusive', 'address' => 'ул. Примерна 1']);
            $offer = VisitOffer::query()->create([
                'activity_id' => $activity->id, 'group_id' => $tuesday->id, 'type' => 'trial', 'status' => 'draft',
                'title' => 'Пробно занимание', 'visit_limit' => 3, 'terms_version' => 'v1',
            ]);
            $offer->sessions()->attach($session->id);
            $campaign = OpenDayCampaign::query()->create(['slug' => 'open-'.Str::random(6), 'title' => 'Отворени врати']);
            $person = StaffMember::query()->create(['full_name' => 'Петър Стоянов']);

            return compact('organization', 'owner', 'activity', 'chess', 'tuesday', 'thursday', 'studio', 'painting', 'session', 'price',
                'enrollment', 'waiting', 'visit', 'location', 'offer', 'campaign', 'person');
        });
    }

    private function rightsApplication(User $parent, string $childId, string $groupId, string $priceId, string $status): EnrollmentApplication
    {
        return EnrollmentApplication::query()->create([
            'parent_user_id' => $parent->id, 'child_id' => $childId, 'group_id' => $groupId, 'price_option_id' => $priceId,
            'scope' => 'full_period', 'source' => 'organization_catalog', 'status' => $status, 'idempotency_key' => (string) Str::uuid(),
            'accepted_terms_version' => 'v1', 'accepted_terms_at' => now(), 'amount_cents_snapshot' => 4500, 'currency_snapshot' => 'EUR',
            'unit_snapshot' => 'month',
        ]);
    }

    private function memberOf(Organization $organization, string $role, string $status = 'active'): User
    {
        $user = User::factory()->create();
        OrganizationMembership::query()->withoutGlobalScopes()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'status' => $status,
        ]);

        return $user;
    }

    /** A person of the team for the user, and a teacher profile linked to that person. */
    private function personFor(Organization $organization, User $user, bool $teaches = false): StaffMember
    {
        return $this->inTenant($organization, function () use ($user, $teaches): StaffMember {
            $person = StaffMember::query()->create(['full_name' => 'Служител '.Str::random(4), 'user_id' => $user->id]);
            if ($teaches) {
                $profile = InstructorProfile::query()->create(['public_name' => $person->full_name]);
                $profile->forceFill(['staff_member_id' => $person->id])->save();
            }

            return $person;
        });
    }

    private function profileOf(Organization $organization, StaffMember $person): InstructorProfile
    {
        return $this->inTenant($organization, fn (): InstructorProfile => InstructorProfile::query()->where('staff_member_id', $person->id)->firstOrFail());
    }

    private function signInTo(User $user, Organization $organization): static
    {
        return $this->actingAs($user)->withHeader('X-Organization-Id', $organization->id);
    }

    /**
     * Each request ends with the organization cleared: models are used inside the organization again.
     *
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
