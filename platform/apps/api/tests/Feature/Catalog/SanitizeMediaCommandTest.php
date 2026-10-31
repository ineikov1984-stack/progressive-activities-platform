<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Services\ImageCapabilities;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\SyntheticImages;
use Tests\TestCase;

/** `catalog:sanitize-media` cleans the photos stored before the sanitizer (docs/product/teacher-content.md, т. 5.2). */
class SanitizeMediaCommandTest extends TestCase
{
    /** @var array<string, array{string, string}> photo → organization, extension */
    private array $photos = [];

    public function test_old_photos_are_cleaned_once_and_unreadable_ones_are_listed_and_left_alone(): void
    {
        Storage::fake('local');
        // Two organizations, each in its own folder.
        $school = (string) Str::uuid();
        $club = (string) Str::uuid();
        $jpeg = $this->oldPhoto($school, SyntheticImages::jpegWithMetadata(40, 20, 6), 'jpg', ['activity_id' => (string) Str::uuid()]);
        $png = $this->oldPhoto($club, SyntheticImages::pngWithMetadata(), 'png');
        $cmyk = $this->oldPhoto($school, SyntheticImages::cmykJpeg(), 'jpg');
        // Marked as clean: not opened again, even though its bytes would be cleaned.
        $marked = $this->oldPhoto($club, SyntheticImages::jpegWithMetadata(), 'jpg', ['sanitized' => true]);
        // A record in one organization's folder that names another: never cleaned under the wrong organization.
        $misplaced = $this->oldPhoto($school, SyntheticImages::jpegWithMetadata(), 'jpg', ['organization_id' => $club]);
        $unreadable = [$this->storedBytes($cmyk, 'jpg'), $this->storedBytes($cmyk, 'json')];
        $misplacedBytes = $this->storedBytes($misplaced, 'jpg');
        $markedBytes = $this->storedBytes($marked, 'jpg');

        $this->artisan('catalog:sanitize-media')
            ->expectsOutputToContain('cleaned 2 · already clean 1 · could not process 2')
            ->expectsOutputToContain("organization {$school} · photo {$cmyk}")
            ->expectsOutputToContain("organization {$school} · photo {$misplaced}")
            ->assertExitCode(0);

        foreach ([$jpeg, $png] as $id) {
            $this->assertWithoutSecrets($this->storedBytes($id, $this->extensionOf($id)));
            $this->assertTrue($this->storedRecord($id)['sanitized']);
        }
        $this->assertSame([20, 40], array_slice((array) getimagesizefromstring($this->storedBytes($jpeg, 'jpg')), 0, 2), 'Turned upright.');
        $this->assertSame($this->organizationOf($jpeg), $this->storedRecord($jpeg)['organization_id'], 'The rest of the record stays.');
        $this->assertSame("catalog/{$jpeg}.jpg", $this->storedRecord($jpeg)['path']);
        $this->assertSame($unreadable, [$this->storedBytes($cmyk, 'jpg'), $this->storedBytes($cmyk, 'json')], 'An unreadable photo is not touched.');
        $this->assertSame($markedBytes, $this->storedBytes($marked, 'jpg'));
        $this->assertSame($misplacedBytes, $this->storedBytes($misplaced, 'jpg'));
        $this->assertArrayNotHasKey('sanitized', $this->storedRecord($misplaced));
        $this->assertSame([], array_values(array_filter(Storage::disk('local')->allFiles('catalog'), fn (string $file): bool => str_ends_with($file, '.tmp'))), 'No temporary file is left.');
        $this->assertFalse(app(TenantContext::class)->hasOrganization(), 'No organization is left selected.');

        $cleaned = $this->storedBytes($jpeg, 'jpg');
        $this->artisan('catalog:sanitize-media')
            ->expectsOutputToContain('cleaned 0 · already clean 3 · could not process 2')
            ->assertExitCode(0);
        $this->assertSame($cleaned, $this->storedBytes($jpeg, 'jpg'), 'A second run changes nothing.');
    }

    public function test_without_the_tools_the_command_stops_and_changes_nothing(): void
    {
        Storage::fake('local');
        $organization = (string) Str::uuid();
        $id = $this->oldPhoto($organization, SyntheticImages::jpegWithMetadata(), 'jpg');
        $before = $this->storedBytes($id, 'jpg');
        $this->app->instance(ImageCapabilities::class, new class extends ImageCapabilities
        {
            public function available(): bool
            {
                return false;
            }
        });

        $this->artisan('catalog:sanitize-media')->assertExitCode(1);

        $this->assertSame($before, $this->storedBytes($id, 'jpg'));
        $this->assertArrayNotHasKey('sanitized', $this->storedRecord($id));
    }

    /**
     * A photo as the store wrote it before PR 1a: the bytes as uploaded, a record without `sanitized` (unless given).
     *
     * @param  array<string, mixed>  $record
     */
    private function oldPhoto(string $organization, string $contents, string $extension, array $record = []): string
    {
        $id = (string) Str::uuid();
        $mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$extension];
        Storage::disk('local')->put("catalog/{$organization}/{$id}.{$extension}", $contents);
        Storage::disk('local')->put("catalog/{$organization}/{$id}.json", json_encode([
            'organization_id' => $organization, 'activity_id' => null, 'path' => "catalog/{$id}.{$extension}", 'mime' => $mime, ...$record,
        ], JSON_THROW_ON_ERROR));
        $this->photos[$id] = [$organization, $extension];

        return $id;
    }

    private function storedBytes(string $id, string $extension): string
    {
        return (string) Storage::disk('local')->get("catalog/{$this->organizationOf($id)}/{$id}.{$extension}");
    }

    /** @return array<string, mixed> */
    private function storedRecord(string $id): array
    {
        return (array) json_decode($this->storedBytes($id, 'json'), true);
    }

    private function organizationOf(string $id): string
    {
        return $this->photos[$id][0];
    }

    private function extensionOf(string $id): string
    {
        return $this->photos[$id][1];
    }

    private function assertWithoutSecrets(string $contents): void
    {
        foreach (SyntheticImages::SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $contents);
        }
    }
}
