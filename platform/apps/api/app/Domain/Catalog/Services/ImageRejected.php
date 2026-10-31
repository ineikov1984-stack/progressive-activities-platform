<?php

namespace App\Domain\Catalog\Services;

use RuntimeException;

/** Why a photo was not taken (docs/product/teacher-content.md, т. 5.1–5.2). The message is the one people see. */
final class ImageRejected extends RuntimeException
{
    public const TOO_LARGE = 'too_large';

    public const UNREADABLE = 'unreadable';

    public const TOOLS_MISSING = 'tools_missing';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    /** Over 24 megapixels for a JPEG, 16 for a PNG or a WebP, seen in the header before decoding: 422. */
    public static function tooLarge(): self
    {
        return new self(self::TOO_LARGE, 'Снимката е твърде голяма.');
    }

    /** CMYK, animated, damaged or not what it says it is: 422. */
    public static function unreadable(): self
    {
        return new self(self::UNREADABLE, 'Снимката не може да се обработи. Запазете я като обикновена JPG снимка и опитайте отново.');
    }

    /** No gd, exif or support for a format: 503 — the photo is never stored as it came. */
    public static function toolsMissing(): self
    {
        return new self(self::TOOLS_MISSING, 'Снимките временно не се приемат. Опитайте по-късно.');
    }

    public function toolsAreMissing(): bool
    {
        return $this->reason === self::TOOLS_MISSING;
    }
}
