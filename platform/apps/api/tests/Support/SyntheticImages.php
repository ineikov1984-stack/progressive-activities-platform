<?php

namespace Tests\Support;

use GdImage;
use RuntimeException;

/**
 * Small photos made in the test, with metadata a phone would add. Everything is synthetic: the "place" is 1° 2' 3"
 * north and east, the device is SYNTHETIC-PHONE. Nothing here is a real location or a real person.
 */
final class SyntheticImages
{
    /** Text hidden in the metadata and after the picture; the cleaned file must not contain any of it. */
    public const SECRETS = ['SYNTHETIC-', 'ns.adobe.com/xap', '<script'];

    /**
     * A JPEG with EXIF (device, GPS, orientation), XMP, a comment and HTML after the end of the picture. The left half
     * is red and the right half blue, so that the direction of turning can be seen.
     */
    public static function jpegWithMetadata(int $width = 40, int $height = 20, int $orientation = 6): string
    {
        $jpeg = self::encode(self::halves($width, $height), 'jpeg');
        $exif = "Exif\0\0".self::tiff($orientation);
        $xmp = "http://ns.adobe.com/xap/1.0/\0".'<x:xmpmeta xmlns:x="adobe:ns:meta/">SYNTHETIC-XMP</x:xmpmeta>';
        $segments = self::segment("\xE1", $exif).self::segment("\xE1", $xmp).self::segment("\xFE", 'SYNTHETIC-COMMENT');

        return substr($jpeg, 0, 2).$segments.substr($jpeg, 2).'<html><script>alert(1)</script></html>';
    }

    /** A transparent PNG with an opaque square in the middle, with tEXt and eXIf chunks. */
    public static function pngWithMetadata(int $width = 30, int $height = 30): string
    {
        $image = self::canvas($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, (int) ($width / 3), (int) ($height / 3), (int) ($width * 2 / 3), (int) ($height * 2 / 3), (int) imagecolorallocatealpha($image, 0, 128, 0, 0));
        $png = self::encode($image, 'png');
        // After the IHDR chunk (8 bytes of signature, 25 of IHDR).
        $extra = self::pngChunk('tEXt', "Comment\0SYNTHETIC-PNG-TEXT").self::pngChunk('eXIf', self::tiff(1));

        return substr($png, 0, 33).$extra.substr($png, 33);
    }

    /** A WebP with an EXIF chunk (an extended "VP8X" file). */
    public static function webpWithMetadata(int $width = 24, int $height = 16): string
    {
        $webp = self::encode(self::halves($width, $height), 'webp');
        $chunks = substr($webp, 12);
        if (substr($chunks, 0, 4) === 'VP8X') {
            $chunks[8] = chr(ord($chunks[8]) | 0x08);
        } else {
            $chunks = self::riffChunk('VP8X', chr(0x08)."\0\0\0".self::uint24($width - 1).self::uint24($height - 1)).$chunks;
        }
        $chunks .= self::riffChunk('EXIF', self::tiff(1));

        return 'RIFF'.pack('V', 4 + strlen($chunks)).'WEBP'.$chunks;
    }

    /** Only the header of an animated WebP: enough for the size and the animation flag. */
    public static function animatedWebp(int $width = 20, int $height = 20): string
    {
        $chunks = self::riffChunk('VP8X', chr(0x02)."\0\0\0".self::uint24($width - 1).self::uint24($height - 1))
            .self::riffChunk('ANIM', "\0\0\0\0\0\0");

        return 'RIFF'.pack('V', 4 + strlen($chunks)).'WEBP'.$chunks;
    }

    /** A real PNG with an "acTL" chunk, as an animated PNG has. */
    public static function animatedPng(): string
    {
        $png = self::encode(self::halves(10, 10), 'png');

        return substr($png, 0, 33).self::pngChunk('acTL', pack('NN', 2, 0)).substr($png, 33);
    }

    /** A JPEG header with four colour channels (CMYK). */
    public static function cmykJpeg(int $width = 20, int $height = 20): string
    {
        $components = '';
        for ($id = 1; $id <= 4; $id++) {
            $components .= chr($id)."\x11\0";
        }

        return "\xFF\xD8".self::segment("\xC0", "\x08".pack('nn', $height, $width)."\x04".$components)."\xFF\xD9";
    }

