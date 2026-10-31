<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Services\ImageRejected;
use App\Domain\Catalog\Services\ImageSanitizer;
use App\Domain\Organizations\Tenancy\TenantContext;

/**
 * `catalog:sanitize-media` (docs/product/teacher-content.md, т. 5.2): cleans the photos stored before the sanitizer,
 * organization by organization, each under its own tenant context. A photo marked `sanitized` is skipped, so a second
 * run changes nothing. A photo that cannot be read (CMYK, animation, damage) is left as it is and listed by its
 * identifiers only. The files are the only thing it touches: no database rows, no names, no paths in the output.
 */
final readonly class SanitizeStoredMedia
{
    public function __construct(
        private TenantContext $context,
        private CatalogMediaStore $media,
        private ImageSanitizer $sanitizer,
    ) {}

    /**
     * @return array{sanitized: int, skipped: int, failed: list<array{organization_id: string, media_id: string}>}
     *
     * @throws ImageRejected without gd, exif or one of the formats: nothing is touched
     */
    public function run(): array
    {
        $this->sanitizer->assertToolsAvailable();
        $result = ['sanitized' => 0, 'skipped' => 0, 'failed' => []];

        foreach ($this->media->organizations() as $organizationId) {
            $this->context->select($organizationId);
            try {
                foreach ($this->media->storedIds() as $id) {
                    try {
                        if ($this->media->sanitizeStored($id)) {
                            $result['sanitized']++;
                        } else {
                            $result['skipped']++;
                        }
                    } catch (ImageRejected $rejected) {
                        if ($rejected->toolsAreMissing()) {
                            throw $rejected;
                        }
                        $result['failed'][] = ['organization_id' => $organizationId, 'media_id' => $id];
                    }
                }
            } finally {
                // The next organization never inherits this one.
                $this->context->clear();
            }
        }

        return $result;
    }
}
