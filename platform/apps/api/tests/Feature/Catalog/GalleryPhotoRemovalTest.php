<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\OrganizationCatalogProfile;
use App\Domain\Catalog\Services\CatalogMediaUsage;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Tests\Support\SyntheticImages;
use Tests\TestCase;

/** "Махни от страницата" on a published activity (docs/product/teacher-content.md, т. 5.6). */
class GalleryPhotoRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_office_takes_a_photo_off_a_published_page_and_its_file_goes(): void
    {
        Storage::fake('local');
        [$user, $organization] = $this->schoolWith('organization_owner');
        $this->signInAs($user, $organization);
        [$activity, $first, $second] = $this->publishedGallery($organization);
        $public = "/api/v1/public/organizations/{$organization->slug}/media/";
        $this->get($public.basename($first))->assertOk();

        $this->postJson($this->removeUrl($activity), ['version' => 1, 'path' => $first])->assertOk()
            ->assertJsonPath('activity.version', 2)
            ->assertJsonPath('activity.status', 'published')
            ->assertJsonPath('activity.sections.gallery', [['path' => $second, 'alt' => 'Готови картини']])
            ->assertJsonPath('still_shown', false)->assertJsonPath('shown_as', []);

        $this->get($public.basename($first))->assertNotFound();
        $this->get($public.basename($second))->assertOk();
        Storage::disk('local')->assertMissing("catalog/{$organization->id}/".basename($first));
        Storage::disk('local')->assertMissing("catalog/{$organization->id}/".pathinfo($first, PATHINFO_FILENAME).'.json');
        Storage::disk('local')->assertExists("catalog/{$organization->id}/".basename($second));
        // Only the gallery and the version changed.
        $after = $this->inOrganization($organization, fn (): Activity => Activity::query()->findOrFail($activity->id));
        $this->assertSame(['Рисуване', 'risuvane', 'published', $activity->published_at?->toIso8601String()], [
            $after->title, $after->slug, $after->status->value, $after->published_at?->toIso8601String(),
        ]);

        $event = DB::table('audit_events')->where('action', 'catalog.gallery_photo_removed')->sole();
        $this->assertSame([$organization->id, $user->id, 'activity', $activity->id], [$event->organization_id, $event->actor_id, $event->subject_type, $event->subject_id]);
        $this->assertSame(['version' => 2], json_decode((string) $event->metadata, true));
        $this->assertStringNotContainsString(pathinfo($first, PATHINFO_FILENAME), (string) $event->metadata);
        $this->assertStringNotContainsString('акварел', (string) $event->metadata);
    }

    public function test_a_photo_that_is_also_the_cover_stays_the_answer_says_so_and_a_coordinator_may_remove(): void
    {
        Storage::fake('local');
        [$owner, $organization] = $this->schoolWith('organization_owner');
        $this->signInAs($owner, $organization);
        [$activity, $first] = $this->publishedGallery($organization);
        // The same photo is also the cover.
        $this->inOrganization($organization, fn () => Activity::query()->whereKey($activity->id)->update(['hero_image_path' => $first]));
        $coordinator = $this->memberOf($organization, 'coordinator');
        $this->signInAs($coordinator, $organization);

        $response = $this->postJson($this->removeUrl($activity), ['version' => 1, 'path' => $first])->assertOk()
            ->assertJsonCount(1, 'activity.sections.gallery')
            // So that the office does not think the photo is gone: where it is still shown, never its path.
            ->assertJsonPath('still_shown', true)->assertJsonPath('shown_as', ['cover']);
        $this->assertStringNotContainsString(pathinfo($first, PATHINFO_FILENAME), (string) json_encode([$response->json('still_shown'), $response->json('shown_as')]));

        Storage::disk('local')->assertExists("catalog/{$organization->id}/".basename($first));
        $this->get("/api/v1/public/organizations/{$organization->slug}/media/".basename($first))->assertOk();
    }

    public function test_the_same_photo_in_another_activitys_gallery_keeps_its_file(): void
    {
        Storage::fake('local');
        [$user, $organization] = $this->schoolWith('organization_owner');
        $this->signInAs($user, $organization);
        [$activity, $first] = $this->publishedGallery($organization);
        $this->inOrganization($organization, fn (): Activity => Activity::query()->create([
            'slug' => 'akvarel', 'title' => 'Акварел', 'category' => 'Изкуство', 'summary' => 'Акварел за деца.',
            'age_label' => '6–9 години', 'status' => 'published', 'published_at' => now(), 'version' => 1,
            'gallery' => [['path' => $first, 'alt' => 'Деца рисуват с акварел']],
        ]));

        $this->postJson($this->removeUrl($activity), ['version' => 1, 'path' => $first])->assertOk()
            ->assertJsonPath('still_shown', true)->assertJsonPath('shown_as', ['gallery']);

        Storage::disk('local')->assertExists("catalog/{$organization->id}/".basename($first));
        Storage::disk('local')->assertExists("catalog/{$organization->id}/".pathinfo($first, PATHINFO_FILENAME).'.json');
        $this->get("/api/v1/public/organizations/{$organization->slug}/media/".basename($first))->assertOk();
    }

    public function test_without_a_selected_organization_no_photo_looks_unused(): void
    {
        app(TenantContext::class)->clear();

        // The tenant scope would find no record at all, and the file would be deleted: stop instead.
        $this->expectException(LogicException::class);
        app(CatalogMediaUsage::class)->placesOf('catalog/'.Str::uuid().'.jpg');
    }

    public function test_a_stale_version_a_draft_an_archived_activity_and_a_missing_photo_change_nothing(): void
    {
        Storage::fake('local');
        [$user, $organization] = $this->schoolWith('organization_owner');
        $this->signInAs($user, $organization);
        [$activity, $first] = $this->publishedGallery($organization);

        $this->postJson($this->removeUrl($activity), ['version' => 7, 'path' => $first])->assertConflict()
            ->assertJsonPath('message', 'Дейността е променена междувременно. Заредете страницата отново.');
        $this->postJson($this->removeUrl($activity), ['version' => 1, 'path' => 'catalog/'.Str::uuid().'.jpg'])
            ->assertUnprocessable()->assertJsonPath('errors.path.0', 'Снимката вече не е в галерията.');
        $this->postJson($this->removeUrl($activity), ['version' => 1])->assertUnprocessable()->assertJsonValidationErrors('path');

        $this->inOrganization($organization, fn () => Activity::query()->whereKey($activity->id)->update(['status' => 'draft']));
        $this->postJson($this->removeUrl($activity), ['version' => 1, 'path' => $first])->assertConflict()
            ->assertJsonPath('message', 'Черновата се променя в редактора.');
        $this->inOrganization($organization, fn () => Activity::query()->whereKey($activity->id)->update(['status' => 'archived']));
        $this->postJson($this->removeUrl($activity), ['version' => 1, 'path' => $first])->assertConflict()
            ->assertJsonPath('message', 'Дейността е в архива.');

        $after = $this->inOrganization($organization, fn (): Activity => Activity::query()->findOrFail($activity->id));
        $this->assertSame(1, $after->version);
        $this->assertCount(2, (array) $after->getAttribute('gallery'));
        Storage::disk('local')->assertExists("catalog/{$organization->id}/".basename($first));
        $this->assertSame(0, DB::table('audit_events')->where('action', 'catalog.gallery_photo_removed')->count());
    }

    #[TestGroup('tenant-isolation')]
    public function test_another_organizations_activity_is_not_found_and_a_viewer_is_refused(): void
    {
        Storage::fake('local');
        [$foreignOwner, $foreign] = $this->schoolWith('organization_owner');
        $this->signInAs($foreignOwner, $foreign);
        [$foreignActivity, $foreignPhoto] = $this->publishedGallery($foreign);
        [$user, $organization] = $this->schoolWith('organization_owner');
        $this->signInAs($user, $organization);

        $this->postJson($this->removeUrl($foreignActivity), ['version' => 1, 'path' => $foreignPhoto])->assertNotFound();

        $viewer = $this->memberOf($foreign, 'viewer');
        $this->signInAs($viewer, $foreign);
        $this->postJson($this->removeUrl($foreignActivity), ['version' => 1, 'path' => $foreignPhoto])->assertForbidden();

        $after = $this->inOrganization($foreign, fn (): Activity => Activity::query()->findOrFail($foreignActivity->id));
        $this->assertSame(1, $after->version);
        $this->assertCount(2, (array) $after->getAttribute('gallery'));
        Storage::disk('local')->assertExists("catalog/{$foreign->id}/".basename($foreignPhoto));
        $this->assertSame(0, DB::table('audit_events')->where('action', 'catalog.gallery_photo_removed')->count());
    }

    /** @return array{User, Organization} */
    private function schoolWith(string $role): array
    {
        $organization = Organization::query()->withoutGlobalScopes()->create([
            'name' => 'Gallery school', 'slug' => 'gallery-'.Str::lower(Str::random(10)), 'status' => 'active',
        ]);

        return [$this->memberOf($organization, $role), $organization];
    }

    private function memberOf(Organization $organization, string $role): User
    {
        $user = User::factory()->create();
        OrganizationMembership::query()->withoutGlobalScopes()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active',
        ]);

        return $user;
    }

    private function signInAs(User $user, Organization $organization): void
    {
        $this->actingAs($user)->withHeaders(['X-Organization-Id' => $organization->id, 'Accept' => 'application/json']);
    }

    /**
     * A published activity on a published page, with two uploaded (cleaned) photos in its gallery.
     *
     * @return array{Activity, string, string}
     */
    private function publishedGallery(Organization $organization): array
    {
        $first = $this->post('/api/v1/catalog/media', ['file' => UploadedFile::fake()->createWithContent('a.jpg', SyntheticImages::jpegWithMetadata())])
            ->assertCreated()->json('media.path');
        $second = $this->post('/api/v1/catalog/media', ['file' => UploadedFile::fake()->createWithContent('b.png', SyntheticImages::plainPng(30, 20))])
            ->assertCreated()->json('media.path');

        $activity = $this->inOrganization($organization, function () use ($first, $second): Activity {
            OrganizationCatalogProfile::query()->create(['public_name' => 'Школа', 'is_published' => true]);

            return Activity::query()->create([
                'slug' => 'risuvane', 'title' => 'Рисуване', 'category' => 'Изкуство', 'summary' => 'Рисуване за деца.',
                'age_label' => '6–9 години', 'status' => 'published', 'published_at' => now(), 'version' => 1,
                'gallery' => [['path' => $first, 'alt' => 'Деца рисуват с акварел'], ['path' => $second, 'alt' => 'Готови картини']],
            ]);
        });

        return [$activity, $first, $second];
    }

    private function removeUrl(Activity $activity): string
    {
        return "/api/v1/catalog/activities/{$activity->id}/gallery/remove";
    }

    /**
     * Each request ends with the organization cleared: models are used inside it again.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function inOrganization(Organization $organization, callable $callback): mixed
    {
        app(TenantContext::class)->select($organization);

        try {
            return $callback();
        } finally {
            app(TenantContext::class)->clear();
        }
    }
}