    /** A PNG whose header says 6000 × 5000 (30 megapixels); the picture data is never read. */
    public static function oversizedPngHeader(): string
    {
        return "\x89PNG\r\n\x1a\n".self::pngChunk('IHDR', pack('NNCCCCC', 6000, 5000, 8, 2, 0, 0, 0))
            .self::pngChunk('IDAT', (string) gzcompress("\0\0\0\0")).self::pngChunk('IEND', '');
    }

    /** A plain JPEG of the given size, written to a file (large ones would not fit in a string comfortably). */
    public static function plainJpegFile(string $path, int $width, int $height): void
    {
        $image = self::halves($width, $height);
        if (! imagejpeg($image, $path, 60)) {
            throw new RuntimeException('Could not write the test JPEG.');
        }
    }

    public static function plainPng(int $width, int $height): string
    {
        return self::encode(self::halves($width, $height), 'png');
    }

    /**
     * The colour at a point of a picture.
     *
     * @return array{int, int, int, int} red, green, blue, alpha
     */
    public static function colorAt(string $contents, int $x, int $y): array
    {
        $image = imagecreatefromstring($contents);
        if (! $image instanceof GdImage) {
            throw new RuntimeException('Could not read the cleaned picture.');
        }
        $color = imagecolorsforindex($image, (int) imagecolorat($image, $x, $y));

        return [$color['red'], $color['green'], $color['blue'], $color['alpha']];
    }

    private static function halves(int $width, int $height): GdImage
    {
        $image = self::canvas($width, $height);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height - 1, (int) imagecolorallocate($image, 220, 0, 0));
        imagefilledrectangle($image, intdiv($width, 2), 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 0, 0, 220));

        return $image;
    }

    private static function canvas(int $width, int $height): GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            throw new RuntimeException('Could not create the test picture.');
        }

        return $image;
    }

    private static function encode(GdImage $image, string $format): string
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Could not open a stream.');
        }
        match ($format) {
            'jpeg' => imagejpeg($image, $stream, 90),
            'png' => imagepng($image, $stream),
            default => imagewebp($image, $stream, 90),
        };
        rewind($stream);
        $contents = (string) stream_get_contents($stream);
        fclose($stream);

        return $contents;
    }

    /** TIFF data as in EXIF: the device, the orientation and a GPS position. */
    private static function tiff(int $orientation): string
    {
        $rational = fn (int $value): string => pack('VV', $value, 1);
        $gps = [
            [0x0000, 1, 4, "\x02\x02\x00\x00"],
            [0x0001, 2, 2, "N\0"],
            [0x0002, 5, 3, $rational(1).$rational(2).$rational(3)],
            [0x0003, 2, 2, "E\0"],
            [0x0004, 5, 3, $rational(1).$rational(2).$rational(3)],
        ];
        $first = fn (int $gpsOffset): array => [
            [0x010F, 2, 16, "SYNTHETIC-MAKER\0"],
            [0x0110, 2, 16, "SYNTHETIC-PHONE\0"],
            [0x0112, 3, 1, pack('v', $orientation)."\0\0"],
            [0x8825, 4, 1, pack('V', $gpsOffset)],
        ];
        $ifd0 = self::ifd($first(0), 8);
        $gpsOffset = 8 + strlen($ifd0);

        return "II*\0".pack('V', 8).self::ifd($first($gpsOffset), 8).self::ifd($gps, $gpsOffset);
    }

    /**
     * One directory of TIFF entries at an offset, with the values that do not fit in four bytes after it.
     *
     * @param  list<array{int, int, int, string}>  $entries  tag, type, count, value
     */
    private static function ifd(array $entries, int $offset): string
    {
        $dataOffset = $offset + 2 + count($entries) * 12 + 4;
        $directory = pack('v', count($entries));
        $data = '';
        foreach ($entries as [$tag, $type, $count, $value]) {
            if (strlen($value) <= 4) {
                $directory .= pack('vvV', $tag, $type, $count).str_pad($value, 4, "\0");

                continue;
            }
            $directory .= pack('vvVV', $tag, $type, $count, $dataOffset + strlen($data));
            $data .= $value.(strlen($value) % 2 === 1 ? "\0" : '');
        }

        return $directory.pack('V', 0).$data;
    }

    private static function segment(string $marker, string $payload): string
    {
        return "\xFF".$marker.pack('n', strlen($payload) + 2).$payload;
    }

    private static function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }

    private static function riffChunk(string $type, string $data): string
    {
        return $type.pack('V', strlen($data)).$data.(strlen($data) % 2 === 1 ? "\0" : '');
    }

    private static function uint24(int $value): string
    {
        return substr(pack('V', $value), 0, 3);
    }
}
