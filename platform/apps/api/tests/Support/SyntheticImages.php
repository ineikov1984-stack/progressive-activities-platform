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
    public const SECRETS = ['SYNTHETIC-', 'ns.adobe.com/xap', 'Photoshop 3.0', '8BIM', 'ICC_PROFILE', '<script'];

    /**
     * A JPEG with EXIF (device, GPS, orientation), XMP, IPTC (APP13), an ICC profile (APP2), a comment and HTML after
     * the end of the picture. The left half is red and the right half blue, so that the direction of turning can be seen.
     */
    public static function jpegWithMetadata(int $width = 40, int $height = 20, int $orientation = 6): string
    {
        $jpeg = self::encode(self::halves($width, $height), 'jpeg');
        $exif = "Exif\0\0".self::tiff($orientation);
        $xmp = "http://ns.adobe.com/xap/1.0/\0".'<x:xmpmeta xmlns:x="adobe:ns:meta/">SYNTHETIC-XMP</x:xmpmeta>';
        // IPTC caption (record 2, dataset 120) in a Photoshop resource block.
        $iptc = "\x1C\x02\x78".pack('n', strlen('SYNTHETIC-IPTC caption')).'SYNTHETIC-IPTC caption';
        $photoshop = "Photoshop 3.0\0".'8BIM'.pack('n', 0x0404)."\0\0".pack('N', strlen($iptc)).$iptc.(strlen($iptc) % 2 === 1 ? "\0" : '');
        $segments = self::segment("\xE1", $exif).self::segment("\xE1", $xmp).self::segment("\xED", $photoshop)
            .self::segment("\xE2", "ICC_PROFILE\0\x01\x01".self::iccProfile()).self::segment("\xFE", 'SYNTHETIC-COMMENT');

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
        $chunks = self::riffChunk('VP8X', chr(0x08)."\0\0\0".self::uint24($width - 1).self::uint24($height - 1))
            .self::webpBitstream(self::halves($width, $height)).self::riffChunk('EXIF', self::tiff(1));

        return 'RIFF'.pack('V', 4 + strlen($chunks)).'WEBP'.$chunks;
    }

    /** A real animated WebP: two frames (red, then blue), 100 ms each, as a browser plays it. */
    public static function animatedWebp(int $width = 20, int $height = 20): string
    {
        $frames = '';
        foreach ([[220, 0, 0], [0, 0, 220]] as [$red, $green, $blue]) {
            $image = self::canvas($width, $height);
            imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, $red, $green, $blue));
            // At 0, 0, the size of the canvas, 100 ms, not blended, not disposed.
            $frames .= self::riffChunk('ANMF', "\0\0\0\0\0\0".self::uint24($width - 1).self::uint24($height - 1).self::uint24(100)."\x02"
                .self::webpBitstream($image));
        }
        $chunks = self::riffChunk('VP8X', chr(0x02)."\0\0\0".self::uint24($width - 1).self::uint24($height - 1))
            .self::riffChunk('ANIM', "\xFF\xFF\xFF\xFF\0\0").$frames;

        return 'RIFF'.pack('V', 4 + strlen($chunks)).'WEBP'.$chunks;
    }

    /** A real PNG with an "acTL" chunk, as an animated PNG has. */
    public static function animatedPng(): string
    {
        $png = self::encode(self::halves(10, 10), 'png');

        return substr($png, 0, 33).self::pngChunk('acTL', pack('NN', 2, 0)).substr($png, 33);
    }

    /**
     * A real CMYK JPEG (four channels, Adobe marker), which libjpeg and gd read. Every 8 × 8 block is flat: its coded data
     * is a DC difference of 0 and an end of block, with Huffman tables of a single code.
     */
    public static function cmykJpeg(int $width = 16, int $height = 16): string
    {
        $components = '';
        $scan = '';
        for ($id = 1; $id <= 4; $id++) {
            // 1 × 1 sampling and quantization table 0; DC and AC Huffman tables 0.
            $components .= chr($id)."\x11\0";
            $scan .= chr($id)."\0";
        }
        // One code of length 1 for the symbol 0.
        $oneCode = "\x01".str_repeat("\0", 15)."\0";
        $bits = str_repeat('00', (int) (ceil($width / 8) * ceil($height / 8) * 4));
        $bits .= str_repeat('1', (8 - strlen($bits) % 8) % 8);
        $data = implode('', array_map(fn (string $byte): string => chr((int) bindec($byte)), str_split($bits, 8)));

        return "\xFF\xD8".self::segment("\xEE", "Adobe\0\x64\0\0\0\0\0").self::segment("\xDB", "\0".str_repeat("\x01", 64))
            .self::segment("\xC0", "\x08".pack('nn', $height, $width)."\x04".$components)
            .self::segment("\xC4", "\x00".$oneCode."\x10".$oneCode).self::segment("\xDA", "\x04".$scan."\0\x3F\0")
            .$data."\xFF\xD9";
    }

    /** Only the header of a PNG of this size; the picture data is never read. */
    public static function pngHeader(int $width, int $height): string
    {
        return "\x89PNG\r\n\x1a\n".self::pngChunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
            .self::pngChunk('IDAT', (string) gzcompress("\0\0\0\0\0")).self::pngChunk('IEND', '');
    }

    /** Only the header of a still, extended WebP of this size. */
    public static function webpHeader(int $width, int $height): string
    {
        $chunks = self::riffChunk('VP8X', "\0\0\0\0".self::uint24($width - 1).self::uint24($height - 1));

        return 'RIFF'.pack('V', 4 + strlen($chunks)).'WEBP'.$chunks;
    }

    /** Only the header of a colour JPEG of this size. */
    public static function jpegHeader(int $width, int $height): string
    {
        return "\xFF\xD8".self::segment("\xC0", "\x08".pack('nn', $height, $width)."\x03\x01\x22\0\x02\x11\0\x03\x11\0")."\xFF\xD9";
    }

    /**
     * A large picture written to a file: "jpeg", "progressive-jpeg", "rgba-png" (half transparent, with an opaque
     * quarter) or "webp".
     */
    public static function pictureFile(string $path, string $format, int $width, int $height): void
    {
        $image = self::canvas($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocatealpha($image, 30, 120, 90, $format === 'rgba-png' ? 64 : 0));
        imagefilledrectangle($image, 0, 0, intdiv($width, 2), intdiv($height, 2), (int) imagecolorallocatealpha($image, 220, 0, 0, 0));
        if ($format === 'progressive-jpeg') {
            imageinterlace($image, true);
        }
        $written = match ($format) {
            'rgba-png' => imagepng($image, $path),
            'webp' => imagewebp($image, $path, 85),
            default => imagejpeg($image, $path, 85),
        };
        if (! $written) {
            throw new RuntimeException("Could not write the test {$format}.");
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

    /** The image data of a still WebP made by gd ("VP8 " or "VP8L", with "ALPH" if any), without its RIFF header. */
    private static function webpBitstream(GdImage $image): string
    {
        $chunks = substr(self::encode($image, 'webp'), 12);

        return substr($chunks, 0, 4) === 'VP8X' ? substr($chunks, 18) : $chunks;
    }

    /** A minimal ICC profile: the 128-byte header and no tags. */
    private static function iccProfile(): string
    {
        $header = pack('N', 132).'none'.pack('N', 0x02100000).'mntrRGB XYZ '.str_repeat("\0", 12).'acsp';

        return str_pad($header, 128, "\0").pack('N', 0);
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
