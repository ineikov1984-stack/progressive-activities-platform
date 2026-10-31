<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Services\ImageCapabilities;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\SyntheticImages;
use Tests\TestCase;

/** Every photo of the catalog is stored without the phone's data (docs/product/teacher-content.md, т. 5.2). */
class CatalogMediaSanitizingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_jpeg_from_a_phone_is_stored_without_exif_gps_xmp_or_its_name_and_upright(): void
    {
        Storage::fake('local');
        $organization = $this->signedInOwner();

        $path = $this->uploadPhoto(SyntheticImages::jpegWithMetadata(40, 20, 6), 'Рожден-ден-на-Иван.jpg')
            ->assertCreated()->assertJsonPath('media.mime', 'image/jpeg')->json('media.path');

        $stored = $this->storedContents($organization, $path);
        $this->assertWithoutSecrets($stored);
        $this->assertStringNotContainsString("\xFF\xE1", $stored, 'No APP1 segment (EXIF or XMP) is left.');
        $this->assertStringNotContainsString("\xFF\xED", $stored, 'No APP13 segment (IPTC) is left.');
        $this->assertStringNotContainsString("\xFF\xE2", $stored, 'No APP2 segment (ICC) is left.');
        $this->assertSame([20, 40], array_slice((array) getimagesizefromstring($stored), 0, 2), 'Turned upright by its orientation.');
        $exif = @exif_read_data(Storage::disk('local')->path("catalog/{$organization->id}/".basename($path)));
        $this->assertFalse(is_array($exif) && (isset($exif['GPSLatitude']) || isset($exif['Model']) || isset($exif['Orientation'])));

        $record = $this->storedRecord($organization, $path);
        $this->assertTrue($record['sanitized']);
        $this->assertStringNotContainsString('Иван', (string) json_encode($record, JSON_UNESCAPED_UNICODE));
        $this->assertSame(["{$this->stem($path)}.jpg", "{$this->stem($path)}.json"], $this->storedNames($organization));
        // The editor still reads it.
        $this->get('/api/v1/catalog/media/'.basename($path))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_a_png_and_a_webp_lose_their_metadata_and_a_small_logo_is_accepted(): void
    {
        Storage::fake('local');
        $organization = $this->signedInOwner();

        $png = $this->uploadPhoto(SyntheticImages::pngWithMetadata(), 'logo.png')->assertCreated()->json('media.path');
        $stored = $this->storedContents($organization, $png);
        $this->assertWithoutSecrets($stored);
        $this->assertStringNotContainsString('tEXt', $stored);
        $this->assertStringNotContainsString('eXIf', $stored);
        $this->assertSame(127, SyntheticImages::colorAt($stored, 1, 1)[3], 'A logo keeps its transparency.');

        $webp = $this->uploadPhoto(SyntheticImages::webpWithMetadata(), 'photo.webp')->assertCreated()->json('media.path');
        $this->assertWithoutSecrets($this->storedContents($organization, $webp));
        $this->assertStringNotContainsString('EXIF', $this->storedContents($organization, $webp));

        // No smallest size for the catalog itself: logos are small (the 1000 px are only for teachers' photos).
        $logo = $this->uploadPhoto(SyntheticImages::plainPng(300, 120), 'small-logo.png')->assertCreated()->json('media.path');
        $this->assertSame([300, 120], array_slice((array) getimagesizefromstring($this->storedContents($organization, $logo)), 0, 2));
        $this->assertTrue($this->storedRecord($organization, $logo)['sanitized']);
    }

    public function test_over_the_limit_is_refused_and_nothing_is_stored(): void
    {
        Storage::fake('local');
        $organization = $this->signedInOwner();

        // 24 megapixels for a JPEG, 16 for a PNG or a WebP.
        $this->uploadPhoto(SyntheticImages::jpegHeader(6001, 4000), 'huge.jpg')
            ->assertUnprocessable()->assertJsonPath('errors.file.0', 'Снимката е твърде голяма.');
        $this->uploadPhoto(SyntheticImages::pngHeader(4000, 4001), 'huge.png')
            ->assertUnprocessable()->assertJsonPath('errors.file.0', 'Снимката е твърде голяма.');
        $this->uploadPhoto(SyntheticImages::webpHeader(4001, 4000), 'huge.webp')
            ->assertUnprocessable()->assertJsonPath('errors.file.0', 'Снимката е твърде голяма.');

        $this->assertSame([], $this->storedNames($organization));
    }

    public function test_cmyk_animated_and_disguised_files_are_refused_and_nothing_is_stored(): void
    {
        Storage::fake('local');
        $organization = $this->signedInOwner();
        $unreadable = 'Снимката не може да се обработи. Запазете я като обикновена JPG снимка и опитайте отново.';

        $this->uploadPhoto(SyntheticImages::cmykJpeg(), 'print.jpg')->assertUnprocessable()->assertJsonPath('errors.file.0', $unreadable);
        $this->uploadPhoto(SyntheticImages::animatedWebp(), 'moving.webp')->assertUnprocessable()->assertJsonPath('errors.file.0', $unreadable);
        $this->uploadPhoto(SyntheticImages::animatedPng(), 'moving.png')->assertUnprocessable()->assertJsonPath('errors.file.0', $unreadable);
        // A PNG called .jpg: the kind comes from the content, so it is stored as a PNG, without anything added.
        $path = $this->uploadPhoto(SyntheticImages::pngWithMetadata(), 'disguised.jpg')->assertCreated()->json('media.path');
        $this->assertStringEndsWith('.png', $path);
        $this->assertWithoutSecrets($this->storedContents($organization, $path));

        $this->assertSame(["{$this->stem($path)}.json", "{$this->stem($path)}.png"], $this->storedNames($organization));
    }

    public function test_without_gd_exif_or_webp_support_the_upload_is_refused_and_nothing_is_stored(): void
    {
        Storage::fake('local');
        $organization = $this->signedInOwner();
        $this->app->instance(ImageCapabilities::class, new class extends ImageCapabilities
        {
            public function available(): bool
            {
                return false;
            }
        });

        $this->uploadPhoto(SyntheticImages::jpegWithMetadata(), 'photo.jpg')
            ->assertStatus(503)->assertJsonPath('message', 'Снимките временно не се приемат. Опитайте по-късно.');

        $this->assertSame([], $this->storedNames($organization));
    }

    private function signedInOwner(): Organization
    {
        $user = User::factory()->create();
        $organization = Organization::query()->withoutGlobalScopes()->create([
            'name' => 'Photo school', 'slug' => 'photos-'.Str::random(10), 'status' => 'active',
        ]);
        OrganizationMembership::query()->withoutGlobalScopes()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id, 'role' => 'organization_owner', 'status' => 'active',
        ]);
        $this->actingAs($user)->withHeaders(['X-Organization-Id' => $organization->id, 'Accept' => 'application/json']);

        return $organization;
    }

    private function uploadPhoto(string $contents, string $name): TestResponse
    {
        return $this->post('/api/v1/catalog/media', ['file' => UploadedFile::fake()->createWithContent($name, $contents)]);
    }

    private function storedContents(Organization $organization, string $path): string
    {
        return (string) Storage::disk('local')->get("catalog/{$organization->id}/".basename($path));
    }

    /** @return array<string, mixed> */
    private function storedRecord(Organization $organization, string $path): array
    {
        return (array) json_decode((string) Storage::disk('local')->get("catalog/{$organization->id}/{$this->stem($path)}.json"), true);
    }

    /** @return list<string> */
    private function storedNames(Organization $organization): array
    {
        $names = array_map(basename(...), Storage::disk('local')->allFiles("catalog/{$organization->id}"));
        sort($names);

        return $names;
    }

    private function stem(string $path): string
    {
        return pathinfo($path, PATHINFO_FILENAME);
    }

    private function assertWithoutSecrets(string $contents): void
    {
        foreach (SyntheticImages::SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $contents);
        }
    }
}
