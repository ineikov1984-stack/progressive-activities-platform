<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Activity;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ActivityDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_minimal_draft_requires_core_fields_and_creates_first_offering(): void
    {
        [$user, $organization] = $this->member();
        $this->select($user, $organization);
        $this->postJson('/api/v1/catalog/activities', [])
            ->assertUnprocessable()->assertJsonValidationErrors(['title', 'category', 'summary', 'age_label']);
        $created = $this->postJson('/api/v1/catalog/activities', $this->core())
            ->assertCreated()->assertJsonPath('activity.status', 'draft')
            ->assertJsonPath('activity.version', 1)
            ->assertJsonPath('activity.sections.about', null)
            ->assertJsonPath('activity.offering.terms', null);
        $this->assertDatabaseHas('activities', ['id' => $created->json('activity.id'), 'organization_id' => $organization->id]);
        $this->assertDatabaseHas('offerings', ['activity_id' => $created->json('activity.id'), 'organization_id' => $organization->id]);
    }

    public function test_bulgarian_title_receives_unique_latin_slug_within_tenant(): void
    {
        [$user, $organization] = $this->member();
        $this->select($user, $organization);
        $this->postJson('/api/v1/catalog/activities', $this->core())
            ->assertCreated()->assertJsonPath('activity.slug', 'shkola-po-risuvane');
        $this->postJson('/api/v1/catalog/activities', $this->core())
            ->assertCreated()->assertJsonPath('activity.slug', 'shkola-po-risuvane-2');
    }

    public function test_maximum_length_titles_keep_unique_slugs_within_database_limit(): void
    {
        [$user, $organization] = $this->member();
        $this->select($user, $organization);
        $title = str_repeat('a', 255);

        $this->postJson('/api/v1/catalog/activities', [...$this->core(), 'title' => $title])
            ->assertCreated()->assertJsonPath('activity.slug', $title);
        $this->postJson('/api/v1/catalog/activities', [...$this->core(), 'title' => $title])
            ->assertCreated()->assertJsonPath('activity.slug', str_repeat('a', 253).'-2');

        $expanded = $this->postJson('/api/v1/catalog/activities', [
            ...$this->core(), 'title' => str_repeat('Щ', 255),
        ])->assertCreated()->json('activity.slug');
        $this->assertLessThanOrEqual(255, strlen($expanded));
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $expanded);
    }

    public function test_sections_and_offering_round_trip_without_tenant_or_status_override(): void
    {
        [$user, $organization] = $this->member();
        $foreign = $this->organization();
        $this->select($user, $organization);
        $created = $this->postJson('/api/v1/catalog/activities', [
            ...$this->core(), 'organization_id' => $foreign->id, 'status' => 'published',
            'sections' => [
                'about' => 'За начинаещи', 'benefits' => ['Увереност'],
                'faq' => [['question' => 'Какво да нося?', 'answer' => 'Моливи']],
            ],
            'offering' => ['terms' => 'С предварително записване', 'contact_email' => 'hello@example.test'],
        ])->assertCreated()->assertJsonPath('activity.status', 'draft')
            ->assertJsonPath('activity.sections.benefits.0', 'Увереност')
            ->assertJsonPath('activity.offering.terms', 'С предварително записване');
        $this->getJson('/api/v1/catalog/activities/'.$created->json('activity.id'))
            ->assertOk()->assertJsonPath('activity.sections.faq.0.answer', 'Моливи');
        $this->getJson('/api/v1/catalog/activities')->assertOk()->assertJsonCount(1, 'activities');
        $this->assertDatabaseHas('activities', ['id' => $created->json('activity.id'), 'organization_id' => $organization->id]);
    }

    public function test_current_version_updates_draft_and_offering_but_stale_version_conflicts_atomically(): void
    {
        [$user, $organization] = $this->member();
        $this->select($user, $organization);
        $id = $this->postJson('/api/v1/catalog/activities', $this->core())->assertCreated()->json('activity.id');
        $this->patchJson("/api/v1/catalog/activities/{$id}", [
            'version' => 1, 'summary' => 'Нова програма',
            'sections' => ['methodology' => 'Работа в малки групи'],
            'offering' => ['terms' => 'Нови условия'],
        ])->assertOk()->assertJsonPath('activity.version', 2)
            ->assertJsonPath('activity.offering.terms', 'Нови условия');
        $this->patchJson("/api/v1/catalog/activities/{$id}", [
            'version' => 1, 'summary' => 'Lost update', 'offering' => ['terms' => 'Lost terms'],
        ])->assertStatus(409);
        $this->getJson("/api/v1/catalog/activities/{$id}")
            ->assertJsonPath('activity.version', 2)
            ->assertJsonPath('activity.summary', 'Нова програма')
            ->assertJsonPath('activity.offering.terms', 'Нови условия');
    }

    public function test_foreign_activity_is_hidden_and_viewers_cannot_write(): void
    {
        [$user, $organization] = $this->member();
        $foreign = $this->organization();
        app(TenantContext::class)->select($foreign);
        $activity = Activity::query()->create([...$this->core(), 'slug' => 'foreign']);
        $this->select($user, $organization);
        $this->getJson("/api/v1/catalog/activities/{$activity->id}")->assertNotFound();
        $this->patchJson("/api/v1/catalog/activities/{$activity->id}", ['version' => 1, 'summary' => 'Changed'])->assertNotFound();
        [$viewer, $viewedOrganization] = $this->member('viewer');
        $this->select($viewer, $viewedOrganization);
        $this->getJson('/api/v1/catalog/activities')->assertOk();
        $this->postJson('/api/v1/catalog/activities', $this->core())->assertForbidden();
    }

    public function test_media_accepts_png_with_private_tenant_path_and_protected_read(): void
    {
        Storage::fake('local');
        [$user, $organization] = $this->member();
        $this->select($user, $organization);
        $response = $this->post('/api/v1/catalog/media', ['file' => $this->pngUpload()])->assertCreated();
        $path = $response->json('media.path');
        $this->assertMatchesRegularExpression('/^catalog\/[0-9a-f-]{36}\.png$/', $path);
        $this->assertStringNotContainsString($organization->id, $path);
        Storage::disk('local')->assertExists("catalog/{$organization->id}/".basename($path));
        $this->get('/api/v1/catalog/media/'.basename($path))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->postJson('/api/v1/catalog/activities', [...$this->core(), 'hero_image_path' => $path])
            ->assertCreated()->assertJsonPath('activity.hero_image_path', $path);
    }

    public function test_real_multipart_png_above_two_megabytes_is_accepted_by_the_shipped_php_limits(): void
    {
        $this->assertGreaterThanOrEqual(5 * 1024 * 1024, $this->iniBytes('upload_max_filesize'));
        $this->assertGreaterThan(5 * 1024 * 1024 + 64 * 1024, $this->iniBytes('post_max_size'));
        Storage::fake('local');
        [$user, $organization] = $this->member();
        $this->select($user, $organization);
        $file = $this->pngUpload(3 * 1024 * 1024);
        $this->assertGreaterThan(2 * 1024 * 1024, $file->getSize());

        $path = $this->post('/api/v1/catalog/media', ['file' => $file])
            ->assertCreated()->json('media.path');
        Storage::disk('local')->assertExists("catalog/{$organization->id}/".basename($path));
    }

    public function test_media_rejects_svg_executable_and_oversized_files(): void
    {
        Storage::fake('local');
        [$user, $organization] = $this->member();
        $this->select($user, $organization);
        $this->post('/api/v1/catalog/media', ['file' => UploadedFile::fake()->createWithContent('art.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')])->assertUnprocessable();
        $this->post('/api/v1/catalog/media', ['file' => UploadedFile::fake()->createWithContent('malware.png', "MZ\0\0fake executable")])->assertUnprocessable();
        $this->post('/api/v1/catalog/media', ['file' => $this->pngUpload()->size(5121)])->assertUnprocessable();
    }

    public function test_media_retrieval_and_attachment_are_tenant_scoped(): void
    {
        Storage::fake('local');
        [$user, $organization] = $this->member();
        $this->select($user, $organization);
        $path = $this->post('/api/v1/catalog/media', ['file' => $this->pngUpload()])->assertCreated()->json('media.path');
        [$foreignUser, $foreign] = $this->member();
        $this->select($foreignUser, $foreign);
        $this->get('/api/v1/catalog/media/'.basename($path))->assertNotFound();
        $this->postJson('/api/v1/catalog/activities', [...$this->core(), 'hero_image_path' => $path])
            ->assertUnprocessable()->assertJsonValidationErrors('hero_image_path');
    }

    public function test_activity_bound_media_cannot_be_attached_to_a_different_activity(): void
    {
        Storage::fake('local');
        [$user, $organization] = $this->member();
        $this->select($user, $organization);
        $first = $this->postJson('/api/v1/catalog/activities', $this->core())->assertCreated()->json('activity.id');
        $second = $this->postJson('/api/v1/catalog/activities', $this->core())->assertCreated()->json('activity.id');
        $path = $this->post('/api/v1/catalog/media', [
            'file' => $this->pngUpload(), 'activity_id' => $first,
        ])->assertCreated()->json('media.path');

        $this->patchJson("/api/v1/catalog/activities/{$second}", [
            'version' => 1, 'hero_image_path' => $path,
        ])->assertUnprocessable()->assertJsonValidationErrors('hero_image_path');
        $this->patchJson("/api/v1/catalog/activities/{$first}", [
            'version' => 1, 'hero_image_path' => $path,
        ])->assertOk()->assertJsonPath('activity.hero_image_path', $path);
    }

    public function test_profile_and_instructor_images_require_tenant_owned_media(): void
    {
        Storage::fake('local');
        [$user, $organization] = $this->member();
        $this->select($user, $organization);
        $path = $this->post('/api/v1/catalog/media', ['file' => $this->pngUpload()])
            ->assertCreated()->json('media.path');

        $this->patchJson('/api/v1/catalog/profile', ['public_name' => 'School', 'logo_path' => $path])
            ->assertOk()->assertJsonPath('profile.logo_path', $path);
        $this->postJson('/api/v1/catalog/instructors', ['public_name' => 'Teacher', 'photo_path' => $path])
            ->assertCreated()->assertJsonPath('instructor.photo_path', $path);
        $this->patchJson('/api/v1/catalog/profile', ['logo_path' => 'catalog/unknown.png'])
            ->assertUnprocessable()->assertJsonValidationErrors('logo_path');
        $this->postJson('/api/v1/catalog/instructors', ['public_name' => 'Other', 'photo_path' => 'catalog/unknown.png'])
            ->assertUnprocessable()->assertJsonValidationErrors('photo_path');
    }

    /** @return array<string, string> */
    private function core(): array
    {
        return ['title' => 'Школа по рисуване', 'category' => 'Изкуство', 'summary' => 'Творчески занимания за деца.', 'age_label' => '6–9 години'];
    }

    private function pngUpload(int $extraBytes = 0): UploadedFile
    {
        $png = "\x89PNG\r\n\x1a\n";
        $png .= $this->pngChunk('IHDR', pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0));
        if ($extraBytes > 0) {
            $png .= $this->pngChunk('tEXt', "Comment\0".str_repeat('x', $extraBytes));
        }
        $png .= $this->pngChunk('IDAT', gzcompress("\0\xff\0\0"));
        $png .= $this->pngChunk('IEND', '');

        return UploadedFile::fake()->createWithContent('portrait.png', $png);
    }

    private function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }

    private function iniBytes(string $key): int
    {
        $setting = strtolower(trim((string) ini_get($key)));
        $value = (int) $setting;

        return match (substr($setting, -1)) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /** @return array{User, Organization} */
    private function member(string $role = 'organization_owner'): array
    {
        $user = User::factory()->create();
        $organization = $this->organization();
        OrganizationMembership::query()->withoutGlobalScopes()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active',
        ]);

        return [$user, $organization];
    }

    private function organization(): Organization
    {
        return Organization::query()->withoutGlobalScopes()->create([
            'name' => 'Catalog organization', 'slug' => 'catalog-'.Str::random(12), 'status' => 'active',
        ]);
    }

    private function select(User $user, Organization $organization): void
    {
        $this->actingAs($user)
            ->withSession(['active_organization_id' => $organization->id])
            ->withHeader('Accept', 'application/json');
    }
}
