<?php

namespace App\Domain\Catalog\Services;

/**
 * Whether this PHP can clean photos (docs/product/teacher-content.md, т. 5.2): gd that reads and writes JPEG, PNG and
 * WebP, and exif for the orientation. Cheap, so it is asked on every upload. Not final: a test replaces it to see the
 * refusal without the tools.
 */
class ImageCapabilities
{
    public function available(): bool
    {
        if (! function_exists('gd_info') || ! function_exists('exif_read_data')) {
            return false;
        }
        $gd = gd_info();

        return ($gd['JPEG Support'] ?? false) === true
            && ($gd['PNG Support'] ?? false) === true
            && ($gd['WebP Support'] ?? false) === true;
    }
}
