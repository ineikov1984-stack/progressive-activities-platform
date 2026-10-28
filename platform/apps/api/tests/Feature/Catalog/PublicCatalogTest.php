<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Catalog\Models\Location;
use App\Domain\Catalog\Models\Offering;
use App\Domain\Catalog\Models\OrganizationCatalogProfile;
use App\Domain\Catalog\Models\PriceOption;
use App\Domain\Catalog\Models\Session;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_exposes_only_published_activities_of_an_active_published_organization(): void
    {
        [, $published] = $this->publishedCatalog('progressive-school');
        Activity::query()->create($this->activityPayload('Скрита чернова', 'skrita', 'draft'));
        Activity::query()->create($this->activityPayload('Архивирана', 'arhivirana', 'archived'));

        $this->getJson('/api/v1/public/organizations/progressive-school/catalog')
            ->assertOk()->assertJsonPath('organization.slug', 'progressive-school')
            ->assertJsonPath('organization.public_name', 'Прогресивно училище')
            ->assertJsonCount(1, 'activities')->assertJsonPath('activities.0.slug', $published->slug)
            ->assertJsonPath('activities.0.price.amount_cents', 4500)
            ->assertJsonPath('activities.0.price.unit', 'month')
            ->assertJsonPath('activities.0.availability.remaining_places', 3)
            ->assertJsonMissingPath('activities.0.organization_id')
            ->assertJsonMissingPath('activities.0.version')
            ->assertJsonMissingPath('activities.0.published_at');

        $this->assertFalse(app(TenantContext::class)->hasOrganization());
    }

    public function test_activity_page_contains_only_public_relations_and_computed_physical_navigation(): void
    {
        [, $activity] = $this->publishedCatalog('progressive-school');

        $group = Group::query()->firstOrFail();
        $session = Session::query()->firstOrFail();
        $price = PriceOption::query()->firstOrFail();

        $this->getJson('/api/v1/public/organizations/progressive-school/activities/'.$activity->slug)
            ->assertOk()->assertJsonPath('activity.title', 'Шах за деца')
            ->assertJsonPath('activity.groups.0.id', $group->id)
            ->assertJsonPath('activity.sections.benefits.0', 'Концентрация')
            ->assertJsonPath('activity.groups.0.schedule.frequency', 'weekly')
            ->assertJsonPath('activity.groups.0.schedule.slots.0.starts_at', '16:00')
            ->assertJsonPath('activity.groups.0.availability.remaining_places', 3)
            ->assertJsonPath('activity.groups.0.location.type', 'physical')
            ->assertJsonPath('activity.groups.0.location.navigation_url', 'https://maps.example/school')
            ->assertJsonPath('activity.groups.0.prices.0.label', '45,00 € / месец')
            ->assertJsonPath('activity.groups.0.prices.0.id', $price->id)
            ->assertJsonPath('activity.groups.0.sessions.0.id', $session->id)
            ->assertJsonPath('activity.groups.0.sessions.0.starts_at', '2026-11-02T14:00:00+00:00')
            ->assertJsonPath('activity.groups.0.sessions.0.ends_at', '2026-11-02T15:00:00+00:00')
            ->assertJsonPath('activity.groups.0.sessions.0.status', 'scheduled')
            ->assertJsonPath('activity.instructors.0.public_name', 'Анна Иванова')
            ->assertJsonMissingPath('activity.groups.0.sessions.0.organization_id')
            ->assertJsonMissingPath('activity.groups.0.location.space_usage')
            ->assertJsonMissingPath('activity.offerings.0.contact_email')
            ->assertJsonMissingPath('activity.organization_id');
    }

    public function test_online_location_never_exposes_a_navigation_action(): void
    {
        [, $activity] = $this->publishedCatalog('online-academy', locationType: 'online');

        $this->getJson('/api/v1/public/organizations/online-academy/activities/'.$activity->slug)
            ->assertOk()->assertJsonPath('activity.groups.0.location.type', 'online')
            ->assertJsonPath('activity.groups.0.location.navigation_url', null);
    }

    public function test_group_without_a_public_location_does_not_fall_back_to_a_private_offering_location(): void
    {
        [, $activity] = $this->publishedCatalog('private-location-school');
        $privateLocation = Location::query()->create([
            'name' => 'Скрита зала', 'type' => 'physical', 'space_usage' => 'exclusive',
            'address' => 'София, таен адрес 1', 'map_url' => 'https://maps.example/private',
            'public_directions' => 'Не трябва да се вижда.', 'is_public' => false,
        ]);
        $offering = Offering::query()->where('activity_id', $activity->id)->firstOrFail();
        $offering->update(['location_id' => $privateLocation->id]);
        Group::query()->where('offering_id', $offering->id)->update(['location_id' => null]);

        $this->getJson('/api/v1/public/organizations/private-location-school/activities/'.$activity->slug)
            ->assertOk()
            ->assertJsonPath('activity.groups.0.location', null)
            ->assertJsonMissing(['name' => 'Скрита зала'])
            ->assertJsonMissing(['address' => 'София, таен адрес 1']);
    }

    public function test_instructor_media_linked_only_to_a_draft_group_is_not_publicly_downloadable(): void
    {
        Storage::fake('local');
        [$organization, $activity] = $this->publishedCatalog('draft-instructor-school');
        $offering = Offering::query()->where('activity_id', $activity->id)->firstOrFail();
        $filename = Str::uuid().'.png';
        $path = 'catalog/'.$filename;
        $instructor = InstructorProfile::query()->create([
            'public_name' => 'Скрит преподавател', 'photo_path' => $path, 'is_public' => true,
        ]);
        $draftGroup = Group::query()->create([
            'offering_id' => $offering->id, 'name' => 'Чернова група', 'status' => 'draft',
        ]);
        $draftGroup->instructors()->attach($instructor->id);
        $mediaId = pathinfo($filename, PATHINFO_FILENAME);
        Storage::disk('local')->put('catalog/'.$organization->id.'/'.$filename, 'private-image');
        Storage::disk('local')->put('catalog/'.$organization->id.'/'.$mediaId.'.json', json_encode([
            'organization_id' => $organization->id,
            'activity_id' => $activity->id,
            'path' => $path,
            'mime' => 'image/png',
        ], JSON_THROW_ON_ERROR));

        $this->get('/api/v1/public/organizations/draft-instructor-school/media/'.$filename)
            ->assertNotFound();
    }

    public function test_unpublished_inactive_foreign_and_non_published_activity_slugs_return_not_found(): void
    {
        [, $activity] = $this->publishedCatalog('visible-school');
        $draft = Activity::query()->create($this->activityPayload('Чернова', 'draft-only', 'draft'));

        $foreign = $this->organization('foreign-school');
        app(TenantContext::class)->select($foreign);
        OrganizationCatalogProfile::query()->create(['public_name' => 'Чуждо училище', 'is_published' => true]);
        $foreignActivity = Activity::query()->create($this->activityPayload('Чужда дейност', 'foreign-activity', 'published'));

        app(TenantContext::class)->clear();
        $this->getJson('/api/v1/public/organizations/visible-school/activities/'.$draft->slug)->assertNotFound();
        $this->getJson('/api/v1/public/organizations/visible-school/activities/'.$foreignActivity->slug)->assertNotFound();
        $this->getJson('/api/v1/public/organizations/foreign-school/activities/'.$activity->slug)->assertNotFound();

        $hidden = $this->organization('hidden-school');
        app(TenantContext::class)->select($hidden);
        OrganizationCatalogProfile::query()->create(['public_name' => 'Скрито', 'is_published' => false]);
        app(TenantContext::class)->clear();
        $this->getJson('/api/v1/public/organizations/hidden-school/catalog')->assertNotFound();

        $inactive = $this->organization('inactive-school', 'restricted');
        app(TenantContext::class)->select($inactive);
        OrganizationCatalogProfile::query()->create(['public_name' => 'Неактивно', 'is_published' => true]);
        app(TenantContext::class)->clear();
        $this->getJson('/api/v1/public/organizations/inactive-school/catalog')->assertNotFound();
    }

    /** @return array{Organization, Activity} */
    private function publishedCatalog(string $slug, string $locationType = 'physical'): array
    {
        $organization = $this->organization($slug);
        app(TenantContext::class)->select($organization);
        OrganizationCatalogProfile::query()->create([
            'public_name' => 'Прогресивно училище', 'intro' => 'Подбрани занимания за всяко дете.',
            'accent_color' => '#216653', 'is_published' => true,
        ]);
        $location = Location::query()->create([
            'name' => $locationType === 'physical' ? 'Училищна зала' : 'Онлайн класна стая',
            'type' => $locationType, 'space_usage' => 'shared',
            'address' => $locationType === 'physical' ? 'София, ул. Примерна 1' : null,
            'map_url' => 'https://maps.example/school', 'public_directions' => 'Вход откъм двора.', 'is_public' => true,
        ]);
        $instructor = InstructorProfile::query()->create([
            'public_name' => 'Анна Иванова', 'role_title' => 'Шахматен треньор',
            'bio' => 'Работи с деца от десет години.', 'qualifications' => ['Лицензиран треньор'], 'is_public' => true,
        ]);
        $activity = Activity::query()->create([
            ...$this->activityPayload('Шах за деца', 'shah-za-deca', 'published'),
            'about' => 'Учение чрез игра и практически задачи.', 'benefits' => ['Концентрация', 'Логическо мислене'],
            'methodology' => 'Кратка теория, практика и анализ.', 'participation_terms' => 'Подходящо и за начинаещи.',
            'faq' => [['question' => 'Нужен ли е опит?', 'answer' => 'Не.']], 'gallery' => [],
            'instructor_intro' => 'Опитен и внимателен екип.', 'published_at' => now(),
        ]);
        $offering = Offering::query()->create([
            'activity_id' => $activity->id, 'location_id' => $location->id,
            'lead_instructor_profile_id' => $instructor->id, 'title' => 'Следобеден шах',
            'description' => 'Групови занимания.', 'terms' => 'Месечно участие.',
            'cancellation_policy' => 'Отписване до 25-о число.', 'contact_email' => 'private@example.test',
            'enrollment_instructions' => 'Internal instructions',
        ]);
        $group = Group::query()->create([
            'offering_id' => $offering->id, 'location_id' => $location->id, 'name' => 'Група 1',
            'age_label' => '1.–4. клас', 'capacity_min' => 2, 'capacity_max' => 3,
            'schedule_pattern' => ['timezone' => 'Europe/Sofia', 'frequency' => 'weekly',
                'start_date' => '2026-11-02', 'end_date' => '2027-01-31',
                'slots' => [['weekday' => 1, 'starts_at' => '16:00', 'duration_minutes' => 60]]],
            'status' => 'published',
        ]);
        $group->instructors()->attach($instructor->id);
        Session::query()->create([
            'group_id' => $group->id, 'location_id' => $location->id,
            'starts_at' => '2026-11-02T14:00:00Z', 'ends_at' => '2026-11-02T15:00:00Z',
            'capacity' => 3, 'status' => 'scheduled',
        ]);
        PriceOption::query()->create([
            'offering_id' => $offering->id, 'group_id' => $group->id,
            'enrollment_mode' => 'monthly', 'unit' => 'month', 'amount_cents' => 4500,
            'currency' => 'EUR', 'terms' => 'Плащане до 10-о число.', 'is_available' => true,
        ]);

        return [$organization, $activity];
    }

    /** @return array<string, mixed> */
    private function activityPayload(string $title, string $slug, string $status): array
    {
        return ['slug' => $slug, 'title' => $title, 'category' => 'Логика и мислене',
            'summary' => 'Занимания за развитие на увереност и умения.', 'age_label' => '1.–4. клас', 'status' => $status];
    }

    private function organization(string $slug, string $status = 'active'): Organization
    {
        return Organization::query()->withoutGlobalScopes()->create([
            'name' => Str::headline($slug), 'slug' => $slug, 'status' => $status,
        ]);
    }
}
