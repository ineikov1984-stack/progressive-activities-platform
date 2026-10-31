<?php

namespace App\Domain\Catalog\Services;

use GdImage;

/**
 * Every catalog photo is decoded and encoded again (docs/product/teacher-content.md, т. 5.2), so the phone's EXIF
 * (place and GPS, time, device), XMP, IPTC, comments and anything hidden after the picture stay behind. In order: read
 * the orientation, decode, make it smaller, turn the smaller copy, encode in the same format. Only the clean bytes leave
 * this class; the caller never writes the file as it came.
 */
final readonly class ImageSanitizer
{
    /** About 96 MB once decoded (memory_limit=256M in the image); checked in the header, before decoding. */
    public const MAX_PIXELS = 24_000_000;

    /** The long side after cleaning. */
    public const MAX_EDGE = 2560;

    public const QUALITY = 85;

    private const FORMATS = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private ImageCapabilities $capabilities) {}

    /** @throws ImageRejected without gd, exif or one of the formats */
    public function assertToolsAvailable(): void
    {
        if (! $this->capabilities->available()) {
            throw ImageRejected::toolsMissing();
        }
    }

    /**
     * @param  string  $path  a readable file: an upload or a stored photo
     * @param  string  $mime  what the content was found to be; the result keeps it
     * @return array{contents: string, mime: string, width: int, height: int}
     *
     * @throws ImageRejected
     */
    public function sanitize(string $path, string $mime): array
    {
        $this->assertToolsAvailable();
        if (! in_array($mime, self::FORMATS, true)) {
            throw ImageRejected::unreadable();
        }
        $info = @getimagesize($path);
        if ($info === false || $info['mime'] !== $mime || $info[0] < 1 || $info[1] < 1) {
            throw ImageRejected::unreadable();
        }
        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            throw ImageRejected::tooLarge();
        }
        // CMYK (four channels) would come out with wrong colours; an animation would lose all but one frame.
        if (($mime === 'image/jpeg' && ($info['channels'] ?? 3) === 4) || $this->isAnimated($path, $mime)) {
            throw ImageRejected::unreadable();
        }

        $orientation = $this->orientation($path, $mime);
        // Each step returns a new picture; the one before is freed as soon as it is replaced.
        $image = $this->decode($path, $mime);
        $image = $this->fit($image);
        $image = $this->orient($image, $orientation);

        return [
            'contents' => $this->encode($image, $mime),
            'mime' => $mime,
            'width' => imagesx($image),
            'height' => imagesy($image),
        ];
    }

    private function isAnimated(string $path, string $mime): bool
    {
        if ($mime === 'image/webp') {
            // An extended WebP (VP8X) says in its flags whether it is animated (bit 2).
            $head = (string) @file_get_contents($path, false, null, 0, 21);

            return strlen($head) === 21 && substr($head, 12, 4) === 'VP8X' && (ord($head[20]) & 0x02) !== 0;
        }
        if ($mime !== 'image/png') {
            return false;
        }

        // An animated PNG has an "acTL" chunk before the first picture data.
        $file = @fopen($path, 'rb');
        if ($file === false) {
            return true;
        }
        try {
            if (fread($file, 8) !== "\x89PNG\r\n\x1a\n") {
                return true;
            }
            for ($chunks = 0; $chunks < 1000; $chunks++) {
                $header = fread($file, 8);
                if ($header === false || strlen($header) !== 8) {
                    return false;
                }
                $type = substr($header, 4, 4);
                if ($type === 'acTL') {
                    return true;
                }
                if ($type === 'IDAT' || $type === 'IEND') {
                    return false;
                }
                $length = unpack('N', substr($header, 0, 4));
                if ($length === false || fseek($file, (int) $length[1] + 4, SEEK_CUR) !== 0) {
                    return false;
                }
            }

            return false;
        } finally {
            fclose($file);
        }
    }

    /** 1–8 from the phone's EXIF; 1 (as it is) when there is none. */
    private function orientation(string $path, string $mime): int
    {
        if ($mime === 'image/png') {
            return 1;
        }
        $exif = @exif_read_data($path);
        $value = is_array($exif) ? ($exif['Orientation'] ?? 1) : 1;

        return is_int($value) && $value >= 1 && $value <= 8 ? $value : 1;
    }

    private function decode(string $path, string $mime): GdImage
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            default => @imagecreatefromwebp($path),
        };
        if (! $image instanceof GdImage || imagesx($image) * imagesy($image) > self::MAX_PIXELS) {
            throw ImageRejected::unreadable();
        }
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        // Transparency stays (logos in PNG and WebP).
        imagealphablending($image, false);

        return $image;
    }

    /** Down to MAX_EDGE on the long side, before turning, so that only the smaller copy is turned. */
    private function fit(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $long = max($width, $height);
        if ($long <= self::MAX_EDGE) {
            return $image;
        }

        $scale = self::MAX_EDGE / $long;
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));
        $smaller = imagecreatetruecolor($newWidth, $newHeight);
        if ($smaller === false) {
            throw ImageRejected::unreadable();
        }
        imagealphablending($smaller, false);
        if (! imagecopyresampled($smaller, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height)) {
            throw ImageRejected::unreadable();
        }

        return $smaller;
    }

    /** As the phone meant it, so that a photo does not come out lying on its side. */
    private function orient(GdImage $image, int $orientation): GdImage
    {
        // 2, 5 and 7 are mirrored; 4 is upside down and mirrored.
        if (in_array($orientation, [2, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }
        if ($orientation === 4) {
            imageflip($image, IMG_FLIP_VERTICAL);
        }
        // imagerotate() turns counter-clockwise.
        $angle = match ($orientation) {
            3 => 180,
            5, 8 => 90,
            6, 7 => 270,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        $turned = imagerotate($image, $angle, 0);
        if ($turned === false) {
            throw ImageRejected::unreadable();
        }
        imagealphablending($turned, false);

        return $turned;
    }

    private function encode(GdImage $image, string $mime): string
    {
        imagesavealpha($image, true);
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw ImageRejected::unreadable();
        }
        try {
            $written = match ($mime) {
                'image/jpeg' => imagejpeg($image, $stream, self::QUALITY),
                'image/png' => imagepng($image, $stream, 6),
                default => imagewebp($image, $stream, self::QUALITY),
            };
            rewind($stream);
            $contents = stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
        if (! $written || ! is_string($contents) || $contents === '') {
            throw ImageRejected::unreadable();
        }

        return $contents;
    }
}
