<?php

namespace Tests\Unit\Catalog;

use App\Domain\Catalog\Services\ImageCapabilities;
use App\Domain\Catalog\Services\ImageRejected;
use App\Domain\Catalog\Services\ImageSanitizer;
use PHPUnit\Framework\TestCase;
use Tests\Support\SyntheticImages;

/** The photo loses the phone's data and keeps its picture (docs/product/teacher-content.md, т. 5.2). */
class ImageSanitizerTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_this_php_has_the_tools_the_image_ships_with(): void
    {
        $this->assertTrue((new ImageCapabilities)->available(), 'gd with JPEG, PNG and WebP, and exif are needed (PR 0).');
    }

    public function test_a_jpeg_loses_exif_gps_xmp_the_comment_and_what_follows_the_picture_and_is_turned_upright(): void
    {
        $source = SyntheticImages::jpegWithMetadata(40, 20, 6);
        $before = @exif_read_data($this->tempFile($source));
        $this->assertIsArray($before);
        $this->assertSame(6, $before['Orientation'] ?? null, 'The fixture carries the orientation.');
        $this->assertArrayHasKey('GPSLatitude', $before, 'The fixture carries a position.');

        $clean = $this->sanitizer()->sanitize($this->tempFile($source), 'image/jpeg');

        $this->assertSame('image/jpeg', $clean['mime']);
        $this->assertSame([20, 40], [$clean['width'], $clean['height']], 'Orientation 6 turns the photo a quarter clockwise.');
        $this->assertSame([20, 40], array_slice((array) getimagesizefromstring($clean['contents']), 0, 2));
        $this->assertWithoutSecrets($clean['contents']);
        $this->assertStringNotContainsString("\xFF\xE1", $clean['contents'], 'No APP1 segment (EXIF or XMP) is left.');
        $after = @exif_read_data($this->tempFile($clean['contents']));
        $this->assertFalse(is_array($after) && (isset($after['GPSLatitude']) || isset($after['Model']) || isset($after['Orientation'])));
        // The left half (red) is now on top, the right half (blue) at the bottom.
        [$red, , $blue] = SyntheticImages::colorAt($clean['contents'], 10, 5);
        $this->assertGreaterThan($blue, $red);
        [$red, , $blue] = SyntheticImages::colorAt($clean['contents'], 10, 35);
        $this->assertGreaterThan($red, $blue);
    }

    public function test_each_orientation_gives_the_upright_size(): void
    {
        foreach ([1 => [40, 20], 2 => [40, 20], 3 => [40, 20], 4 => [40, 20], 5 => [20, 40], 6 => [20, 40], 7 => [20, 40], 8 => [20, 40]] as $orientation => $size) {
            $clean = $this->sanitizer()->sanitize($this->tempFile(SyntheticImages::jpegWithMetadata(40, 20, $orientation)), 'image/jpeg');
            $this->assertSame($size, [$clean['width'], $clean['height']], "Orientation {$orientation}");
        }
        // 8 turns a quarter counter-clockwise: the left half (red) goes to the bottom.
        $clean = $this->sanitizer()->sanitize($this->tempFile(SyntheticImages::jpegWithMetadata(40, 20, 8)), 'image/jpeg');
        [$red, , $blue] = SyntheticImages::colorAt($clean['contents'], 10, 35);
        $this->assertGreaterThan($blue, $red);
    }

    public function test_a_png_loses_its_text_and_exif_chunks_and_keeps_transparency(): void
    {
        $clean = $this->sanitizer()->sanitize($this->tempFile(SyntheticImages::pngWithMetadata()), 'image/png');

        $this->assertSame('image/png', $clean['mime']);
        $this->assertWithoutSecrets($clean['contents']);
        $this->assertStringNotContainsString('tEXt', $clean['contents']);
        $this->assertStringNotContainsString('eXIf', $clean['contents']);
        $this->assertSame(127, SyntheticImages::colorAt($clean['contents'], 1, 1)[3], 'The corner stays transparent.');
        $this->assertSame(0, SyntheticImages::colorAt($clean['contents'], 15, 15)[3], 'The middle stays opaque.');
    }

    public function test_a_webp_loses_its_exif_chunk(): void
    {
        $source = SyntheticImages::webpWithMetadata();
        $this->assertStringContainsString('SYNTHETIC-PHONE', $source);

        $clean = $this->sanitizer()->sanitize($this->tempFile($source), 'image/webp');

        $this->assertSame('image/webp', $clean['mime']);
        $this->assertSame([24, 16], [$clean['width'], $clean['height']]);
        $this->assertWithoutSecrets($clean['contents']);
        $this->assertStringNotContainsString('EXIF', $clean['contents']);
    }

    public function test_a_large_photo_is_made_smaller_and_turned(): void
    {
        $clean = $this->sanitizer()->sanitize($this->tempFile(SyntheticImages::jpegWithMetadata(4000, 3000, 6)), 'image/jpeg');

        $this->assertSame([1920, 2560], [$clean['width'], $clean['height']]);
    }

    public function test_twenty_four_megapixels_are_cleaned_well_within_the_memory_limit(): void
    {
        $path = $this->tempFile('');
        SyntheticImages::plainJpegFile($path, 6000, 4000);
        $before = memory_get_usage();
        memory_reset_peak_usage();

        $clean = $this->sanitizer()->sanitize($path, 'image/jpeg');

        $this->assertSame([2560, 1707], [$clean['width'], $clean['height']]);
        // About 96 MB for the decoded photo and 18 MB for the smaller copy; memory_limit is 256M (PR 0).
        $this->assertLessThan(160 * 1024 * 1024, memory_get_peak_usage() - $before);
    }

    public function test_over_twenty_four_megapixels_is_refused_from_the_header(): void
    {
        $this->assertRejected(SyntheticImages::oversizedPngHeader(), 'image/png', ImageRejected::TOO_LARGE, 'Снимката е твърде голяма.');
    }

    public function test_cmyk_animated_damaged_and_mislabelled_files_are_refused(): void
    {
        $unreadable = 'Снимката не може да се обработи. Запазете я като обикновена JPG снимка и опитайте отново.';
        $this->assertRejected(SyntheticImages::cmykJpeg(), 'image/jpeg', ImageRejected::UNREADABLE, $unreadable);
        $this->assertRejected(SyntheticImages::animatedWebp(), 'image/webp', ImageRejected::UNREADABLE, $unreadable);
        $this->assertRejected(SyntheticImages::animatedPng(), 'image/png', ImageRejected::UNREADABLE, $unreadable);
        $this->assertRejected(substr(SyntheticImages::plainPng(20, 20), 0, 60), 'image/png', ImageRejected::UNREADABLE, $unreadable);
        $this->assertRejected(SyntheticImages::plainPng(20, 20), 'image/jpeg', ImageRejected::UNREADABLE, $unreadable);
        $this->assertRejected('<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'image/svg+xml', ImageRejected::UNREADABLE, $unreadable);
    }

    public function test_without_the_tools_nothing_is_decoded(): void
    {
        $sanitizer = new ImageSanitizer(new class extends ImageCapabilities
        {
            public function available(): bool
            {
                return false;
            }
        });

        try {
            $sanitizer->sanitize($this->tempFile(SyntheticImages::plainPng(20, 20)), 'image/png');
            $this->fail('A photo was cleaned without the tools.');
        } catch (ImageRejected $rejected) {
            $this->assertTrue($rejected->toolsAreMissing());
            $this->assertSame('Снимките временно не се приемат. Опитайте по-късно.', $rejected->getMessage());
        }
    }

    private function sanitizer(): ImageSanitizer
    {
        return new ImageSanitizer(new ImageCapabilities);
    }

    private function tempFile(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'sanitizer-test-');
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }

    private function assertRejected(string $contents, string $mime, string $reason, string $message): void
    {
        try {
            $this->sanitizer()->sanitize($this->tempFile($contents), $mime);
            $this->fail("A {$mime} file that should be refused was cleaned.");
        } catch (ImageRejected $rejected) {
            $this->assertSame($reason, $rejected->reason);
            $this->assertSame($message, $rejected->getMessage());
        }
    }

    private function assertWithoutSecrets(string $contents): void
    {
        foreach (SyntheticImages::SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $contents);
        }
    }
}
